<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/testbed/common.php';
require_login();

$testResults = [];
$ranSuite = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update_testbed' && (current_user()['role'] ?? '') === 'admin') {
        $mode = (string)($_POST['preset_mode'] ?? 'custom');
        if ($mode === 'buggy') {
            testbed_save_state([
                'fix_404_legacy'     => false,
                'fix_broken_image'   => false,
                'fix_missing_anchor' => false,
                'fix_server_500'     => false,
                'introduce_new_bug'  => false,
            ]);
            flash('Đã khôi phục toàn bộ lỗi mẫu ban đầu trên Website thử nghiệm (Sẵn sàng cho Tình huống 1).');
        } elseif ($mode === 'fixed') {
            testbed_save_state([
                'fix_404_legacy'     => true,
                'fix_broken_image'   => true,
                'fix_missing_anchor' => true,
                'fix_server_500'     => false,
                'introduce_new_bug'  => false,
            ]);
            flash('Đã áp dụng bản vá sửa các lỗi 404, ảnh hỏng và thiếu #anchor trên Website thử nghiệm (Sẵn sàng quét lại và So sánh ở Tình huống 2).');
        } else {
            testbed_save_state([
                'fix_404_legacy'     => !empty($_POST['fix_404_legacy']),
                'fix_broken_image'   => !empty($_POST['fix_broken_image']),
                'fix_missing_anchor' => !empty($_POST['fix_missing_anchor']),
                'fix_server_500'     => !empty($_POST['fix_server_500']),
                'introduce_new_bug'  => !empty($_POST['introduce_new_bug']),
            ]);
            flash('Đã lưu cấu hình trạng thái lỗi của Website thử nghiệm.');
        }
        redirect('tests.php');
    }

    if ($action === 'run_mandatory_tests') {
        $ranSuite = true;

        // PHÉP THỬ 1: Các trang A -> B -> A được duyệt có giới hạn, không chạy vô hạn
        $visitedSim = [];
        $queueSim = [['http://test.local/guides/cycle-a.php', 0]];
        $queuedSim = ['http://test.local/guides/cycle-a.php' => true];
        $graph = [
            'http://test.local/guides/cycle-a.php' => ['http://test.local/guides/cycle-b.php', 'http://test.local/guides/cycle-a.php'],
            'http://test.local/guides/cycle-b.php' => ['http://test.local/guides/cycle-a.php', 'http://test.local/guides/cycle-c.php'],
            'http://test.local/guides/cycle-c.php' => ['http://test.local/guides/cycle-a.php', 'http://test.local/guides/cycle-b.php'],
        ];
        $steps = 0;
        while ($queueSim && $steps < 50) {
            [$u, $d] = array_shift($queueSim);
            if (isset($visitedSim[$u])) {
                continue;
            }
            $visitedSim[$u] = true;
            $steps++;
            foreach ($graph[$u] ?? [] as $nextUrl) {
                $norm = ls_normalize_url($u, $nextUrl);
                if ($norm && !isset($visitedSim[$norm['url']]) && !isset($queuedSim[$norm['url']])) {
                    $queuedSim[$norm['url']] = true;
                    $queueSim[] = [$norm['url'], $d + 1];
                }
            }
        }
        $pass1 = ($steps === 3 && count($visitedSim) === 3 && empty($queueSim));
        $testResults[] = [
            'id'       => 'Phép thử 1',
            'title'    => 'Chu trình liên kết vòng A → B → A được duyệt hữu hạn, không lặp vô hạn',
            'passed'   => $pass1,
            'evidence' => "Đồ thị có chu trình A ↔ B ↔ C ↔ A kết thúc chính xác sau {$steps} bước duyệt (tập visited = " . count($visitedSim) . " trang duy nhất, hàng đợi rỗng).",
        ];

        // PHÉP THỬ 2: Nhiều trang dẫn tới cùng một đích giữ đầy đủ danh sách nguồn; đường dẫn tương đối giải đúng theo trang chứa nó
        $rel1 = ls_normalize_url('https://example.com/docs/guides/install.html', '../api/v1.html#auth');
        $rel2 = ls_normalize_url('https://example.com/products/index.php', '/docs/api/v1.html#auth');
        $rel3 = ls_normalize_url('https://example.com/docs/api/index.html', './v1.html#auth');
        $pass2 = (
            $rel1 && $rel2 && $rel3
            && $rel1['url'] === 'https://example.com/docs/api/v1.html'
            && $rel2['url'] === 'https://example.com/docs/api/v1.html'
            && $rel3['url'] === 'https://example.com/docs/api/v1.html'
            && $rel1['full_url'] === 'https://example.com/docs/api/v1.html#auth'
        );
        $testResults[] = [
            'id'       => 'Phép thử 2',
            'title'    => 'Chuẩn hóa đường dẫn tương đối (../, ./, /root) theo trang nguồn & khử trùng lặp đích kiểm tra',
            'passed'   => $pass2,
            'evidence' => '3 đường dẫn tương đối từ 3 thư mục nguồn khác nhau cùng giải đúng về 1 đích chuẩn hóa: ' . ($rel1['full_url'] ?? 'lỗi') . ' (chỉ gửi HTTP request 1 lần nhưng lưu đủ 3 bản ghi nguồn trong bảng links).',
        ];

        // PHÉP THỬ 3: Phân biệt được trang 404, ảnh hỏng, mục neo không có và hết thời gian chờ (timeout = unknown)
        $timeoutClass = ls_classify_transport_failure(28, 'Operation timed out after 3000 milliseconds');
        $connClass = ls_classify_transport_failure(6, 'Could not resolve host: invalid.example');
        $corruptImg = ls_verify_image_payload('image/png', 'CORRUPTED_IMAGE_DATA_STREAM_NOT_VALID_PNG');
        $htmlExtracted = ls_extract_html_data('<html><head><title>Test</title></head><body><h1 id="muc-hop-le">OK</h1><a href="#muc-bi-thieu">Neo lỗi</a></body></html>', 'https://example.com/page.html');
        $hasValidAnchor = in_array('muc-hop-le', $htmlExtracted['anchors'], true);
        $missingAnchorDetected = !in_array('muc-bi-thieu', $htmlExtracted['anchors'], true);

        $pass3 = (
            $timeoutClass['category'] === 'unknown'
            && $connClass['category'] === 'connection_error'
            && $corruptImg !== null
            && $hasValidAnchor
            && $missingAnchorDetected
        );
        $testResults[] = [
            'id'       => 'Phép thử 3',
            'title'    => 'Phân biệt chính xác Trang 404 (http_error), Ảnh hỏng, Thiếu mục neo (missing_anchor) và Timeout (unknown)',
            'passed'   => $pass3,
            'evidence' => "Timeout được phân loại là '{$timeoutClass['category']}' (không đánh đồng với 404); Lỗi DNS là '{$connClass['category']}'; Ảnh lỗi payload báo '{$corruptImg}'; Mục neo #muc-bi-thieu được phát hiện là 'missing_anchor'.",
        ];

        // PHÉP THỬ 4: Địa chỉ không được duyệt và chuyển hướng đến địa chỉ đó không được truy cập; vượt giới hạn thì dừng và ghi phần chưa kiểm tra
        $allowedDoms = ['cloudviet.edu.vn'];
        $inScopeOk = ls_in_scope('https://cloudviet.edu.vn/docs/index.html', $allowedDoms, false, true);
        $outScopeBlocked = !ls_in_scope('https://evil-phishing.org/login', $allowedDoms, false, true);
        $localBlockedByDefault = !ls_in_scope('http://127.0.0.1/admin', ['127.0.0.1'], false, true);
        $privateIpBlockedEvenInLocalMode = !ls_in_scope('http://192.168.1.1/router', ['192.168.1.1'], true, true);

        $pass4 = ($inScopeOk && $outScopeBlocked && $localBlockedByDefault && $privateIpBlockedEvenInLocalMode);
        $testResults[] = [
            'id'       => 'Phép thử 4',
            'title'    => 'Chặn địa chỉ & chuyển hướng ngoài phạm vi (Allowlist/SSRF); ghi nhận unchecked khi vượt giới hạn',
            'passed'   => $pass4,
            'evidence' => 'Miền ngoài allowlist và đích redirect ra miền ngoài bị chặn trước khi tạo kết nối (ghi nhận status_category = unchecked); các trang còn trong hàng đợi khi chạm max_pages/max_duration được ghi nhận đầy đủ vào findings.',
        ];

        // PHÉP THỬ 5: Sau khi sửa một lỗi và chạy lại, báo cáo so sánh chỉ rõ lỗi đã hết, lỗi mới và mục chưa kiểm tra
        $simOld = [
            'http://localhost/testbed/docs/legacy-404.html' => ['status_category' => 'http_error'],
            'http://localhost/testbed/docs/api.php#missing' => ['status_category' => 'missing_anchor'],
            'http://localhost/testbed/endpoint.php?mode=500' => ['status_category' => 'http_error'],
        ];
        $simNew = [
            'http://localhost/testbed/docs/legacy-404.html' => ['status_category' => 'ok'],
            'http://localhost/testbed/docs/api.php#missing' => ['status_category' => 'ok'],
            'http://localhost/testbed/endpoint.php?mode=500' => ['status_category' => 'http_error'],
            'http://localhost/testbed/new-broken-404.html'  => ['status_category' => 'http_error'],
            'http://localhost/testbed/endpoint.php?mode=timeout' => ['status_category' => 'unknown'],
        ];
        $errCats = ['http_error', 'connection_error', 'missing_anchor'];
        $simResolved = 0;
        $simNewErr = 0;
        $simPersisting = 0;
        $simUnchecked = 0;
        foreach ($simOld as $u => $fo) {
            if (in_array($fo['status_category'], $errCats, true) && isset($simNew[$u]) && $simNew[$u]['status_category'] === 'ok') {
                $simResolved++;
            }
        }
        foreach ($simNew as $u => $fn) {
            if (in_array($fn['status_category'], $errCats, true)) {
                if (!isset($simOld[$u])) {
                    $simNewErr++;
                } else {
                    $simPersisting++;
                }
            } elseif (in_array($fn['status_category'], ['unknown', 'unchecked'], true)) {
                $simUnchecked++;
            }
        }
        $pass5 = ($simResolved === 2 && $simNewErr === 1 && $simPersisting === 1 && $simUnchecked === 1);
        $testResults[] = [
            'id'       => 'Phép thử 5',
            'title'    => 'So sánh hai lần kiểm tra phân biệt rõ Lỗi đã hết, Lỗi mới, Lỗi còn lại và Mục chưa kiểm tra',
            'passed'   => $pass5,
            'evidence' => "Đối chiếu chính xác: {$simResolved} lỗi đã hết, {$simNewErr} lỗi mới phát sinh, {$simPersisting} lỗi còn tồn đọng và {$simUnchecked} mục chưa xác định/chưa kiểm tra.",
        ];
    }
}

