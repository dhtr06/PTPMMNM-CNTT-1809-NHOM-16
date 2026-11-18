<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_login();

$runs = $pdo->query("SELECT r.id, r.site_id, r.started_at, r.status, r.stop_reason, r.total_pages_crawled, r.total_links_found, r.total_errors, r.total_unchecked, s.name, s.start_url, s.allowed_domains, s.max_pages, s.max_depth, s.request_timeout, s.max_duration FROM scan_runs r JOIN sites s ON s.id=r.site_id ORDER BY r.id DESC")->fetchAll();

$a = max(0, (int)($_GET['a'] ?? 0));
$b = max(0, (int)($_GET['b'] ?? 0));

// Nếu người dùng mở trang So sánh mà chưa chọn a, b thì tự động gợi ý cặp 2 phiên gần nhất có cùng phạm vi
if ($a === 0 && $b === 0 && count($runs) >= 2) {
    for ($i = 0; $i < count($runs); $i++) {
        for ($j = $i + 1; $j < count($runs); $j++) {
            $rNew = $runs[$i];
            $rOld = $runs[$j];
            if ($rNew['start_url'] === $rOld['start_url']
                && trim((string)$rNew['allowed_domains']) === trim((string)$rOld['allowed_domains'])
                && (int)$rNew['max_pages'] === (int)$rOld['max_pages']
                && (int)$rNew['max_depth'] === (int)$rOld['max_depth']
            ) {
                $a = (int)$rOld['id'];
                $b = (int)$rNew['id'];
                break 2;
            }
        }
    }
}

$old = [];
$new = [];
$newErrors = [];
$resolved = [];
$persisting = [];
$unknown = [];
$err = '';
$scopeA = null;
$scopeB = null;

if ($a && $b) {
    if ($a === $b) {
        $err = 'Hãy chọn hai phiên kiểm tra khác nhau để đối chiếu.';
    } else {
        foreach ($runs as $rr) {
            if ((int)$rr['id'] === $a) {
                $scopeA = $rr;
            }
            if ((int)$rr['id'] === $b) {
                $scopeB = $rr;
            }
        }
        if (!$scopeA || !$scopeB) {
            $err = 'Không tìm thấy một trong hai phiên kiểm tra.';
        } elseif (
            $scopeA['start_url'] !== $scopeB['start_url']
            || strtolower(trim((string)$scopeA['allowed_domains'])) !== strtolower(trim((string)$scopeB['allowed_domains']))
            || (int)$scopeA['max_pages'] !== (int)$scopeB['max_pages']
            || (int)$scopeA['max_depth'] !== (int)$scopeB['max_depth']
        ) {
            $err = 'Không thể so sánh: Hai phiên thuộc hai phạm vi/website khác nhau (khác URL bắt đầu, danh sách miền cho phép hoặc giới hạn số trang/độ sâu). Hãy chọn hai phiên có cùng phạm vi để kết quả đối chiếu chính xác.';
        } else {
            $sqlFindings = "SELECT f.*, COALESCE(GROUP_CONCAT(DISTINCT CONCAT(l.source_url, ' (dòng ', l.line_number, ')') SEPARATOR ' | '), '') AS source_pages, COALESCE(MAX(l.tag_snippet), '') AS tag_snippet FROM findings f LEFT JOIN links l ON l.run_id=f.run_id AND (l.normalized_url=f.normalized_url OR (l.base_target_url=f.base_target_url AND COALESCE(l.fragment,'')=COALESCE(f.fragment,''))) WHERE f.run_id=? GROUP BY f.id";
            $q = $pdo->prepare($sqlFindings);

            $q->execute([$a]);
            foreach ($q->fetchAll() as $f) {
                $old[$f['normalized_url']] = $f;
            }

            $q->execute([$b]);
            foreach ($q->fetchAll() as $f) {
                $new[$f['normalized_url']] = $f;
            }

            // Lấy danh sách trang đã crawl ở phiên B để biết liên kết lỗi ở phiên A đã được sửa/xóa khỏi trang nguồn hay chưa
            $stPagesB = $pdo->prepare('SELECT url FROM pages WHERE run_id=? AND http_status >= 200 AND http_status < 400');
            $stPagesB->execute([$b]);
            $crawledPagesB = array_flip($stPagesB->fetchAll(PDO::FETCH_COLUMN));

            $stLinksA = $pdo->prepare('SELECT normalized_url, source_url FROM links WHERE run_id=?');
            $stLinksA->execute([$a]);
            $sourcesInA = [];
            foreach ($stLinksA->fetchAll() as $la) {
                $sourcesInA[$la['normalized_url']][] = $la['source_url'];
            }

            if (!$old && !$new) {
                $err = 'Cả hai phiên chưa có dữ liệu phát hiện để so sánh.';
            } else {
                $errorCats = ['http_error', 'connection_error', 'missing_anchor'];

                foreach ($new as $url => $f) {
                    if (in_array($f['status_category'], ['unknown', 'unchecked'], true)) {
                        $unknown[$url] = $f;
                    }
                    if (in_array($f['status_category'], $errorCats, true)) {
                        if (!isset($old[$url]) || !in_array($old[$url]['status_category'], $errorCats, true)) {
                            $newErrors[$url] = $f;
                        } else {
                            $persisting[$url] = $f;
                        }
                    }
                }

                foreach ($old as $url => $fOld) {
                    if (!in_array($fOld['status_category'], $errorCats, true)) {
                        continue;
                    }
                    if (isset($new[$url])) {
                        if (in_array($new[$url]['status_category'], ['ok', 'redirect'], true)) {
                            $resolvedItem = $new[$url];
                            $resolvedItem['old_status'] = $fOld['status_category'];
                            $resolvedItem['old_detail'] = $fOld['error_detail'];
                            if ($resolvedItem['source_pages'] === '') {
                                $resolvedItem['source_pages'] = $fOld['source_pages'];
                            }
                            if ($resolvedItem['tag_snippet'] === '') {
                                $resolvedItem['tag_snippet'] = $fOld['tag_snippet'];
                            }
                            $resolved[$url] = $resolvedItem;
                        }
                    } else {
                        // Liên kết lỗi ở phiên cũ không còn xuất hiện ở phiên mới: kiểm tra xem trang nguồn chứa nó đã được quét lại ở phiên B chưa
                        $srcUrls = $sourcesInA[$url] ?? [];
                        $sourceReCrawled = false;
                        foreach ($srcUrls as $su) {
                            if (isset($crawledPagesB[$su])) {
                                $sourceReCrawled = true;
                                break;
                            }
                        }
                        if ($sourceReCrawled) {
                            $resolvedItem = $fOld;
                            $resolvedItem['old_status'] = $fOld['status_category'];
                            $resolvedItem['old_detail'] = $fOld['error_detail'];
                            $resolvedItem['status_category'] = 'ok';
                            $resolvedItem['error_detail'] = 'Đã sửa trên trang nguồn (Liên kết hỏng đã được thay thế hoặc gỡ bỏ)';
                            $resolved[$url] = $resolvedItem;
                        }
                    }
                }
            }
        }
    }
}

