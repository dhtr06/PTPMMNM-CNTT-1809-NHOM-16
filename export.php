<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_login();

$compareA = max(0, (int)($_GET['compare_a'] ?? 0));
$compareB = max(0, (int)($_GET['compare_b'] ?? 0));

// Xuất CSV Báo cáo So sánh 2 phiên (Chức năng 5 & Tình huống 2)
if ($compareA > 0 && $compareB > 0 && $compareA !== $compareB) {
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

    $rowsOut = [];
    $errorCats = ['http_error', 'connection_error', 'missing_anchor'];

    foreach ($old as $url => $fOld) {
        if (in_array($fOld['status_category'], $errorCats, true)) {
            if (isset($new[$url]) && in_array($new[$url]['status_category'], ['ok', 'redirect'], true)) {
                $rowsOut[] = [
                    'Lỗi đã hết (Đã khắc phục)',
                    $url,
                    $fOld['resource_type'],
                    $fOld['status_category'] . ' -> ' . $new[$url]['status_category'],
                    $new[$url]['http_status'] ?? '',
                    $new[$url]['error_detail'],
                    $new[$url]['source_pages'] ?: $fOld['source_pages'],
                    $new[$url]['source_html'] ?: $fOld['source_html'],
                ];
            } elseif (!isset($new[$url])) {
                foreach ($sourcesInA[$url] ?? [] as $su) {
                    if (isset($crawledPagesB[$su])) {
                        $rowsOut[] = [
                            'Lỗi đã hết (Đã sửa liên kết trên trang nguồn)',
                            $url,
                            $fOld['resource_type'],
                            $fOld['status_category'] . ' -> ok',
                            200,
                            'Liên kết hỏng đã được sửa hoặc gỡ bỏ khỏi trang nguồn',
                            $fOld['source_pages'],
                            $fOld['source_html'],
                        ];
                        break;
                    }
                }
            }
        }
    }

    foreach ($new as $url => $fNew) {
        if (in_array($fNew['status_category'], $errorCats, true)) {
            $group = (!isset($old[$url]) || !in_array($old[$url]['status_category'], $errorCats, true))
                ? 'Lỗi mới phát sinh'
                : 'Lỗi còn lại (Chưa sửa)';
            $rowsOut[] = [
                $group,
                $url,
                $fNew['resource_type'],
                $fNew['status_category'],
                $fNew['http_status'] ?? '',
                $fNew['error_detail'],
                $fNew['source_pages'],
                $fNew['source_html'],
            ];
        } elseif (in_array($fNew['status_category'], ['unknown', 'unchecked'], true)) {
            $rowsOut[] = [
                'Chưa kiểm tra / Chưa xác định',
                $url,
                $fNew['resource_type'],
                $fNew['status_category'],
                $fNew['http_status'] ?? '',
                $fNew['error_detail'],
                $fNew['source_pages'],
                $fNew['source_html'],
            ];
        }
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="linkscope_compare_' . $compareA . '_vs_' . $compareB . '_' . date('Ymd_His') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Nhóm đối chiếu', 'URL đích', 'Loại tài nguyên', 'Trạng thái', 'Mã HTTP', 'Chi tiết', 'Trang nguồn & Số dòng', 'Thẻ HTML nguồn']);
    foreach ($rowsOut as $r) {
        foreach ($r as $k => $v) {
            if (is_string($v) && preg_match('/^[=+@\-\t\r]/', $v)) {
                $r[$k] = "'" . $v;
            }
        }
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

$id = max(0, (int)($_GET['run_id'] ?? 0));
if (!$id) {
    http_response_code(400);
    exit('Thiếu run_id hợp lệ.');
}
$check = $pdo->prepare('SELECT id FROM scan_runs WHERE id=?');
$check->execute([$id]);
if (!$check->fetch()) {
    http_response_code(404);
    exit('Không tìm thấy phiên quét.');
}

$stmt = $pdo->prepare("SELECT f.normalized_url, f.base_target_url, f.fragment, f.resource_type, f.status_category, f.http_status, f.redirect_chain, f.error_detail, f.checked_at, COALESCE(GROUP_CONCAT(DISTINCT CONCAT(l.source_url, ' (dòng ', l.line_number, ')') SEPARATOR ' | '), '') AS source_pages, COALESCE(GROUP_CONCAT(DISTINCT l.tag_snippet SEPARATOR ' | '), '') AS source_html FROM findings f LEFT JOIN links l ON l.run_id=f.run_id AND (l.normalized_url=f.normalized_url OR (l.base_target_url=f.base_target_url AND COALESCE(l.fragment,'')=COALESCE(f.fragment,''))) WHERE f.run_id=? GROUP BY f.id ORDER BY f.id");
$stmt->execute([$id]);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="linkscope_report_' . $id . '_' . date('Ymd_His') . '.csv"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['URL chuẩn hóa', 'URL đích (Base)', 'Mục neo (#fragment)', 'Loại', 'Phân loại trạng thái', 'Mã HTTP', 'Chuỗi chuyển hướng', 'Chi tiết quan sát', 'Thời điểm kiểm tra', 'Tất cả trang nguồn & Dòng HTML', 'Đoạn thẻ HTML nguồn']);

while ($row = $stmt->fetch()) {
    foreach ($row as $key => $value) {
        if (is_string($value) && preg_match('/^[=+@\-\t\r]/', $value)) {
            $row[$key] = "'" . $value;
        }
    }
    fputcsv($out, $row);
}
fclose($out);
exit;
