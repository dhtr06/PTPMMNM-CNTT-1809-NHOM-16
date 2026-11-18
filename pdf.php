<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_login();

$compareA = max(0, (int)($_GET['compare_a'] ?? 0));
$compareB = max(0, (int)($_GET['compare_b'] ?? 0));

if ($compareA > 0 && $compareB > 0 && $compareA !== $compareB) {
    $stRun = $pdo->prepare('SELECT r.*, s.name, s.start_url, s.allowed_domains FROM scan_runs r JOIN sites s ON s.id=r.site_id WHERE r.id=?');
    $stRun->execute([$compareA]);
    $runA = $stRun->fetch();
    $stRun->execute([$compareB]);
    $runB = $stRun->fetch();
    if (!$runA || !$runB) {
        http_response_code(404);
        exit('Không tìm thấy hai phiên cần so sánh.');
    }

    $sqlFindings = "SELECT f.*, COALESCE(GROUP_CONCAT(DISTINCT CONCAT(l.source_url, ' (dòng ', l.line_number, ')') SEPARATOR ' | '), '') AS source_pages, COALESCE(GROUP_CONCAT(DISTINCT l.tag_snippet SEPARATOR ' | '), '') AS source_html FROM findings f LEFT JOIN links l ON l.run_id=f.run_id AND (l.normalized_url=f.normalized_url OR (l.base_target_url=f.base_target_url AND COALESCE(l.fragment,'')=COALESCE(f.fragment,''))) WHERE f.run_id=? GROUP BY f.id";
    $q = $pdo->prepare($sqlFindings);
    $old = [];
    $new = [];
    $q->execute([$compareA]);
    foreach ($q->fetchAll() as $f) {
        $old[$f['normalized_url']] = $f;
    }
    $q->execute([$compareB]);
    foreach ($q->fetchAll() as $f) {
        $new[$f['normalized_url']] = $f;
    }

    $stPagesB = $pdo->prepare('SELECT url FROM pages WHERE run_id=? AND http_status >= 200 AND http_status < 400');
    $stPagesB->execute([$compareB]);
    $crawledPagesB = array_flip($stPagesB->fetchAll(PDO::FETCH_COLUMN));

    $stLinksA = $pdo->prepare('SELECT normalized_url, source_url FROM links WHERE run_id=?');
    $stLinksA->execute([$compareA]);
    $sourcesInA = [];
    foreach ($stLinksA->fetchAll() as $la) {
        $sourcesInA[$la['normalized_url']][] = $la['source_url'];
    }

    $rows = [];
    $errorCats = ['http_error', 'connection_error', 'missing_anchor'];
    foreach ($old as $url => $fOld) {
        if (in_array($fOld['status_category'], $errorCats, true)) {
            if (isset($new[$url]) && in_array($new[$url]['status_category'], ['ok', 'redirect'], true)) {
                $r = $new[$url];
                $r['diff_group'] = 'Đã hết lỗi';
                $r['source_pages'] = $r['source_pages'] ?: $fOld['source_pages'];
                $r['source_html'] = $r['source_html'] ?: $fOld['source_html'];
                $rows[] = $r;
            } elseif (!isset($new[$url])) {
                foreach ($sourcesInA[$url] ?? [] as $su) {
                    if (isset($crawledPagesB[$su])) {
                        $r = $fOld;
                        $r['diff_group'] = 'Đã hết lỗi (Đã sửa nguồn)';
                        $r['status_category'] = 'ok';
                        $r['error_detail'] = 'Liên kết hỏng đã được sửa hoặc xóa trên trang nguồn';
                        $rows[] = $r;
                        break;
                    }
                }
            }
        }
    }
    foreach ($new as $url => $fNew) {
        if (in_array($fNew['status_category'], $errorCats, true)) {
            $fNew['diff_group'] = (!isset($old[$url]) || !in_array($old[$url]['status_category'], $errorCats, true)) ? 'Lỗi mới' : 'Lỗi còn lại';
            $rows[] = $fNew;
        } elseif (in_array($fNew['status_category'], ['unknown', 'unchecked'], true)) {
            $fNew['diff_group'] = 'Chưa kiểm tra / Chưa xác định';
            $rows[] = $fNew;
        }
    }
    ?><!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Báo cáo So sánh #<?=e($compareA)?> vs #<?=e($compareB)?></title>
    <style>*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#1c2434;margin:0;background:#eef1f7;font-size:13px}.page{max-width:1180px;margin:24px auto;background:#fff;padding:34px;border-radius:14px;box-shadow:0 10px 35px #17203312}.top{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;border-bottom:2px solid #5b55ef;padding-bottom:20px}.brand{font-size:24px;font-weight:800;color:#5148e5}.muted{color:#6b7486}.actions{display:flex;gap:10px;margin:20px 0}.btn{border:0;border-radius:8px;padding:10px 14px;background:#5b55ef;color:#fff;font-weight:bold;cursor:pointer;text-decoration:none}.btn.secondary{background:#eef0ff;color:#4338ca}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border-bottom:1px solid #e8ebf2;text-align:left;vertical-align:top;padding:9px 8px;overflow-wrap:anywhere}th{font-size:10px;text-transform:uppercase;background:#f7f8fc;color:#667085}td{font-size:11px}@media print{body{background:#fff;font-size:10px}.page{margin:0;max-width:none;padding:0;box-shadow:none}.actions{display:none}@page{size:A4 landscape;margin:10mm}}</style>
    </head><body><main class="page"><header class="top"><div><div class="brand">↗ LinkScope Pro</div><h1 style="font-size:22px;margin:10px 0 5px">Báo cáo So sánh Phiên #<?=e($compareA)?> và Phiên #<?=e($compareB)?></h1><div class="muted"><?=e($runB['name'])?> · <?=e($runB['start_url'])?></div></div><div><div><strong>Phiên cũ #<?=e($compareA)?>:</strong> <?=e($runA['started_at'])?></div><div><strong>Phiên mới #<?=e($compareB)?>:</strong> <?=e($runB['started_at'])?></div><div><strong>Phạm vi:</strong> <?=e($runB['allowed_domains'])?></div></div></header>
    <div class="actions"><button class="btn" onclick="window.print()">🖨 In / Lưu thành PDF</button><a class="btn secondary" href="compare.php?a=<?=(int)$compareA?>&b=<?=(int)$compareB?>">Quay lại trang So sánh</a></div>
    <table><thead><tr><th style="width:14%">Nhóm</th><th style="width:22%">URL đích</th><th style="width:10%">Trạng thái</th><th style="width:22%">Trang nguồn & Dòng</th><th style="width:16%">Thẻ HTML nguồn</th><th style="width:16%">Chi tiết</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="6">Không có sự khác biệt lỗi giữa hai phiên.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?><tr><td><strong><?=e($r['diff_group'])?></strong></td><td><?=e($r['normalized_url'])?></td><td><?=e($r['status_category'])?></td><td><?=e($r['source_pages'] ?: '—')?></td><td><code><?=e($r['source_html'] ?: '—')?></code></td><td><?=e($r['error_detail'])?></td></tr><?php endforeach; ?>
    </tbody></table></main></body></html><?php
    exit;
}

