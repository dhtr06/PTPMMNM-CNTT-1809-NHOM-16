<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_login();

$site = $pdo->query("SELECT * FROM sites ORDER BY id DESC LIMIT 1")->fetch() ?: [];
$stats = $pdo->query("SELECT COUNT(*) runs, COALESCE(SUM(total_pages_crawled),0) pages, COALESCE(SUM(total_links_found),0) links, COALESCE(SUM(total_errors),0) errors FROM scan_runs")->fetch() ?: ['runs'=>0,'pages'=>0,'links'=>0,'errors'=>0];
$recent = $pdo->query("SELECT r.id,r.started_at,r.status,r.stop_reason,r.total_pages_crawled,r.total_links_found,r.total_errors,r.total_unchecked,s.name,s.start_url FROM scan_runs r JOIN sites s ON s.id=r.site_id ORDER BY r.id DESC LIMIT 6")->fetchAll();

// Tương thích cả MySQL và SQLite cho biểu đồ xu hướng 7 ngày
$sevenDaysAgo = date('Y-m-d 00:00:00', strtotime('-6 days'));
$stTrend = $pdo->prepare("SELECT SUBSTR(started_at, 1, 10) AS day, COUNT(*) AS runs, COALESCE(SUM(total_errors),0) AS errors FROM scan_runs WHERE started_at >= ? GROUP BY SUBSTR(started_at, 1, 10) ORDER BY day");
$stTrend->execute([$sevenDaysAgo]);
$trend = $stTrend->fetchAll();

$notifications = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn();
$testbedUrl = get_testbed_start_url();