layout_start('So sánh hai lần kiểm tra'); ?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
  <div>
    <div class="small-label mb-2">CHỨC NĂNG 5 · SCAN DIFF & REGRESSION REPORT</div>
    <h1 class="h3 page-title mb-1">So sánh hai lần kiểm tra cùng phạm vi</h1>
    <p class="muted mb-0">Chỉ rõ lỗi đã hết (sau khi sửa website), lỗi mới phát sinh, lỗi còn tồn đọng và mục chưa kiểm tra kèm vị trí trong trang nguồn.</p>
  </div>
  <?php if ($err === '' && $a && $b): ?>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-success" href="export.php?compare_a=<?=$a?>&compare_b=<?=$b?>">↓ Xuất CSV So sánh & Lỗi còn lại</a>
      <a class="btn btn-outline-dark" href="pdf.php?compare_a=<?=$a?>&compare_b=<?=$b?>" target="_blank" rel="noopener">🖨 Xuất PDF So sánh</a>
    </div>
  <?php endif; ?>
</div>

<div class="card p-4 mb-4">
  <form method="get" class="row g-3 align-items-end">
    <div class="col-md-5">
      <label class="form-label fw-semibold">Phiên kiểm tra trước (Cũ — Trước khi sửa)</label>
      <select class="form-select" name="a" required>
        <option value="">— Chọn phiên cũ —</option>
        <?php foreach ($runs as $r): ?>
          <option value="<?=$r['id']?>" <?=$a === (int)$r['id'] ? 'selected' : ''?>>
            #<?=$r['id']?> · <?=e($r['name'])?> (<?=e($r['started_at'])?> · <?=(int)$r['total_errors']?> lỗi)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-5">
      <label class="form-label fw-semibold">Phiên kiểm tra sau (Mới — Sau khi sửa)</label>
      <select class="form-select" name="b" required>
        <option value="">— Chọn phiên mới —</option>
        <?php foreach ($runs as $r): ?>
          <option value="<?=$r['id']?>" <?=$b === (int)$r['id'] ? 'selected' : ''?>>
            #<?=$r['id']?> · <?=e($r['name'])?> (<?=e($r['started_at'])?> · <?=(int)$r['total_errors']?> lỗi)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <button class="btn btn-primary w-100" type="submit">⇄ Đối chiếu</button>
    </div>
  </form>
</div>

<?php if ($err !== ''): ?>
  <div class="alert alert-warning shadow-sm">
    <strong>⚠ Không thể đối chiếu:</strong> <?=e($err)?>
  </div>
