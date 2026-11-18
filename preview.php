<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/engine.php';
require_admin();

$lastSite = $pdo->query("SELECT * FROM sites ORDER BY id DESC LIMIT 1")->fetch() ?: [];

$isSubmitted = ($_SERVER['REQUEST_METHOD'] === 'POST') || isset($_GET['start_url']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$name = trim((string)($input['name'] ?? ($lastSite['name'] ?? 'Website cần kiểm tra')));
$start = trim((string)($input['start_url'] ?? ($lastSite['start_url'] ?? '')));
$domainsRaw = trim((string)($input['allowed_domains'] ?? ($lastSite['allowed_domains'] ?? '')));
$maxPages = max(1, min(2000, (int)($input['max_pages'] ?? ($lastSite['max_pages'] ?? 50))));
$maxDepth = max(0, min(15, (int)($input['max_depth'] ?? ($lastSite['max_depth'] ?? 3))));
$timeout = max(1, min(30, (int)($input['timeout'] ?? ($lastSite['request_timeout'] ?? 4))));
$duration = max(3, min(600, (int)($input['duration'] ?? ($lastSite['max_duration'] ?? 60))));
$concurrency = max(1, min(32, (int)($input['concurrency'] ?? ($lastSite['concurrency'] ?? 15))));

$allowSubdomains = $isSubmitted
    ? !empty($input['allow_subdomains'])
    : (!isset($lastSite['allow_subdomains']) || !empty($lastSite['allow_subdomains']));
$checkExternalAssets = $isSubmitted
    ? !empty($input['check_external_assets'])
    : !empty($lastSite['check_external_assets']);
$allowLocal = $isSubmitted
    ? !empty($input['allow_local'])
    : !empty($lastSite['allow_local']);

$error = '';
$normalizedStart = '';
$domains = [];
$startHost = '';

if ($isSubmitted && $start !== '') {
    $norm = ls_normalize_url($start, $start);
    if (!$norm) {
        $error = 'URL bắt đầu không hợp lệ. Hãy nhập địa chỉ đầy đủ bắt đầu bằng http:// hoặc https://.';
    } else {
        $normalizedStart = $norm['url'];
        $startHost = $norm['host'];
        if (in_array($startHost, ['localhost', '127.0.0.1', '::1'], true) && !empty($input['is_testbed'])) {
            $allowLocal = true;
        }
        $domains = ls_parse_allowed_domains($normalizedStart, $domainsRaw, $allowSubdomains);
        if (!ls_validate_domain_list($domains, $allowLocal)) {
            $error = 'Danh sách domain được phép chứa tên miền không hợp lệ.';
        } elseif (!ls_host_in_domains($startHost, $domains, $allowSubdomains)) {
            $error = 'Tên miền của URL bắt đầu (' . $startHost . ') phải nằm trong danh sách domain được phép.';
        } elseif (!ls_is_safe_host($startHost, $allowLocal)) {
            $error = 'Địa chỉ máy chủ nội bộ (localhost / mạng riêng) bị chặn theo mặc định. Nếu bạn đang kiểm tra Website thử nghiệm nội bộ trên XAMPP, hãy bật tùy chọn "Cho phép môi trường thử nghiệm nội bộ (localhost)".';
        }
    }
}

layout_start('Xem trước phạm vi quét'); ?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
  <div>
    <div class="small-label mb-2">CHỨC NĂNG 1 · SCOPE PREVIEW</div>
    <h1 class="h3 page-title mb-1">Xem trước phạm vi & giới hạn quét</h1>
    <p class="muted mb-0">Rà soát địa chỉ bắt đầu, danh sách miền được phép (allowlist), giới hạn tải và chính sách chuyển hướng trước khi thực thi.</p>
  </div>
  <a class="btn btn-outline-secondary" href="index.php">← Quay lại Tổng quan</a>
</div>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card p-4 h-100">
      <h2 class="h5 fw-bold mb-3">1. Khai báo cấu hình & phạm vi</h2>
      <form method="post" action="preview.php">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <div class="mb-3">
          <label class="form-label fw-semibold">Tên cấu hình / Dự án</label>
          <input class="form-control" name="name" required maxlength="255" value="<?=e($name)?>" placeholder="Ví dụ: Website thử nghiệm Đề tài 13">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Địa chỉ URL bắt đầu</label>
          <input class="form-control" name="start_url" type="url" required value="<?=e($start)?>" placeholder="https://example.com/">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Các miền được phép (Allowlist Domains)</label>
          <input class="form-control" name="allowed_domains" value="<?=e($domainsRaw)?>" placeholder="Để trống sẽ tự lấy từ URL bắt đầu (hỗ trợ *.domain.com)">
          <div class="form-text">Phân cách nhiều miền bằng dấu phẩy. Ví dụ: <code>vnexpress.net, *.vnecdn.net</code>.</div>
        </div>
        <div class="row g-3">
          <div class="col-6 col-md-4">
            <label class="form-label fw-semibold">Số trang tối đa</label>
            <input class="form-control" type="number" name="max_pages" min="1" max="2000" value="<?=$maxPages?>" required>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label fw-semibold">Độ sâu BFS tối đa</label>
            <input class="form-control" type="number" name="max_depth" min="0" max="15" value="<?=$maxDepth?>" required>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label fw-semibold">Luồng song song</label>
            <input class="form-control" type="number" name="concurrency" min="1" max="32" value="<?=$concurrency?>" required>
          </div>
          <div class="col-6 col-md-6">
            <label class="form-label fw-semibold">Timeout mỗi yêu cầu (giây)</label>
            <input class="form-control" type="number" name="timeout" min="1" max="30" value="<?=$timeout?>" required>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Tổng thời gian tối đa (giây)</label>
            <input class="form-control" type="number" name="duration" min="3" max="600" value="<?=$duration?>" required>
          </div>
        </div>
        <div class="mt-3 pt-2 border-top">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="prevAllowSub" name="allow_subdomains" value="1" <?=$allowSubdomains?'checked':''?>>
            <label class="form-check-label small" for="prevAllowSub">Cho phép các tên miền phụ (Subdomains, ví dụ <code>www.</code>, <code>static.</code> cùng miền chính)</label>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="prevExtAssets" name="check_external_assets" value="1" <?=$checkExternalAssets?'checked':''?>>
            <label class="form-check-label small" for="prevExtAssets">Kiểm tra thêm tài nguyên tĩnh (ảnh/CSS/JS) đặt trên CDN ngoài (nhưng không duyệt sâu trang ngoài)</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="prevAllowLocal" name="allow_local" value="1" <?=$allowLocal?'checked':''?>>
            <label class="form-check-label small" for="prevAllowLocal">Cho phép môi trường thử nghiệm nội bộ (<code>localhost</code> / <code>127.0.0.1</code> do giảng viên hoặc nhóm khai báo)</label>
          </div>
        </div>
        <div class="d-flex gap-2 flex-wrap mt-4">
          <button class="btn btn-outline-primary" type="submit">🔍 Kiểm tra & Xem trước phạm vi</button>
        </div>
      </form>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card p-4 h-100">
      <h2 class="h5 fw-bold mb-3">2. Kết quả phân tích phạm vi trước khi chạy</h2>
      <?php if (!$isSubmitted || $start === ''): ?>
        <div class="text-center py-5 muted">
          <div class="metric-icon mx-auto mb-3">⌕</div>
          <p class="mb-0">Nhập cấu hình bên trái và nhấn <strong>Kiểm tra & Xem trước phạm vi</strong> để xác nhận các quy tắc trước khi chạy thật.</p>
        </div>
      <?php elseif ($error !== ''): ?>
        <div class="alert alert-danger mb-3">
          <strong>Cấu hình chưa hợp lệ:</strong><br><?=e($error)?>
        </div>
        <div class="small muted">Hệ thống đã chặn phiên quét trước khi gửi bất kỳ yêu cầu HTTP nào.</div>
      <?php else: ?>
        <div class="alert alert-success mb-3">
          <strong>✓ Phạm vi hợp lệ & sẵn sàng chạy kiểm tra!</strong><br>
          Bản xem trước này chưa gửi yêu cầu HTTP tới máy chủ đích. Khi bắt đầu quét, mọi địa chỉ và mọi bước chuyển hướng (Redirect 3xx) đều được kiểm tra đối chiếu với bảng phạm vi dưới đây.
        </div>
        <div class="table-responsive mb-3">
          <table class="table table-sm mb-0">
            <tbody>
              <tr><th style="width:38%">Tên cấu hình</th><td><strong><?=e($name)?></strong></td></tr>
              <tr><th>URL bắt đầu (chuẩn hóa)</th><td class="wrap-url"><code><?=e($normalizedStart)?></code></td></tr>
              <tr><th>Miền được phép (Allowlist)</th><td><?php foreach ($domains as $d): ?><span class="badge text-bg-primary me-1"><?=e($d)?></span><?php endforeach; ?></td></tr>
              <tr><th>Chính sách Subdomain</th><td><?=$allowSubdomains ? 'Cho phép subdomain (*.'.e(preg_replace('/^www\./i','',$startHost)).')' : 'Chỉ khớp chính xác tên miền trong danh sách'?></td></tr>
              <tr><th>Tài nguyên CDN ngoài</th><td><?=$checkExternalAssets ? 'Có kiểm tra trạng thái ảnh/CSS/JS trên CDN' : 'Nghiêm ngặt: Mọi địa chỉ ngoài Allowlist ghi nhận là Bỏ qua (unchecked)'?></td></tr>
              <tr><th>Môi trường nội bộ</th><td><?=$allowLocal ? '<span class="badge text-bg-warning">Cho phép localhost / 127.0.0.1 (Môi trường thử)</span>' : '<span class="badge text-bg-secondary">Chặn toàn bộ localhost & IP nội bộ</span>'?></td></tr>
              <tr><th>Giới hạn duyệt BFS</th><td>Tối đa <strong><?=$maxPages?> trang</strong> · Độ sâu tối đa <strong><?=$maxDepth?> cấp</strong></td></tr>
              <tr><th>Giới hạn thời gian & tải</th><td>Timeout <strong><?=$timeout?>s</strong>/request · Tổng thời gian <strong><?=$duration?>s</strong> · Đa luồng <strong><?=$concurrency?> kết nối</strong></td></tr>
              <tr><th>Xử lý khi chạm giới hạn</th><td>Dừng ngay lập tức; các địa chỉ còn trong hàng đợi chưa kiểm tra được ghi rõ là <code>unchecked</code>. Timeout được ghi là <code>unknown</code> (không đánh đồng với 404).</td></tr>
            </tbody>
          </table>
        </div>
        <form method="post" action="scan.php" data-scan-form="1">
          <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
          <input type="hidden" name="name" value="<?=e($name)?>">
          <input type="hidden" name="start_url" value="<?=e($normalizedStart)?>">
          <input type="hidden" name="allowed_domains" value="<?=e(implode(', ', $domains))?>">
          <input type="hidden" name="max_pages" value="<?=$maxPages?>">
          <input type="hidden" name="max_depth" value="<?=$maxDepth?>">
          <input type="hidden" name="timeout" value="<?=$timeout?>">
          <input type="hidden" name="duration" value="<?=$duration?>">
          <input type="hidden" name="concurrency" value="<?=$concurrency?>">
          <?php if ($allowSubdomains): ?><input type="hidden" name="allow_subdomains" value="1"><?php endif; ?>
          <?php if ($checkExternalAssets): ?><input type="hidden" name="check_external_assets" value="1"><?php endif; ?>
          <?php if ($allowLocal): ?><input type="hidden" name="allow_local" value="1"><?php endif; ?>
          <button class="btn btn-primary w-100 py-2" type="submit">▶ Bắt đầu quét với phạm vi đã duyệt</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php layout_end(); ?>