layout_start('Tổng quan'); ?>
<section class="hero mb-4">
  <div class="row align-items-center g-3">
    <div class="col-lg-8">
      <div class="small-label text-info mb-2">ĐỀ TÀI 13 · MULTI-CURL WEB CRAWLER & LINK CHECKER</div>
      <h1 class="page-title h2 mb-2">Kiểm tra liên kết & tài nguyên bị lỗi trên Website</h1>
      <p class="mb-0">Tự động duyệt BFS chống lặp vòng, chuẩn hóa URL tương đối, kiểm tra song song tốc độ cao cho cả website lớn, phân biệt rõ lỗi HTTP (4xx/5xx), lỗi kết nối, mục neo (#anchor) bị thiếu, timeout (chưa xác định) và mục ngoài phạm vi.</p>
    </div>
    <div class="col-lg-4 text-lg-end">
      <div class="d-inline-flex flex-wrap gap-2 justify-content-lg-end">
        <a href="tests.php" class="btn btn-light btn-sm fw-bold">🧪 Chạy 5 phép thử bắt buộc</a>
        <a href="testbed/index.php" target="_blank" class="btn btn-outline-light btn-sm">🌐 Mở Web mẫu (22 trang)</a>
      </div>
    </div>
  </div>
</section>

<div class="row g-3 mb-4">
<?php foreach ([
    ['Tổng phiên quét', (int)$stats['runs'], '▤', 'Lịch sử các lần chạy đã lưu'],
    ['Trang HTML đã duyệt', (int)$stats['pages'], '⌕', 'Duyệt BFS không lặp vòng'],
    ['Liên kết & Tài nguyên', (int)$stats['links'], '↗', 'Giữ đầy đủ mọi trang nguồn'],
    ['Đích lỗi phát hiện', (int)$stats['errors'], '⚠', '404, 500, ảnh hỏng, thiếu #anchor']
] as $m): ?>
  <div class="col-6 col-xl-3">
    <div class="card p-3 h-100">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="muted small mb-1"><?=e($m[0])?></div>
          <div class="metric"><?=number_format($m[1])?></div>
        </div>
        <div class="metric-icon"><?=e($m[2])?></div>
      </div>
      <div class="small muted mt-2"><?=e($m[3])?></div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="row g-4">
  <div class="col-lg-8">
    <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
      <div class="card p-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
          <div>
            <h2 class="h4 fw-bold mb-1">Khởi tạo phiên kiểm tra mới</h2>
            <p class="muted small mb-0">Chọn mẫu cấu hình nhanh bên dưới hoặc tự thiết lập phạm vi quét.</p>
          </div>
          <div class="d-flex flex-wrap gap-1">
            <button type="button" class="btn btn-outline-primary preset-btn" onclick="applyPreset('testbed')">🧪 Web thử Đề tài 13 (22 trang)</button>
            <button type="button" class="btn btn-outline-secondary preset-btn" onclick="applyPreset('standard')">⚡ Quét tiêu chuẩn</button>
            <button type="button" class="btn btn-outline-dark preset-btn" onclick="applyPreset('large')">🚀 Quét Web lớn (Đa luồng)</button>
          </div>
        </div>

        <form method="post" action="scan.php" id="mainScanForm" data-scan-form="1">
          <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
          <div class="row g-3 mb-3">
            <div class="col-md-5">
              <label class="form-label fw-semibold">Tên cấu hình / Website</label>
              <input class="form-control" id="f_name" name="name" required maxlength="255" value="<?=e($site['name'] ?? 'Website thử nghiệm Đề tài 13')?>" placeholder="Ví dụ: Website cổng thông tin">
            </div>
            <div class="col-md-7">
              <label class="form-label fw-semibold">Địa chỉ bắt đầu (Start URL)</label>
              <input class="form-control" id="f_start_url" type="url" name="start_url" required maxlength="950" value="<?=e($site['start_url'] ?? $testbedUrl)?>" placeholder="https://example.com/">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Các miền được phép (Allowed Domains) <span class="muted fw-normal">(để trống sẽ tự lấy từ URL bắt đầu)</span></label>
            <input class="form-control" id="f_domains" name="allowed_domains" maxlength="950" placeholder="example.com, *.example.com, cdn.example.com" value="<?=e($site['allowed_domains'] ?? '')?>">
            <div class="form-text">Hỗ trợ nhiều domain cách nhau dấu phẩy và ký tự đại diện <code>*.domain.com</code>. Mọi URL/redirect ngoài phạm vi sẽ được ghi là <code>unchecked</code> (bỏ qua).</div>
          </div>

          <div class="row g-3">
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Số trang tối đa</label>
              <input class="form-control" id="f_max_pages" type="number" name="max_pages" min="1" max="2000" value="<?=e((int)($site['max_pages'] ?? 40))?>" required>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label fw-semibold">Độ sâu</label>
              <input class="form-control" id="f_max_depth" type="number" name="max_depth" min="0" max="15" value="<?=e((int)($site['max_depth'] ?? 4))?>" required>
            </div>
            <div class="col-4 col-md-2">
              <label class="form-label fw-semibold">Timeout (s)</label>
              <input class="form-control" id="f_timeout" type="number" step="1" name="timeout" min="1" max="30" value="<?=e(max(1, min(30, (int)($site['request_timeout'] ?? 3))))?>" required>
            </div>
            <div class="col-4 col-md-3">
              <label class="form-label fw-semibold">Tổng thời gian (s)</label>
              <input class="form-control" id="f_duration" type="number" name="duration" min="3" max="600" value="<?=e(min(600, (int)($site['max_duration'] ?? 60)))?>" required>
            </div>
            <div class="col-4 col-md-2">
              <label class="form-label fw-semibold" title="Số yêu cầu HTTP gửi song song bằng Multi-cURL">Đa luồng</label>
              <input class="form-control" id="f_concurrency" type="number" name="concurrency" min="1" max="32" value="<?=e(max(1, min(32, (int)($site['concurrency'] ?? 16))))?>" required>
            </div>
          </div>

          <div class="mt-3 pt-3 border-top">
            <div class="row g-2">
              <div class="col-md-6">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="f_allow_sub" name="allow_subdomains" value="1" <?=(!isset($site['allow_subdomains']) || !empty($site['allow_subdomains'])) ? 'checked' : ''?>>
                  <label class="form-check-label small" for="f_allow_sub">Cho phép subdomain cùng tên miền (<code>*.domain</code>)</label>
                </div>
              </div>
              <div class="col-md-6">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="f_ext_assets" name="check_external_assets" value="1" <?=!empty($site['check_external_assets']) ? 'checked' : ''?>>
                  <label class="form-check-label small" for="f_ext_assets">Kiểm tra cả ảnh/CSS/JS đặt trên CDN ngoài</label>
                </div>
              </div>
              <div class="col-12">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="f_allow_local" name="allow_local" value="1" <?=(!isset($site['allow_local']) || !empty($site['allow_local'])) ? 'checked' : ''?>>
                  <label class="form-check-label small" for="f_allow_local">Môi trường thử nghiệm nội bộ được khai báo (cho phép <code>localhost</code> / <code>127.0.0.1</code> theo Mục 5 Đề tài 13)</label>
                </div>
              </div>
            </div>
          </div>

          <div class="d-flex flex-wrap gap-2 mt-4">
            <button class="btn btn-outline-primary" type="submit" formaction="preview.php">🔍 1. Xem trước phạm vi (Tình huống 1)</button>
            <button class="btn btn-primary" type="submit">▶ 2. Bắt đầu quét ngay</button>
            <a class="btn btn-outline-secondary" href="compare.php">⇄ So sánh 2 lần chạy</a>
          </div>
        </form>
      </div>
    <?php else: ?>
      <div class="card p-4 mb-4">
        <h2 class="h5 fw-bold">Chế độ chỉ xem (Viewer)</h2>
        <p class="muted mb-0">Tài khoản <code>viewer</code> có thể xem báo cáo, lọc theo trang/lỗi/loại tài nguyên, xuất CSV/PDF và đối chiếu so sánh hai phiên quét. Hãy đăng nhập bằng tài khoản <code>admin</code> để khởi chạy phiên quét mới.</p>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <div class="card p-4 mb-4">
      <h2 class="h5 fw-bold mb-3">Hai tình huống trình diễn (Mục 4)</h2>
      <div class="p-3 rounded-3 mb-3" style="background:#f4f5ff;border:1px solid #e0e3ff">
        <div class="fw-bold text-primary small mb-1">TÌNH HUỐNG 1 · PHÁT HIỆN & ĐỊNH VỊ LỖI</div>
        <div class="small text-dark">Chọn <strong>Web thử Đề tài 13</strong> → Nhấn <strong>Xem trước phạm vi</strong> → <strong>Bắt đầu quét</strong> → Mở 1 lỗi trong báo cáo → Xem trang nguồn, số dòng HTML và mở trang chứa liên kết để sửa.</div>
      </div>
      <div class="p-3 rounded-3 mb-3" style="background:#f2fbf7;border:1px solid #d1fae5">
        <div class="fw-bold text-success small mb-1">TÌNH HUỐNG 2 · SỬA LỖI & ĐỐI CHIẾU</div>
        <div class="small text-dark">Vào <a href="tests.php" class="fw-bold">Kiểm thử & Web mẫu</a> bật <strong>Chế độ Đã sửa lỗi</strong> → Quay lại báo cáo nhấn <strong>Chạy lại cùng phạm vi</strong> → Mở <strong>So sánh</strong> để xác nhận lỗi đã hết và xuất báo cáo.</div>
      </div>
      <a href="tests.php" class="btn btn-sm btn-outline-primary w-100">Mở Bảng điều khiển Web mẫu & Đáp án lỗi →</a>
    </div>

    <div class="card p-4">
      <h2 class="h5 fw-bold mb-2">Phân loại kết quả chuẩn</h2>
      <div class="d-flex justify-content-between py-2 border-bottom"><span><span class="badge text-bg-success">ok</span> Hoạt động tốt</span><span class="muted small">HTTP 2xx</span></div>
      <div class="d-flex justify-content-between py-2 border-bottom"><span><span class="badge text-bg-warning">redirect</span> Chuyển hướng</span><span class="muted small">HTTP 301/302</span></div>
      <div class="d-flex justify-content-between py-2 border-bottom"><span><span class="badge text-bg-danger">http_error</span> Lỗi HTTP / Ảnh hỏng</span><span class="muted small">404 / 500 / Ảnh lỗi</span></div>
      <div class="d-flex justify-content-between py-2 border-bottom"><span><span class="badge text-bg-danger">missing_anchor</span> Thiếu mục neo</span><span class="muted small">Thiếu #id / #name</span></div>
      <div class="d-flex justify-content-between py-2 border-bottom"><span><span class="badge text-bg-danger">connection_error</span> Lỗi kết nối</span><span class="muted small">DNS / Từ chối TCP</span></div>
      <div class="d-flex justify-content-between py-2 border-bottom"><span><span class="badge text-bg-info">unknown</span> Chưa xác định</span><span class="muted small">Hết hạn chờ (Timeout)</span></div>
      <div class="d-flex justify-content-between pt-2"><span><span class="badge text-bg-secondary">unchecked</span> Chưa kiểm tra</span><span class="muted small">Ngoài miền / Vượt giới hạn</span></div>
    </div>
  </div>
</div>

<div class="row g-4 mt-1 mb-4">
  <div class="col-lg-8">
    <div class="card p-4 h-100">
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
          <h2 class="h5 fw-bold mb-1">Xu hướng kiểm tra 7 ngày gần nhất</h2>
          <p class="muted small mb-0">Số phiên quét thực hiện và tổng số lỗi phát hiện theo ngày.</p>
        </div>
        <span class="badge text-bg-light">7 ngày</span>
      </div>
      <canvas id="trendChart" height="110" aria-label="Biểu đồ xu hướng quét 7 ngày"></canvas>
    </div>
  </div>
  <div class="col-lg-4">
    <a href="notifications.php" class="card p-4 d-block text-decoration-none text-reset mb-3">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="muted small mb-1">Thông báo cảnh báo chưa đọc</div>
          <div class="metric"><?=number_format($notifications)?></div>
          <div class="small muted mt-2">Xem cảnh báo phiên quét có lỗi →</div>
        </div>
        <div class="metric-icon">🔔</div>
      </div>
    </a>
    <div class="card p-4">
      <h3 class="h6 fw-bold mb-2">Hỗ trợ quét Website lớn</h3>
      <p class="small muted mb-2">Nhờ cơ chế <strong>Multi-cURL bất đồng bộ</strong> kết hợp bộ nhớ đệm DNS và giới hạn tải thông minh (24KB cho ảnh/tài nguyên, giải nén Gzip tự động), LinkScope có thể kiểm tra hàng trăm liên kết chỉ trong vài giây.</p>
      <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
        <a class="btn btn-sm btn-outline-primary w-100" href="schedules.php">⏰ Cấu hình giám sát định kỳ</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card p-3 p-md-4 mt-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <h2 class="h5 fw-bold mb-1">Các phiên kiểm tra gần đây</h2>
      <p class="muted small mb-0">Mở báo cáo để lọc theo lỗi/trang/loại tài nguyên, kiểm tra lại mục đã chọn hoặc xuất CSV/PDF.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="compare.php" class="btn btn-sm btn-outline-primary">So sánh 2 phiên</a>
      <a href="history.php" class="btn btn-sm btn-outline-secondary">Tất cả lịch sử →</a>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Phiên</th>
          <th>Website / Cấu hình</th>
          <th>Thời điểm</th>
          <th>Trang</th>
          <th>Liên kết</th>
          <th>Lỗi</th>
          <th>Chưa KT / Bỏ qua</th>
          <th>Lý do dừng</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$recent): ?>
          <tr><td colspan="9" class="text-center py-4 muted">Chưa có phiên quét nào. Hãy nhấn nút <strong>Web thử Đề tài 13</strong> rồi bấm <strong>Bắt đầu quét ngay</strong> ở phía trên.</td></tr>
        <?php endif; ?>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td class="fw-bold">#<?=e($r['id'])?></td>
            <td>
              <strong><?=e($r['name'])?></strong>
              <div class="small muted wrap-url"><?=e($r['start_url'])?></div>
            </td>
            <td class="small"><?=e($r['started_at'])?></td>
            <td><?=number_format((int)$r['total_pages_crawled'])?></td>
            <td><?=number_format((int)$r['total_links_found'])?></td>
            <td><span class="badge text-bg-<?=((int)$r['total_errors'] > 0 ? 'danger' : 'success')?>"><?=number_format((int)$r['total_errors'])?> lỗi</span></td>
            <td><span class="badge text-bg-secondary"><?=number_format((int)$r['total_unchecked'])?></span></td>
            <td class="small muted"><?=e($r['stop_reason'] ?? '—')?></td>
            <td>
              <div class="d-flex gap-1">
                <a class="btn btn-sm btn-outline-primary" href="report.php?run_id=<?=(int)$r['id']?>">Báo cáo</a>
                <a class="btn btn-sm btn-outline-success" href="export.php?run_id=<?=(int)$r['id']?>">CSV</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