<?php elseif ($a && $b && $scopeA && $scopeB): ?>
  <div class="card p-3 mb-4" style="background:#f8fafc">
    <div class="row g-2 small">
      <div class="col-md-6">
        <strong>Phiên cũ #<?=$a?>:</strong> <?=e($scopeA['started_at'])?> · <?=(int)$scopeA['total_pages_crawled']?> trang · <?=(int)$scopeA['total_errors']?> lỗi · <a href="report.php?run_id=<?=$a?>">Xem báo cáo #<?=$a?></a>
      </div>
      <div class="col-md-6">
        <strong>Phiên mới #<?=$b?>:</strong> <?=e($scopeB['started_at'])?> · <?=(int)$scopeB['total_pages_crawled']?> trang · <?=(int)$scopeB['total_errors']?> lỗi · <a href="report.php?run_id=<?=$b?>">Xem báo cáo #<?=$b?></a>
      </div>
      <div class="col-12 muted">
        <strong>Phạm vi đồng nhất:</strong> URL bắt đầu <code><?=e($scopeB['start_url'])?></code> · Miền cho phép: <code><?=e($scopeB['allowed_domains'])?></code> · Giới hạn <?=(int)$scopeB['max_pages']?> trang, độ sâu <?=(int)$scopeB['max_depth']?>.
      </div>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <?php foreach ([
        ['Lỗi đã hết (Đã khắc phục)', count($resolved), 'success', '✓ Các liên kết hỏng ở phiên #' . $a . ' đã hoạt động hoặc được sửa'],
        ['Lỗi mới phát sinh', count($newErrors), 'danger', '⚠ Mới xuất hiện ở phiên #' . $b],
        ['Lỗi còn tồn đọng', count($persisting), 'danger', 'Cần tiếp tục sửa trên trang nguồn'],
        ['Chưa kiểm tra / Chưa xác định', count($unknown), 'secondary', 'Timeout (unknown) hoặc ngoài miền / vượt giới hạn (unchecked)'],
    ] as $m): ?>
      <div class="col-6 col-xl-3">
        <div class="card p-3 h-100">
          <div class="muted small"><?=e($m[0])?></div>
          <div class="metric text-<?=$m[2]?> my-1"><?=number_format($m[1])?></div>
          <div class="small muted"><?=e($m[3])?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php
  $sections = [
      ['✓ Lỗi đã hết (Đã khắc phục thành công)', $resolved, 'success', true],
      ['⚠ Lỗi mới phát sinh ở phiên #' . $b, $newErrors, 'danger', false],
      ['❗ Lỗi còn lại (Cần tiếp tục xử lý)', $persisting, 'danger', false],
      ['⏳ Mục chưa kiểm tra / Chưa xác định (Timeout hoặc Ngoài phạm vi)', $unknown, 'secondary', false],
  ];
  foreach ($sections as [$secTitle, $items, $badgeColor, $isResolvedSec]): ?>
    <div class="card p-4 mb-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 fw-bold mb-0"><?=e($secTitle)?></h2>
        <span class="badge text-bg-<?=$badgeColor?>"><?=count($items)?> mục</span>
      </div>
      <?php if (!$items): ?>
        <p class="muted mb-0 small">Không có mục nào trong nhóm này.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th style="width:26%">Địa chỉ đích (URL)</th>
                <th style="width:10%">Loại</th>
                <th style="width:16%">Trạng thái đối chiếu</th>
                <th style="width:26%">Vị trí liên kết trong trang nguồn</th>
                <th style="width:22%">Chi tiết ghi nhận</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $url => $f): ?>
                <tr>
                  <td class="wrap-url">
                    <a href="<?=e($url)?>" target="_blank" rel="noopener noreferrer" class="fw-semibold text-decoration-none"><?=e($url)?></a>
                  </td>
                  <td><span class="badge text-bg-light border"><?=e(resource_type_vi((string)($f['resource_type'] ?? 'link')))?></span></td>
                  <td>
                    <?php if ($isResolvedSec): ?>
                      <span class="badge text-bg-danger text-decoration-line-through me-1"><?=e($f['old_status'] ?? 'error')?></span>
                      → <span class="badge text-bg-success"><?=e(status_label_vi((string)$f['status_category']))?></span>
                    <?php else: ?>
                      <span class="badge text-bg-<?=status_badge_class((string)$f['status_category'])?>"><?=e(status_label_vi((string)$f['status_category']))?></span>
                      <div class="small muted mt-1"><code><?=e($f['status_category'])?></code></div>
                    <?php endif; ?>
                  </td>
                  <td class="wrap-url small">
                    <?php if (!empty($f['source_pages'])): ?>
                      <div><?=e($f['source_pages'])?></div>
                    <?php else: ?>
                      <span class="muted">Trang gốc (Depth 0)</span>
                    <?php endif; ?>
                    <?php if (!empty($f['tag_snippet'])): ?>
                      <div class="mt-1"><code style="font-size:.74rem"><?=e($f['tag_snippet'])?></code></div>
                    <?php endif; ?>
                  </td>
                  <td class="small">
                    <?php if ($isResolvedSec && !empty($f['old_detail'])): ?>
                      <div class="muted mb-1">Trước khi sửa: <?=e($f['old_detail'])?></div>
                    <?php endif; ?>
                    <div class="fw-semibold"><?=e($f['error_detail'])?></div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php layout_end(); ?>