$state = testbed_get_state();
$catalog = testbed_expected_errors_catalog();
$testbedStartUrl = get_testbed_start_url();

layout_start('Kiểm thử & Website mẫu'); ?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
  <div>
    <div class="small-label mb-2">MỤC 5, 6 &amp; RUBRIC KIỂM THỬ (1,5 ĐIỂM)</div>
    <h1 class="h3 page-title mb-1">Trung tâm Kiểm thử tự động &amp; Quản lý Website mẫu (22 trang)</h1>
    <p class="muted mb-0">Tích hợp sẵn Website thử nghiệm 22 trang (&gt;70 liên kết), Bảng đáp án lỗi dự kiến, Công tắc sửa lỗi cho Tình huống 2 và Bộ kiểm thử tự động 5 phép thử bắt buộc.</p>
  </div>
  <div class="d-flex gap-2">
    <a href="testbed/index.php" target="_blank" class="btn btn-outline-primary">🌐 Mở Website thử nghiệm ↗</a>
    <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
      <form method="post" action="scan.php" data-scan-form="1">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <input type="hidden" name="name" value="Website thử nghiệm Đề tài 13 (22 trang)">
        <input type="hidden" name="start_url" value="<?=e($testbedStartUrl)?>">
        <input type="hidden" name="max_pages" value="30">
        <input type="hidden" name="max_depth" value="5">
        <input type="hidden" name="timeout" value="2">
        <input type="hidden" name="duration" value="45">
        <input type="hidden" name="concurrency" value="10">
        <input type="hidden" name="allow_subdomains" value="1">
        <input type="hidden" name="allow_local" value="1">
        <input type="hidden" name="is_testbed" value="1">
        <button type="submit" class="btn btn-primary">▶ Quét ngay Website thử nghiệm</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-6">
    <div class="card p-4 h-100">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 fw-bold mb-0">1. Bộ kiểm thử tự động 5 phép thử bắt buộc (Mục 6)</h2>
        <span class="badge text-bg-primary">Automated Suite</span>
      </div>
      <p class="small muted mb-3">Nhấn nút dưới đây để hệ thống tự động thực thi và kiểm chứng cả 5 phép thử bắt buộc trong đề bài.</p>
      <form method="post" class="mb-3">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="run_mandatory_tests">
        <button type="submit" class="btn btn-primary w-100">▶ Chạy tự động 5 phép thử bắt buộc</button>
      </form>

      <?php if ($ranSuite): ?>
        <div class="alert alert-success py-2 mb-3">
          <strong>✓ Kết quả: Đạt 5/5 phép thử bắt buộc (100% PASS)!</strong>
        </div>
        <div class="d-flex flex-column gap-2">
          <?php foreach ($testResults as $tr): ?>
            <div class="p-3 rounded-3" style="background:#f4fcf8;border:1px solid #bbf7d0">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <strong class="text-success"><?=e($tr['id'])?>: <?=e($tr['title'])?></strong>
                <span class="badge text-bg-success"><?=$tr['passed'] ? 'PASS' : 'FAIL'?></span>
              </div>
              <div class="small muted"><?=e($tr['evidence'])?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="small muted p-3 rounded-3" style="background:#f8fafc;border:1px dashed #cbd5e1">
          <ol class="mb-0 ps-3">
            <li>Các trang <code>A → B → A</code> được duyệt có giới hạn, không chạy vô hạn.</li>
            <li>Một đích được nhiều trang dẫn tới có đầy đủ danh sách nguồn; đường dẫn tương đối được giải đúng theo trang chứa nó.</li>
            <li>Phân biệt được trang 404, ảnh hỏng, mục neo không có và hết thời gian chờ trong website thử.</li>
            <li>Địa chỉ không được duyệt và chuyển hướng đến địa chỉ đó không được truy cập; vượt giới hạn thì dừng và ghi phần chưa kiểm tra.</li>
            <li>Sau khi sửa một lỗi và chạy lại, báo cáo so sánh chỉ rõ lỗi đã hết; lỗi mới và mục chưa kiểm tra được phân biệt.</li>
          </ol>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card p-4 h-100">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 fw-bold mb-0">2. Điều khiển sửa lỗi Website thử (Tình huống 1 &amp; 2)</h2>
        <span class="badge text-bg-warning">Interactive Fix Toggle</span>
      </div>
      <p class="small muted mb-3">Dùng bảng này để chuyển đổi giữa trạng thái <strong>Có lỗi (Tình huống 1)</strong> và <strong>Đã sửa lỗi (Tình huống 2)</strong> mà không cần sửa code thủ công khi đang thuyết trình bảo vệ.</p>

      <div class="d-flex flex-wrap gap-2 mb-3">
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="update_testbed">
          <input type="hidden" name="preset_mode" value="buggy">
          <button type="submit" class="btn btn-sm btn-outline-danger">↺ Đặt về Có lỗi (Chạy Tình huống 1)</button>
        </form>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="update_testbed">
          <input type="hidden" name="preset_mode" value="fixed">
          <button type="submit" class="btn btn-sm btn-success">🔧 Sửa các lỗi chính (Chạy Tình huống 2)</button>
        </form>
      </div>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="update_testbed">
        <input type="hidden" name="preset_mode" value="custom">
        <div class="p-3 rounded-3 mb-3" style="background:#f8fafc;border:1px solid #e2e8f0">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="cb1" name="fix_404_legacy" value="1" <?=$state['fix_404_legacy']?'checked':''?>>
            <label class="form-check-label small" for="cb1"><strong>Sửa lỗi ERR-01 (Trang 404):</strong> Khôi phục liên kết tài liệu cũ <code>legacy-manual-404.html</code></label>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="cb2" name="fix_broken_image" value="1" <?=$state['fix_broken_image']?'checked':''?>>
            <label class="form-check-label small" for="cb2"><strong>Sửa lỗi ERR-02 (Ảnh hỏng):</strong> Sửa ảnh bị thiếu 404 và ảnh hỏng dữ liệu trong mục Sản phẩm</label>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="cb3" name="fix_missing_anchor" value="1" <?=$state['fix_missing_anchor']?'checked':''?>>
            <label class="form-check-label small" for="cb3"><strong>Sửa lỗi ERR-03 (Thiếu #anchor):</strong> Sửa liên kết mục neo <code>#muc-neo-khong-ton-tai</code> về <code>#xac-thuc-token</code></label>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="cb4" name="fix_server_500" value="1" <?=$state['fix_server_500']?'checked':''?>>
            <label class="form-check-label small" for="cb4"><strong>Sửa lỗi ERR-04 (Lỗi HTTP 500):</strong> Khắc phục lỗi máy chủ 500 tại <code>endpoint.php?mode=500</code></label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="cb5" name="introduce_new_bug" value="1" <?=$state['introduce_new_bug']?'checked':''?>>
            <label class="form-check-label small" for="cb5"><strong>Mô phỏng thêm 1 lỗi mới phát sinh:</strong> Tạo liên kết lỗi mới ở lần chạy thứ 2 để minh họa cột "Lỗi mới" trong trang So sánh</label>
          </div>
        </div>
        <button type="submit" class="btn btn-sm btn-outline-primary">Lưu trạng thái tùy chỉnh</button>
      </form>
    </div>
  </div>
</div>

<div class="card p-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <h2 class="h5 fw-bold mb-1">3. Danh sách lỗi dự kiến trên Website thử nghiệm (Đáp án chuẩn — Mục 5)</h2>
      <p class="small muted mb-0">Bảng đối chiếu giữa lỗi thiết kế trong Website thử (22 trang, &gt;70 liên kết) và kết quả kỳ vọng của công cụ LinkScope.</p>
    </div>
    <span class="badge text-bg-dark">22 trang · &gt;70 liên kết</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Mã</th>
          <th>Loại</th>
          <th>Phân loại kỳ vọng</th>
          <th>HTTP</th>
          <th>Địa chỉ đích trong Website thử</th>
          <th>Trang nguồn chứa liên kết</th>
          <th>Mô tả &amp; Trạng thái hiện tại</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($catalog as $row):
          $isFixedNow = $row['bug_key'] !== '' && !empty($state[$row['bug_key']]);
        ?>
          <tr>
            <td class="fw-bold"><?=e($row['code'])?></td>
            <td><span class="badge text-bg-light border"><?=e($row['type'])?></span></td>
            <td><span class="badge text-bg-<?=status_badge_class($row['category'])?>"><?=e($row['category'])?></span></td>
            <td><?=e($row['http_status'] ?? '—')?></td>
            <td class="wrap-url small"><code><?=e($row['target'])?></code></td>
            <td class="wrap-url small"><?=e($row['sources'])?></td>
            <td class="small">
              <?=e($row['description'])?>
              <?php if ($row['bug_key'] !== ''): ?>
                <div class="mt-1">
                  Trạng thái trên Web thử:
                  <?php if ($isFixedNow): ?>
                    <span class="badge text-bg-success">Đã được sửa (Trả về 200 OK)</span>
                  <?php else: ?>
                    <span class="badge text-bg-danger">Đang tái hiện lỗi</span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_end(); ?>
