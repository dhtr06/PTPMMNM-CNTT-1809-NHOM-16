<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/engine.php';

if (!(defined('LINKSCOPE_WORKER') && LINKSCOPE_WORKER === true)) {
    require_admin();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
verify_csrf();

$existingSite = null;
$requestedSiteId = max(0, (int)($_POST['site_id'] ?? 0));
if ($requestedSiteId > 0) {
    $stSite = $pdo->prepare('SELECT * FROM sites WHERE id=?');
    $stSite->execute([$requestedSiteId]);
    $existingSite = $stSite->fetch() ?: null;
    if (!$existingSite && defined('LINKSCOPE_WORKER') && LINKSCOPE_WORKER === true) {
        echo "Site schedule not found\n";
        exit(1);
    }
}

$name = trim((string)($existingSite['name'] ?? ($_POST['name'] ?? 'Website cần kiểm tra')));
$start = trim((string)($existingSite['start_url'] ?? ($_POST['start_url'] ?? '')));
$rawDomains = trim((string)($existingSite['allowed_domains'] ?? ($_POST['allowed_domains'] ?? '')));
$maxPages = max(1, min(2000, (int)($existingSite['max_pages'] ?? ($_POST['max_pages'] ?? 50))));
$maxDepth = max(0, min(15, (int)($existingSite['max_depth'] ?? ($_POST['max_depth'] ?? 3))));
$timeout = max(1.0, min(30.0, (float)($existingSite['request_timeout'] ?? ($_POST['timeout'] ?? 4.0))));
$duration = max(3.0, min(600.0, (float)($existingSite['max_duration'] ?? ($_POST['duration'] ?? 60.0))));
$concurrency = max(1, min(32, (int)($existingSite['concurrency'] ?? ($_POST['concurrency'] ?? 15))));

$allowSubdomains = $existingSite
    ? !empty($existingSite['allow_subdomains'])
    : !empty($_POST['allow_subdomains']);
$checkExternalAssets = $existingSite
    ? !empty($existingSite['check_external_assets'])
    : !empty($_POST['check_external_assets']);
$allowLocal = $existingSite
    ? !empty($existingSite['allow_local'])
    : !empty($_POST['allow_local']);

// Nếu URL bắt đầu là localhost/127.0.0.1 của chính testbed trên máy, tự động nhận diện nếu người dùng tích chọn hoặc dùng preset testbed
$parsedStart = parse_url($start);
$startHost = strtolower((string)($parsedStart['host'] ?? ''));
if (in_array($startHost, ['localhost', '127.0.0.1', '::1'], true) && !empty($_POST['is_testbed'])) {
    $allowLocal = true;
}

try {
    $result = ls_execute_scan_run($pdo, [
        'site_id'               => $existingSite ? (int)$existingSite['id'] : 0,
        'name'                  => $name !== '' ? $name : 'Website cần kiểm tra',
        'start_url'             => $start,
        'allowed_domains'       => $rawDomains,
        'max_pages'             => $maxPages,
        'max_depth'             => $maxDepth,
        'request_timeout'       => $timeout,
        'max_duration'          => $duration,
        'concurrency'           => $concurrency,
        'allow_subdomains'      => $allowSubdomains,
        'check_external_assets' => $checkExternalAssets,
        'allow_local'           => $allowLocal,
    ]);

    if (defined('LINKSCOPE_WORKER') && LINKSCOPE_WORKER === true) {
        echo "Completed scan #{$result['run_id']}: {$result['pages_crawled']} pages, {$result['links_found']} links, {$result['errors_count']} errors ({$result['elapsed_sec']}s)\n";
        exit(0);
    }

    flash(
        "Quét hoàn tất (#{$result['run_id']}) trong {$result['elapsed_sec']}s: "
        . "{$result['pages_crawled']} trang, {$result['links_found']} liên kết, "
        . "{$result['errors_count']} lỗi, {$result['unchecked_count']} mục chưa xác định/ngoài phạm vi. "
        . "Lý do dừng: {$result['stop_reason']}."
    );
    redirect('report.php?run_id=' . (int)$result['run_id']);
} catch (InvalidArgumentException $ie) {
    if (defined('LINKSCOPE_WORKER') && LINKSCOPE_WORKER === true) {
        echo "Invalid scan config: " . $ie->getMessage() . "\n";
        exit(1);
    }
    flash($ie->getMessage(), 'danger');
    redirect('index.php');
} catch (Throwable $e) {
    if (defined('LINKSCOPE_WORKER') && LINKSCOPE_WORKER === true) {
        echo "Failed scan: " . $e->getMessage() . "\n";
        exit(1);
    }
    flash('Phiên quét gặp lỗi: ' . $e->getMessage(), 'danger');
    redirect('index.php');
}