$id = max(0, (int)($_GET['run_id'] ?? 0));
$st = $pdo->prepare('SELECT r.*, s.name, s.start_url, s.allowed_domains FROM scan_runs r JOIN sites s ON s.id=r.site_id WHERE r.id=?');
$st->execute([$id]);
$run = $st->fetch();
if (!$run) {
    http_response_code(404);
    exit('Không tìm thấy phiên quét.');
}
$st = $pdo->prepare("SELECT f.*, COALESCE(GROUP_CONCAT(DISTINCT CONCAT(l.source_url, ' (dòng ', l.line_number, ')') SEPARATOR ' | '), '') source_pages, COALESCE(GROUP_CONCAT(DISTINCT l.tag_snippet SEPARATOR ' | '), '') source_html FROM findings f LEFT JOIN links l ON l.run_id=f.run_id AND (l.normalized_url=f.normalized_url OR (l.base_target_url=f.base_target_url AND COALESCE(l.fragment,'')=COALESCE(f.fragment,''))) WHERE f.run_id=? GROUP BY f.id ORDER BY CASE f.status_category WHEN 'http_error' THEN 1 WHEN 'missing_anchor' THEN 2 WHEN 'connection_error' THEN 3 WHEN 'unknown' THEN 4 WHEN 'unchecked' THEN 5 ELSE 6 END, f.id");
$st->execute([$id]);
$rows = $st->fetchAll();
$counts = [];
foreach ($rows as $r) {
    $counts[$r['status_category']] = ($counts[$r['status_category']] ?? 0) + 1;
}
?><!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Báo cáo LinkScope #<?=e($id)?></title><style>
*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#1c2434;margin:0;background:#eef1f7;font-size:13px}.page{max-width:1180px;margin:24px auto;background:#fff;padding:34px;border-radius:14px;box-shadow:0 10px 35px #17203312}.top{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;border-bottom:2px solid #5b55ef;padding-bottom:20px}.brand{font-size:24px;font-weight:800;letter-spacing:-1px;color:#5148e5}.muted{color:#6b7486}.meta{line-height:1.8}.actions{display:flex;gap:10px;margin:20px 0}.btn{border:0;border-radius:8px;padding:10px 14px;background:#5b55ef;color:#fff;font-weight:bold;cursor:pointer;text-decoration:none}.btn.secondary{background:#eef0ff;color:#4338ca}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:22px 0}.stat{border:1px solid #e4e8f1;border-radius:10px;padding:14px}.stat strong{font-size:24px;display:block;margin-top:7px}.chips{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 22px}.chip{background:#f0f2f8;border-radius:999px;padding:6px 9px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border-bottom:1px solid #e8ebf2;text-align:left;vertical-align:top;padding:9px 8px;overflow-wrap:anywhere}th{font-size:10px;letter-spacing:.06em;text-transform:uppercase;background:#f7f8fc;color:#667085}td{font-size:11px}.url{color:#5148e5}.error{color:#b4233c;font-weight:bold}.ok{color:#087443}.source{font-size:10px;color:#5f687a}h2{margin:22px 0 10px;font-size:17px}@media(max-width:700px){.page{margin:0;padding:16px}.stats{grid-template-columns:repeat(2,1fr)}.top{display:block}}@media print{body{background:#fff;font-size:10px}.page{margin:0;max-width:none;padding:0;box-shadow:none;border-radius:0}.actions{display:none}.stats{gap:6px}.stat{padding:8px}.stat strong{font-size:18px}thead{display:table-header-group}tr{break-inside:avoid}th,td{padding:5px;font-size:9px}@page{size:A4 landscape;margin:10mm}}
</style></head><body><main class="page"><header class="top"><div><div class="brand">↗ LinkScope Pro</div><h1 style="font-size:22px;margin:10px 0 5px">Báo cáo kiểm tra liên kết & tài nguyên</h1><div class="muted">Phiên #<?=e($run['id'])?> · <?=e($run['name'])?></div></div><div class="meta"><div><strong>URL bắt đầu:</strong> <?=e($run['start_url'])?></div><div><strong>Thời điểm:</strong> <?=e($run['started_at'])?></div><div><strong>Lý do dừng:</strong> <?=e($run['stop_reason'] ?? $run['status'])?></div><div><strong>Phạm vi:</strong> <?=e($run['allowed_domains'])?></div></div></header>
<div class="actions"><button class="btn" onclick="window.print()">🖨 In / Lưu thành PDF</button><a class="btn secondary" href="report.php?run_id=<?=(int)$id?>">Quay lại báo cáo</a></div>
<section class="stats"><div class="stat">Trang đã quét<strong><?=number_format((int)$run['total_pages_crawled'])?></strong></div><div class="stat">Liên kết tìm thấy<strong><?=number_format((int)$run['total_links_found'])?></strong></div><div class="stat">Lỗi ghi nhận<strong><?=number_format((int)$run['total_errors'])?></strong></div><div class="stat">Chưa xác định / bỏ qua<strong><?=number_format((int)$run['total_unchecked'])?></strong></div></section>
<div class="chips"><?php foreach ($counts as $status => $count): ?><span class="chip"><?=e(status_label_vi((string)$status))?> (<?=e($status)?>): <strong><?=$count?></strong></span><?php endforeach; ?></div>
<h2>Chi tiết phát hiện kèm vị trí trong trang nguồn (<?=count($rows)?>)</h2><div class="table-wrap"><table><thead><tr><th style="width:20%">URL đích</th><th style="width:8%">Loại</th><th style="width:6%">HTTP</th><th style="width:11%">Trạng thái</th><th style="width:20%">Trang nguồn & Dòng</th><th style="width:17%">Thẻ HTML nguồn</th><th style="width:18%">Chi tiết</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="7">Không có phát hiện nào được lưu cho phiên này.</td></tr><?php endif; foreach ($rows as $r): ?><tr><td class="url"><?=e($r['normalized_url'])?></td><td><?=e($r['resource_type'])?></td><td><?=e($r['http_status'] ?? '—')?></td><td class="<?=in_array($r['status_category'], ['http_error', 'connection_error', 'missing_anchor'], true) ? 'error' : ($r['status_category'] === 'ok' ? 'ok' : '')?>"><?=e($r['status_category'])?></td><td class="source"><?=e($r['source_pages'] ?: '—')?></td><td class="source"><?=e($r['source_html'] ?: '—')?></td><td><?=e($r['error_detail'])?><?php if ($r['redirect_chain']): ?><div class="source">Redirect: <?=e($r['redirect_chain'])?></div><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><p class="muted" style="margin-top:20px">Tạo bởi LinkScope Pro v3.0 · Đề tài 13 · Báo cáo kèm vị trí liên kết trong trang nguồn.</p></main></body></html>
