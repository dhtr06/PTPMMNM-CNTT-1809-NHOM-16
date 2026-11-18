<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/engine.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('history.php');
}
verify_csrf();

$runId = max(0, (int)($_POST['run_id'] ?? 0));
$retryMode = (string)($_POST['retry_mode'] ?? 'inplace');
if (!in_array($retryMode, ['inplace', 'new_run'], true)) {
    $retryMode = 'inplace';
}

$ids = [];
if (!empty($_POST['single_finding_id'])) {
    $ids[] = (int)$_POST['single_finding_id'];
} elseif (!empty($_POST['finding_id'])) {
    $ids[] = (int)$_POST['finding_id'];
} elseif (!empty($_POST['finding_ids']) && is_array($_POST['finding_ids'])) {
    foreach ($_POST['finding_ids'] as $v) {
        $iv = (int)$v;
        if ($iv > 0) {
            $ids[] = $iv;
        }
    }
}
$ids = array_values(array_unique($ids));

if (!$runId || !$ids) {
    flash('Hãy tích chọn ít nhất một mục cần kiểm tra lại.', 'warning');
    redirect('report.php?run_id=' . $runId);
}

$stRun = $pdo->prepare('SELECT r.*, s.name, s.start_url, s.allowed_domains, s.request_timeout, s.concurrency, s.allow_subdomains, s.check_external_assets, s.allow_local FROM scan_runs r JOIN sites s ON s.id=r.site_id WHERE r.id=?');
$stRun->execute([$runId]);
$runInfo = $stRun->fetch();
if (!$runInfo) {
    flash('Không tìm thấy phiên quét gốc.', 'danger');
    redirect('history.php');
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stFindings = $pdo->prepare("SELECT * FROM findings WHERE run_id=? AND id IN ($placeholders)");
$stFindings->execute(array_merge([$runId], $ids));
$selectedFindings = $stFindings->fetchAll();

if (!$selectedFindings) {
    flash('Không tìm thấy các mục cần kiểm tra lại.', 'warning');
    redirect('report.php?run_id=' . $runId);
}

$allowSubdomains = !isset($runInfo['allow_subdomains']) || !empty($runInfo['allow_subdomains']);
$checkExternalAssets = !empty($runInfo['check_external_assets']);
$allowLocal = !empty($runInfo['allow_local']);
$timeout = max(1.0, min(30.0, (float)($runInfo['request_timeout'] ?? 4.0)));
$concurrency = max(1, min(32, (int)($runInfo['concurrency'] ?? 15)));
$domains = ls_parse_allowed_domains((string)$runInfo['start_url'], (string)$runInfo['allowed_domains'], $allowSubdomains);

// Chuẩn bị batch kiểm tra bằng Multi-cURL
$batchReq = [];
foreach ($selectedFindings as $f) {
    $baseUrl = (string)$f['base_target_url'];
    $needFull = ((string)$f['fragment'] !== '' && $f['resource_type'] === 'link');
    $isAsset = in_array($f['resource_type'], ['image', 'resource'], true);
    if (!isset($batchReq[$baseUrl])) {
        $batchReq[$baseUrl] = [
            'url'            => $baseUrl,
            'need_full_body' => $needFull,
            'is_asset'       => $isAsset,
            'resource_type'  => (string)$f['resource_type'],
        ];
    } elseif ($needFull) {
        $batchReq[$baseUrl]['need_full_body'] = true;
    }
}

$batchResults = ls_fetch_multi_batch(
    $batchReq,
    $domains,
    $timeout,
    $concurrency,
    $allowLocal,
    $allowSubdomains,
    $checkExternalAssets
);

$now = date('Y-m-d H:i:s');

try {
    $pdo->beginTransaction();

    if ($retryMode === 'new_run') {
        $stNewRun = $pdo->prepare("INSERT INTO scan_runs(site_id,started_at,status) VALUES(?,?,'running')");
        $stNewRun->execute([(int)$runInfo['site_id'], $now]);
        $targetRunId = (int)$pdo->lastInsertId();

        // Tạo 1 bản ghi page đại diện để gắn các links nguồn được giữ lại
        $stDummyPage = $pdo->prepare('INSERT INTO pages(run_id,url,depth,http_status,title,anchors_list,crawled_at) VALUES(?,?,?,?,?,?,?)');
        $stDummyPage->execute([$targetRunId, $runInfo['start_url'], 0, 200, 'Kiểm tra lại từ phiên #' . $runId, '[]', $now]);
        $dummyPageId = (int)$pdo->lastInsertId();

        $stInsertFinding = $pdo->prepare('INSERT INTO findings(run_id,normalized_url,base_target_url,fragment,resource_type,status_category,http_status,redirect_chain,error_detail,checked_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $stCopyLinks = $pdo->prepare('SELECT * FROM links WHERE run_id=? AND (normalized_url=? OR (base_target_url=? AND COALESCE(fragment,"")=?))');
        $stInsertLink = $pdo->prepare('INSERT INTO links(run_id,source_page_id,source_url,raw_href,normalized_url,base_target_url,fragment,resource_type,line_number,tag_snippet,anchor_text) VALUES(?,?,?,?,?,?,?,?,?,?,?)');

        $errCount = 0;
        $unchkCount = 0;
        $linksCopied = 0;

        foreach ($selectedFindings as $f) {
            $baseUrl = (string)$f['base_target_url'];
            $frag = (string)($f['fragment'] ?? '');
            $resType = (string)$f['resource_type'];
            $res = $batchResults[$baseUrl] ?? null;

            $sc = $res ? (int)$res['status'] : 0;
            $cat = $res ? (string)$res['category'] : 'unknown';
            $detail = $res ? (string)$res['error'] : 'Không nhận được phản hồi';
            $chain = $res ? (string)$res['chain'] : '';

            if ($frag !== '' && $resType === 'link' && $sc >= 200 && $sc < 300 && in_array($cat, ['ok', 'redirect'], true)) {
                $anchors = $res['anchors'] ?? [];
                if (!in_array($frag, $anchors, true) && !in_array(rawurldecode($frag), $anchors, true)) {
                    $cat = 'missing_anchor';
                    $detail = 'Không tìm thấy mục neo (anchor) #' . $frag . ' trên trang đích';
                } else {
                    $detail = 'Phản hồi thành công · Mục neo #' . $frag . ' hợp lệ';
                }
            }

            $stInsertFinding->execute([
                $targetRunId,
                $f['normalized_url'],
                $baseUrl,
                $frag,
                $resType,
                $cat,
                $sc ?: null,
                substr($chain, 0, 1480),
                substr($detail, 0, 990),
                $now,
            ]);

            if (in_array($cat, ['http_error', 'connection_error', 'missing_anchor'], true)) {
                $errCount++;
            } elseif (in_array($cat, ['unknown', 'unchecked'], true)) {
                $unchkCount++;
            }

            $stCopyLinks->execute([$runId, $f['normalized_url'], $baseUrl, $frag]);
            foreach ($stCopyLinks->fetchAll() as $lnk) {
                $linksCopied++;
                $stInsertLink->execute([
                    $targetRunId,
                    $dummyPageId,
                    $lnk['source_url'],
                    $lnk['raw_href'],
                    $lnk['normalized_url'],
                    $lnk['base_target_url'],
                    $lnk['fragment'],
                    $lnk['resource_type'],
                    $lnk['line_number'],
                    $lnk['tag_snippet'],
                    $lnk['anchor_text'],
                ]);
            }
        }

        $stUpRun = $pdo->prepare("UPDATE scan_runs SET finished_at=?,status='completed',stop_reason=?,total_pages_crawled=1,total_links_found=?,total_errors=?,total_unchecked=? WHERE id=?");
        $stUpRun->execute([$now, 'Kiểm tra lại ' . count($selectedFindings) . ' mục từ phiên #' . $runId, $linksCopied, $errCount, $unchkCount, $targetRunId]);
        $pdo->commit();

        flash('Đã kiểm tra lại ' . count($selectedFindings) . ' mục và lưu thành phiên mới #' . $targetRunId . '.');
        redirect('report.php?run_id=' . $targetRunId);
    }

    // Chế độ mặc định (inplace): Cập nhật trực tiếp vào phiên hiện tại
    $stUpdateFinding = $pdo->prepare('UPDATE findings SET status_category=?, http_status=?, redirect_chain=?, error_detail=?, checked_at=? WHERE id=? AND run_id=?');
    foreach ($selectedFindings as $f) {
        $baseUrl = (string)$f['base_target_url'];
        $frag = (string)($f['fragment'] ?? '');
        $resType = (string)$f['resource_type'];
        $res = $batchResults[$baseUrl] ?? null;

        $sc = $res ? (int)$res['status'] : 0;
        $cat = $res ? (string)$res['category'] : 'unknown';
        $detail = $res ? (string)$res['error'] : 'Không nhận được phản hồi';
        $chain = $res ? (string)$res['chain'] : '';

        if ($frag !== '' && $resType === 'link' && $sc >= 200 && $sc < 300 && in_array($cat, ['ok', 'redirect'], true)) {
            $anchors = $res['anchors'] ?? [];
            if (!in_array($frag, $anchors, true) && !in_array(rawurldecode($frag), $anchors, true)) {
                $cat = 'missing_anchor';
                $detail = 'Không tìm thấy mục neo (anchor) #' . $frag . ' trên trang đích';
            } else {
                $detail = 'Phản hồi thành công · Mục neo #' . $frag . ' hợp lệ';
            }
        }

        $stUpdateFinding->execute([
            $cat,
            $sc ?: null,
            substr($chain, 0, 1480),
            substr($detail . ' (Đã kiểm tra lại lúc ' . date('H:i:s') . ')', 0, 990),
            $now,
            (int)$f['id'],
            $runId,
        ]);
    }

    // Tính lại tổng số lỗi và mục chưa xác định của phiên $runId
    $stRecountErr = $pdo->prepare("SELECT COUNT(*) FROM findings WHERE run_id=? AND status_category IN ('http_error','connection_error','missing_anchor')");
    $stRecountErr->execute([$runId]);
    $newErrTotal = (int)$stRecountErr->fetchColumn();

    $stRecountUnchk = $pdo->prepare("SELECT COUNT(*) FROM findings WHERE run_id=? AND status_category IN ('unknown','unchecked')");
    $stRecountUnchk->execute([$runId]);
    $newUnchkTotal = (int)$stRecountUnchk->fetchColumn();

    $stUpRun = $pdo->prepare('UPDATE scan_runs SET total_errors=?, total_unchecked=? WHERE id=?');
    $stUpRun->execute([$newErrTotal, $newUnchkTotal, $runId]);

    $pdo->commit();
    flash('Đã kiểm tra lại ' . count($selectedFindings) . ' mục đã chọn và cập nhật trạng thái mới nhất.');
    redirect('report.php?run_id=' . $runId);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('LinkScope retry error: ' . $e->getMessage());
    flash('Không thể kiểm tra lại các mục đã chọn: ' . $e->getMessage(), 'danger');
    redirect('report.php?run_id=' . $runId);
}
