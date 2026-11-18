<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_login();

$id = max(0, (int)($_GET['run_id'] ?? 0));
$st = $pdo->prepare('SELECT r.*, s.name, s.start_url, s.allowed_domains, s.max_pages, s.max_depth, s.request_timeout, s.max_duration, s.concurrency, s.allow_local FROM scan_runs r JOIN sites s ON s.id=r.site_id WHERE r.id=?');
$st->execute([$id]);
$run = $st->fetch();
if (!$run) {
    flash('Không tìm thấy phiên quét yêu cầu.', 'warning');
    redirect('history.php');
}

// Tìm phiên quét liền trước của cùng cấu hình/phạm vi để gợi ý nút So sánh nhanh (Tình huống 2)
$stPrev = $pdo->prepare("SELECT r.id FROM scan_runs r JOIN sites s ON s.id=r.site_id WHERE s.start_url=? AND r.id < ? ORDER BY r.id DESC LIMIT 1");
$stPrev->execute([$run['start_url'], $id]);
$prevRunId = (int)$stPrev->fetchColumn();

// Danh sách các trang nguồn đã duyệt trong phiên này (phục vụ bộ lọc theo Trang - Chức năng 4)
$stPagesList = $pdo->prepare('SELECT url, title, depth, http_status FROM pages WHERE run_id=? ORDER BY id ASC');
$stPagesList->execute([$id]);
$crawledPagesList = $stPagesList->fetchAll();

$filter = (string)($_GET['status'] ?? 'all');
$allowedStatuses = ['all', 'errors', 'ok', 'redirect', 'http_error', 'connection_error', 'missing_anchor', 'unknown', 'unchecked'];
if (!in_array($filter, $allowedStatuses, true)) {
    $filter = 'all';
}

$typeFilter = (string)($_GET['type'] ?? 'all');
if (!in_array($typeFilter, ['all', 'page', 'link', 'image', 'resource'], true)) {
    $typeFilter = 'all';
}