const TESTBED_URL = <?=json_encode($testbedUrl)?>;
function applyPreset(mode) {
  const fName = document.getElementById('f_name');
  const fUrl = document.getElementById('f_start_url');
  const fDom = document.getElementById('f_domains');
  const fPages = document.getElementById('f_max_pages');
  const fDepth = document.getElementById('f_max_depth');
  const fTimeout = document.getElementById('f_timeout');
  const fDur = document.getElementById('f_duration');
  const fConc = document.getElementById('f_concurrency');
  const fSub = document.getElementById('f_allow_sub');
  const fExt = document.getElementById('f_ext_assets');
  const fLoc = document.getElementById('f_allow_local');

  if (mode === 'testbed') {
    fName.value = 'Website thử nghiệm Đề tài 13 (22 trang)';
    fUrl.value = TESTBED_URL;
    fDom.value = '';
    fPages.value = 30;
    fDepth.value = 5;
    fTimeout.value = 2;
    fDur.value = 45;
    fConc.value = 10;
    fSub.checked = true;
    fExt.checked = false;
    fLoc.checked = true;
  } else if (mode === 'standard') {
    if (fUrl.value === TESTBED_URL) fUrl.value = 'https://example.com/';
    fName.value = 'Kiểm tra Website tiêu chuẩn';
    fPages.value = 50;
    fDepth.value = 3;
    fTimeout.value = 4;
    fDur.value = 60;
    fConc.value = 16;
    fSub.checked = true;
    fExt.checked = false;
  } else if (mode === 'large') {
    if (fUrl.value === TESTBED_URL) fUrl.value = 'https://vnexpress.net/';
    fName.value = 'Quét Website lớn (Đa luồng cURL)';
    fPages.value = 250;
    fDepth.value = 4;
    fTimeout.value = 5;
    fDur.value = 180;
    fConc.value = 24;
    fSub.checked = true;
    fExt.checked = true;
  }
}

if (window.Chart && document.getElementById('trendChart')) {
  new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
      labels: <?=json_encode(array_column($trend, 'day'))?>,
      datasets: [
        {
          label: 'Phiên quét',
          data: <?=json_encode(array_map('intval', array_column($trend, 'runs')))?>,
          borderColor: '#635bff',
          backgroundColor: '#635bff20',
          fill: true,
          tension: 0.35
        },
        {
          label: 'Lỗi phát hiện',
          data: <?=json_encode(array_map('intval', array_column($trend, 'errors')))?>,
          borderColor: '#dc4c64',
          backgroundColor: '#dc4c6420',
          fill: true,
          tension: 0.35
        }
      ]
    },
    options: {
      responsive: true,
      plugins: { legend: { position: 'bottom' } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
  });
}
</script>
<?php layout_end(); ?>