$sourceFilter = trim((string)($_GET['source_page'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 25;

$where = ' WHERE f.run_id=?';
$args = [$id];

if ($filter === 'errors') {
    $where .= " AND f.status_category IN ('http_error','connection_error','missing_anchor')";
} elseif ($filter !== 'all') {
    $where .= ' AND f.status_category=?';
    $args[] = $filter;
}

if ($typeFilter !== 'all') {
    $where .= ' AND f.resource_type=?';
    $args[] = $typeFilter;
}

if ($sourceFilter !== '') {
    $where .= ' AND l.source_url=?';
    $args[] = $sourceFilter;
}

if ($q !== '') {
    $where .= ' AND (f.normalized_url LIKE ? OR f.error_detail LIKE ? OR l.source_url LIKE ? OR l.tag_snippet LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like);
}

$joinSql = " FROM findings f LEFT JOIN links l ON l.run_id=f.run_id AND (l.normalized_url=f.normalized_url OR (l.base_target_url=f.base_target_url AND COALESCE(l.fragment,'')=COALESCE(f.fragment,'')))";

$countSt = $pdo->prepare('SELECT COUNT(DISTINCT f.id)' . $joinSql . $where);
$countSt->execute($args);
$total = (int)$countSt->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$offset = ($page - 1) * $per;

$sql = 'SELECT f.*' . $joinSql . $where
    . ' GROUP BY f.id ORDER BY CASE f.status_category'
    . ' WHEN "http_error" THEN 1 WHEN "missing_anchor" THEN 2 WHEN "connection_error" THEN 3'
    . ' WHEN "unknown" THEN 4 WHEN "unchecked" THEN 5 WHEN "redirect" THEN 6 ELSE 7 END, f.id ASC'
    . ' LIMIT ' . $per . ' OFFSET ' . $offset;
$st = $pdo->prepare($sql);
$st->execute($args);
$findings = $st->fetchAll();

// Lấy danh sách đầy đủ các vị trí trang nguồn (source_url, line_number, tag_snippet, anchor_text) cho các findings trên trang hiện tại
$sourcesByFinding = [];
if ($findings) {
    $stAllLinks = $pdo->prepare('SELECT source_url, raw_href, normalized_url, base_target_url, fragment, line_number, tag_snippet, anchor_text FROM links WHERE run_id=? ORDER BY line_number ASC');
    $stAllLinks->execute([$id]);
    $allLinks = $stAllLinks->fetchAll();
    foreach ($findings as $f) {
        $fid = (int)$f['id'];
        $sourcesByFinding[$fid] = [];
        foreach ($allLinks as $lnk) {
            if ($lnk['normalized_url'] === $f['normalized_url']
                || ($lnk['base_target_url'] === $f['base_target_url'] && (string)$lnk['fragment'] === (string)$f['fragment'])
            ) {
                $sourcesByFinding[$fid][] = $lnk;
            }
        }
    }
}

$counts = $pdo->prepare('SELECT status_category, COUNT(*) amount FROM findings WHERE run_id=? GROUP BY status_category');
$counts->execute([$id]);
$summary = [
    'ok'               => 0,
    'redirect'         => 0,
    'http_error'       => 0,
    'connection_error' => 0,
    'missing_anchor'   => 0,
    'unknown'          => 0,
    'unchecked'        => 0,
];
foreach ($counts->fetchAll() as $c) {
    if (isset($summary[$c['status_category']])) {
        $summary[$c['status_category']] = (int)$c['amount'];
    }
}
$errorCount = $summary['http_error'] + $summary['connection_error'] + $summary['missing_anchor'];

layout_start('Báo cáo phiên #' . $id); ?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
  <div>
    <div class="small-label mb-2">BÁO CÁO CHI TIẾT · CHỨC NĂNG 3 & 4</div>
    <h1 class="h3 page-title mb-1">Phiên kiểm tra #<?=e($run['id'])?> — <?=e($run['name'])?></h1>
    <div class="muted small">
      URL bắt đầu: <a href="<?=e($run['start_url'])?>" target="_blank" rel="noopener noreferrer" class="wrap-url"><?=e($run['start_url'])?></a>
      · Phạm vi miền: <code><?=e($run['allowed_domains'])?></code>
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
      <form method="post" action="scan.php" class="d-inline" data-scan-form="1">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <input type="hidden" name="site_id" value="<?=(int)$run['site_id']?>">
        <button class="btn btn-primary" type="submit" title="Quét lại với đúng phạm vi & cấu hình của phiên này (Phục vụ Tình huống 2)">↻ Chạy lại cùng phạm vi</button>
      </form>
    <?php endif; ?>
    <?php if ($prevRunId > 0): ?>
      <a class="btn btn-outline-primary" href="compare.php?a=<?=$prevRunId?>&b=<?=(int)$id?>">⇄ So sánh với phiên #<?=$prevRunId?></a>
    <?php endif; ?>
    <a class="btn btn-success" href="export.php?run_id=<?=(int)$id?>">↓ Xuất CSV</a>
    <a class="btn btn-outline-dark" href="pdf.php?run_id=<?=(int)$id?>" target="_blank" rel="noopener">🖨 Báo cáo PDF</a>
    <a class="btn btn-outline-secondary" href="history.php">← Lịch sử</a>
  </div>
</div>

<div class="row g-3 mb-4">
<?php foreach ([
    ['Trang HTML đã duyệt', (int)$run['total_pages_crawled'], '#eef2ff', '📄', 'Giới hạn: ' . (int)$run['max_pages'] . ' trang (sâu ' . (int)$run['max_depth'] . ')'],
    ['Tổng liên kết phát hiện', (int)$run['total_links_found'], '#e0f2fe', '↗', 'Lưu đầy đủ mọi trang nguồn & dòng'],
    ['Đích hoạt động (2xx)', $summary['ok'], '#dcfce7', '✓', 'Chuyển hướng: ' . $summary['redirect'] . ' mục'],
    ['Tổng mục lỗi cần sửa', $errorCount, '#fee2e2', '⚠', 'HTTP: ' . $summary['http_error'] . ' · Neo: ' . $summary['missing_anchor'] . ' · KN: ' . $summary['connection_error']]
] as $m): ?>
  <div class="col-6 col-xl-3">
    <div class="card p-3 h-100">
      <div class="d-flex justify-content-between align-items-start">
        <div class="muted small"><?=e($m[0])?></div>
        <span style="background:<?=e($m[2])?>;padding:7px 10px;border-radius:12px"><?=e($m[3])?></span>
      </div>
      <div class="metric mt-1"><?=number_format($m[1])?></div>
      <div class="small muted mt-2"><?=e($m[4])?></div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="card p-4 h-100">
      <h2 class="h5 fw-bold mb-3">Phân bố trạng thái các đích kiểm tra</h2>
      <div class="row align-items-center g-3">
        <div class="col-md-5">
          <div style="max-width:230px;margin:0 auto">
            <canvas id="statusChart" aria-label="Biểu đồ phân bố kết quả" role="img"></canvas>
          </div>
        </div>
        <div class="col-md-7">
          <?php
          $den = max(1, array_sum($summary));
          foreach ([
              ['Hoạt động tốt (ok)', $summary['ok'], 'success', 'ok'],
              ['Chuyển hướng (redirect)', $summary['redirect'], 'warning', 'redirect'],
              ['Lỗi HTTP / Ảnh hỏng (http_error)', $summary['http_error'], 'danger', 'http_error'],
              ['Thiếu mục neo #anchor (missing_anchor)', $summary['missing_anchor'], 'danger', 'missing_anchor'],
              ['Lỗi kết nối / DNS (connection_error)', $summary['connection_error'], 'danger', 'connection_error'],
              ['Chưa xác định / Timeout (unknown)', $summary['unknown'], 'info', 'unknown'],
              ['Chưa kiểm tra / Ngoài miền (unchecked)', $summary['unchecked'], 'secondary', 'unchecked'],
          ] as $m): ?>
            <div class="d-flex justify-content-between small mb-1">
              <a href="?run_id=<?=$id?>&status=<?=e($m[3])?>" class="text-decoration-none text-reset"><?=e($m[0])?></a>
              <strong><?=$m[1]?> (<?=round($m[1] * 100 / $den)?>%)</strong>
            </div>
            <div class="progress mb-2" style="height:7px">
              <div class="progress-bar bg-<?=$m[2]?>" style="width:<?=round($m[1] * 100 / $den)?>%"></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card p-4 h-100">
      <h2 class="h5 fw-bold mb-3">Thông số phiên & Phạm vi</h2>
      <div class="small">
        <div class="d-flex justify-content-between py-2 border-bottom"><span>Trạng thái phiên:</span><span class="badge text-bg-<?=$run['status']==='completed'?'success':'secondary'?>"><?=e($run['status'])?></span></div>
        <div class="d-flex justify-content-between py-2 border-bottom"><span>Thời điểm bắt đầu:</span><strong><?=e($run['started_at'])?></strong></div>
        <div class="d-flex justify-content-between py-2 border-bottom"><span>Thời điểm kết thúc:</span><strong><?=e($run['finished_at'] ?? '—')?></strong></div>
        <div class="d-flex justify-content-between py-2 border-bottom"><span>Lý do dừng:</span><strong><?=e($run['stop_reason'] ?? '—')?></strong></div>
        <div class="d-flex justify-content-between py-2 border-bottom"><span>Cấu hình giới hạn:</span><span><?=(int)$run['max_pages']?> trang · sâu <?=(int)$run['max_depth']?> · timeout <?=e($run['request_timeout'])?>s</span></div>
        <div class="d-flex justify-content-between pt-2"><span>Số luồng Multi-cURL:</span><strong><?=(int)($run['concurrency'] ?? 15)?> luồng song song</strong></div>
      </div>
    </div>
  </div>
</div>

<div class="card p-3 p-md-4">
  <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-3">
    <div>
      <h2 class="h5 fw-bold mb-1">Danh sách kết quả & Vị trí trong trang nguồn</h2>
      <p class="small muted mb-0">Lọc theo loại lỗi, trang nguồn hoặc loại tài nguyên. Chọn các mục cần kiểm tra lại để xác minh ngay sau khi sửa.</p>
    </div>
  </div>

  <form method="get" class="row g-2 mb-3 p-3 rounded-3 no-print" style="background:#f8fafc;border:1px solid #e2e8f0">
    <input type="hidden" name="run_id" value="<?=$id?>">
    <div class="col-md-3">
      <label class="form-label small fw-semibold mb-1">Từ khóa (URL, lỗi, thẻ HTML)</label>
      <input class="form-control form-control-sm" name="q" value="<?=e($q)?>" placeholder="Nhập URL, #anchor, 404...">
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-semibold mb-1">Lọc theo Trang nguồn (<?=count($crawledPagesList)?> trang)</label>
      <select class="form-select form-select-sm" name="source_page">
        <option value="">— Tất cả các trang nguồn —</option>
        <?php foreach ($crawledPagesList as $cp): ?>
          <option value="<?=e($cp['url'])?>" <?=$sourceFilter === $cp['url'] ? 'selected' : ''?>>
            <?=e($cp['url'])?><?=$cp['title'] ? ' (' . e(mb_substr($cp['title'], 0, 30)) . ')' : ''?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small fw-semibold mb-1">Loại tài nguyên</label>
      <select class="form-select form-select-sm" name="type">
        <option value="all" <?=$typeFilter==='all'?'selected':''?>>Mọi loại</option>
        <option value="link" <?=$typeFilter==='link'?'selected':''?>>Liên kết (&lt;a&gt;)</option>
        <option value="image" <?=$typeFilter==='image'?'selected':''?>>Hình ảnh (&lt;img&gt;)</option>
        <option value="resource" <?=$typeFilter==='resource'?'selected':''?>>Tài nguyên (CSS/JS)</option>
        <option value="page" <?=$typeFilter==='page'?'selected':''?>>Trang HTML</option>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small fw-semibold mb-1">Trạng thái / Lỗi</label>
      <select class="form-select form-select-sm" name="status">
        <option value="all" <?=$filter==='all'?'selected':''?>>Tất cả trạng thái</option>
        <option value="errors" <?=$filter==='errors'?'selected':''?>>⚠ Tất cả các lỗi (<?=$errorCount?>)</option>
        <option value="http_error" <?=$filter==='http_error'?'selected':''?>>Lỗi HTTP / Ảnh hỏng (<?=$summary['http_error']?>)</option>
        <option value="missing_anchor" <?=$filter==='missing_anchor'?'selected':''?>>Thiếu mục neo #anchor (<?=$summary['missing_anchor']?>)</option>
        <option value="connection_error" <?=$filter==='connection_error'?'selected':''?>>Lỗi kết nối / DNS (<?=$summary['connection_error']?>)</option>
        <option value="unknown" <?=$filter==='unknown'?'selected':''?>>Chưa xác định / Timeout (<?=$summary['unknown']?>)</option>
        <option value="unchecked" <?=$filter==='unchecked'?'selected':''?>>Bỏ qua / Chưa KT (<?=$summary['unchecked']?>)</option>
        <option value="redirect" <?=$filter==='redirect'?'selected':''?>>Chuyển hướng 3xx (<?=$summary['redirect']?>)</option>
        <option value="ok" <?=$filter==='ok'?'selected':''?>>Hoạt động tốt 2xx (<?=$summary['ok']?>)</option>
      </select>
    </div>
    <div class="col-md-2 d-flex align-items-end gap-1">
      <button class="btn btn-sm btn-primary flex-grow-1" type="submit">Lọc báo cáo</button>
      <a class="btn btn-sm btn-outline-secondary" href="report.php?run_id=<?=$id?>">Xóa lọc</a>
    </div>
  </form>

  <form method="post" action="retry.php" id="bulkRetryForm">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <input type="hidden" name="run_id" value="<?=$id?>">

    <?php if ((current_user()['role'] ?? '') === 'admin' && $findings): ?>
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2 no-print">
        <div class="form-check ms-1">
          <input class="form-check-input" type="checkbox" id="checkAllRows" onchange="toggleAllFindings(this)">
          <label class="form-check-label small fw-semibold" for="checkAllRows">Chọn tất cả các mục trên trang này</label>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button type="submit" name="retry_mode" value="inplace" class="btn btn-sm btn-outline-primary">↻ Kiểm tra lại các mục đã chọn (Cập nhật tại chỗ)</button>
          <button type="submit" name="retry_mode" value="new_run" class="btn btn-sm btn-outline-secondary">↻ Kiểm tra lại các mục đã chọn (Lưu phiên mới)</button>
        </div>
      </div>
    <?php endif; ?>

    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
              <th style="width:32px" class="no-print">✓</th>
            <?php endif; ?>
            <th style="width:24%">Địa chỉ đích (Normalized URL)</th>
            <th style="width:9%">Loại</th>
            <th style="width:7%">Mã HTTP</th>
            <th style="width:13%">Phân loại trạng thái</th>
            <th style="width:27%">Tất cả trang nguồn & Vị trí dòng HTML</th>
            <th style="width:20%">Chi tiết quan sát & Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$findings): ?>
            <tr><td colspan="7" class="text-center py-5 muted">Không có kết quả nào khớp với bộ lọc hiện tại.</td></tr>
          <?php endif; ?>
          <?php foreach ($findings as $f):
            $fid = (int)$f['id'];
            $cat = (string)$f['status_category'];
            $isGood = ($cat === 'ok');
            $isWarn = in_array($cat, ['redirect', 'unknown', 'unchecked'], true);
            $rowClass = $isGood ? 'status-row-ok' : ($isWarn ? 'status-row-warn' : 'status-row-error');
            $srcList = $sourcesByFinding[$fid] ?? [];
            $srcCount = count($srcList);
          ?>
            <tr class="<?=$rowClass?>">
              <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
                <td class="no-print">
                  <input class="form-check-input finding-checkbox" type="checkbox" name="finding_ids[]" value="<?=$fid?>">
                </td>
              <?php endif; ?>
              <td class="wrap-url">
                <a href="<?=e($f['normalized_url'])?>" target="_blank" rel="noopener noreferrer" class="fw-semibold text-decoration-none"><?=e($f['normalized_url'])?></a>
                <?php if (!empty($f['fragment'])): ?>
                  <div class="small text-danger fw-semibold mt-1">⚓ Mục neo: <code>#<?=e($f['fragment'])?></code></div>
                <?php endif; ?>
                <?php if (!empty($f['redirect_chain'])): ?>
                  <div class="small muted mt-1">🔀 Chuỗi chuyển hướng: <code><?=e($f['redirect_chain'])?></code></div>
                <?php endif; ?>
              </td>
              <td><span class="badge text-bg-light border"><?=e(resource_type_vi((string)$f['resource_type']))?></span></td>
              <td class="fw-bold"><?=e($f['http_status'] ?? '—')?></td>
              <td>
                <span class="badge text-bg-<?=status_badge_class($cat)?>"><?=e(status_label_vi($cat))?></span>
                <div class="small muted mt-1"><code><?=e($cat)?></code></div>
              </td>
              <td class="wrap-url small">
                <?php if (!$srcList): ?>
                  <span class="muted">Trang gốc bắt đầu (Depth 0)</span>
                <?php else: ?>
                  <div class="mb-1"><span class="badge text-bg-secondary"><?=$srcCount?> vị trí nguồn</span></div>
                  <?php foreach (array_slice($srcList, 0, 2) as $src): ?>
                    <div class="p-2 rounded mb-1" style="background:#ffffffc9;border:1px solid #e2e8f0">
                      <div class="d-flex justify-content-between align-items-center gap-1">
                        <a href="<?=e($src['source_url'])?>" target="_blank" rel="noopener noreferrer" class="fw-semibold text-decoration-none" title="Mở trang chứa liên kết này để sửa"><?=e($src['source_url'])?> ↗</a>
                        <span class="badge text-bg-dark">Dòng <?=(int)$src['line_number']?></span>
                      </div>
                      <div class="muted mt-1" style="font-size:.75rem">href gốc: <code><?=e($src['raw_href'])?></code></div>
                      <div class="mt-1"><code style="font-size:.74rem;color:#334155"><?=e($src['tag_snippet'])?></code></div>
                    </div>
                  <?php endforeach; ?>
                  <?php if ($srcCount > 2): ?>
                    <details class="mt-1">
                      <summary class="fw-semibold text-primary" style="cursor:pointer">Xem thêm <?=$srcCount - 2?> trang nguồn khác...</summary>
                      <div class="mt-1">
                        <?php foreach (array_slice($srcList, 2) as $src): ?>
                          <div class="p-2 rounded mb-1" style="background:#ffffffc9;border:1px solid #e2e8f0">
                            <div class="d-flex justify-content-between align-items-center gap-1">
                              <a href="<?=e($src['source_url'])?>" target="_blank" rel="noopener noreferrer" class="fw-semibold text-decoration-none"><?=e($src['source_url'])?> ↗</a>
                              <span class="badge text-bg-dark">Dòng <?=(int)$src['line_number']?></span>
                            </div>
                            <div class="mt-1"><code style="font-size:.74rem;color:#334155"><?=e($src['tag_snippet'])?></code></div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </details>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="small">
                <div class="fw-semibold mb-2"><?=e($f['error_detail'])?></div>
                <div class="d-flex flex-wrap gap-1 no-print">
                  <?php if ($srcList): ?>
                    <a href="<?=e($srcList[0]['source_url'])?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary" style="font-size:.75rem;padding:4px 8px">🛠 Đến trang nguồn sửa</a>
                  <?php endif; ?>
                  <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
                    <button type="submit" name="single_finding_id" value="<?=$fid?>" class="btn btn-sm btn-outline-secondary" style="font-size:.75rem;padding:4px 8px">↻ Kiểm tra lại</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
    <div class="small muted">Tổng <strong><?=number_format($total)?></strong> mục phát hiện · Trang <?=$page?>/<?=$pages?></div>
    <div class="d-flex gap-2">
      <?php
      $baseQuery = 'run_id=' . $id . '&status=' . urlencode($filter) . '&type=' . urlencode($typeFilter) . '&source_page=' . urlencode($sourceFilter) . '&q=' . urlencode($q);
      if ($page > 1): ?>
        <a class="btn btn-sm btn-outline-secondary" href="?<?=$baseQuery?>&page=<?=$page - 1?>">← Trang trước</a>
      <?php endif; ?>
      <?php if ($page < $pages): ?>
        <a class="btn btn-sm btn-outline-secondary" href="?<?=$baseQuery?>&page=<?=$page + 1?>">Trang tiếp →</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
function toggleAllFindings(master) {
  document.querySelectorAll('.finding-checkbox').forEach(cb => { cb.checked = master.checked; });
}
const chartNode = document.getElementById('statusChart');
if (chartNode && window.Chart) {
  new Chart(chartNode, {
    type: 'doughnut',
    data: {
      labels: ['Hoạt động (2xx)', 'Chuyển hướng (3xx)', 'Lỗi HTTP', 'Thiếu #anchor', 'Lỗi kết nối', 'Chưa xác định (Timeout)', 'Bỏ qua'],
      datasets: [{
        data: [
          <?=(int)$summary['ok']?>,
          <?=(int)$summary['redirect']?>,
          <?=(int)$summary['http_error']?>,
          <?=(int)$summary['missing_anchor']?>,
          <?=(int)$summary['connection_error']?>,
          <?=(int)$summary['unknown']?>,
          <?=(int)$summary['unchecked']?>
        ],
        backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#dc2626', '#991b1b', '#0ea5e9', '#94a3b8']
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } }
    }
  });
}
</script>
<?php layout_end(); ?>
