<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    $currentSavePath = session_save_path();
    if ($currentSavePath === '' || !is_dir($currentSavePath) || !is_writable($currentSavePath)) {
        session_save_path(sys_get_temp_dir());
    }
    @session_start();
}
date_default_timezone_set('Asia/Ho_Chi_Minh');

$DB_HOST = getenv('LINKSCOPE_DB_HOST') ?: 'localhost';
$DB_NAME = getenv('LINKSCOPE_DB_NAME') ?: 'broken_link_checker';
$DB_USER = getenv('LINKSCOPE_DB_USER') ?: 'root';
$DB_PASS = getenv('LINKSCOPE_DB_PASS') !== false ? (string)getenv('LINKSCOPE_DB_PASS') : '';
$SQLITE_FILE = getenv('LINKSCOPE_SQLITE_PATH') ?: '';

function ls_init_schema(PDO $pdo, bool $isSqlite = false): void {
    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'viewer',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("INSERT OR IGNORE INTO users(username,password_hash,role) VALUES
            ('admin','\$2y\$12\$43mZR8SWB6qQg42EgREPhO/XbqbDeCG2lO4ZwxXrMMYr7hjQxDOaS','admin'),
            ('viewer','\$2y\$10\$s4Uvh65SUVsoeSJpR6oVLeSCK.K15KHRCHIUTc9BOB3OyX2O.nc9y','viewer')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            start_url TEXT NOT NULL,
            allowed_domains TEXT NOT NULL,
            max_pages INTEGER DEFAULT 50,
            max_depth INTEGER DEFAULT 3,
            request_timeout REAL DEFAULT 4.00,
            max_duration REAL DEFAULT 60.00,
            concurrency INTEGER DEFAULT 15,
            allow_subdomains INTEGER DEFAULT 1,
            check_external_assets INTEGER DEFAULT 0,
            allow_local INTEGER DEFAULT 0,
            created_at TEXT NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS scan_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL,
            started_at TEXT NOT NULL,
            finished_at TEXT NULL,
            status TEXT NOT NULL,
            stop_reason TEXT NULL,
            total_pages_crawled INTEGER DEFAULT 0,
            total_links_found INTEGER DEFAULT 0,
            total_errors INTEGER DEFAULT 0,
            total_unchecked INTEGER DEFAULT 0
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            url TEXT NOT NULL,
            depth INTEGER NOT NULL,
            http_status INTEGER NULL,
            title TEXT NULL,
            anchors_list TEXT NULL,
            crawled_at TEXT NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            source_page_id INTEGER NOT NULL,
            source_url TEXT NOT NULL,
            raw_href TEXT NOT NULL,
            normalized_url TEXT NOT NULL,
            base_target_url TEXT NOT NULL,
            fragment TEXT NULL,
            resource_type TEXT NOT NULL,
            line_number INTEGER NOT NULL,
            tag_snippet TEXT NOT NULL,
            anchor_text TEXT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS findings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            normalized_url TEXT NOT NULL,
            base_target_url TEXT NOT NULL,
            fragment TEXT NULL,
            resource_type TEXT NOT NULL,
            status_category TEXT NOT NULL,
            http_status INTEGER NULL,
            redirect_chain TEXT NULL,
            error_detail TEXT NOT NULL,
            checked_at TEXT NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS scan_schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL,
            interval_minutes INTEGER NOT NULL DEFAULT 1440,
            enabled INTEGER NOT NULL DEFAULT 1,
            next_run_at TEXT NULL,
            last_run_at TEXT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            message TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_read INTEGER NOT NULL DEFAULT 0
        )");
        return;
    }

    // MySQL session optimization for large websites with many source links per finding
    @$pdo->exec("SET SESSION group_concat_max_len = 1048576");

    // Auto-create tables if missing
    $sqlFile = __DIR__ . '/database.sql';
    $hasSites = false;
    try {
        $chk = $pdo->query("SHOW TABLES LIKE 'sites'");
        $hasSites = (bool)$chk->fetchColumn();
    } catch (Throwable $e) {
        $hasSites = false;
    }

    if (!$hasSites && is_file($sqlFile)) {
        $rawSql = (string)file_get_contents($sqlFile);
        foreach (explode(';', $rawSql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || stripos($stmt, 'CREATE DATABASE') === 0 || stripos($stmt, 'USE ') === 0) {
                continue;
            }
            try {
                $pdo->exec($stmt);
            } catch (Throwable $e) {
                // Ignore harmless migration statement warnings
            }
        }
    }

    // Ensure new columns exist on existing MySQL installations (seamless upgrade for large web scanning)
    if (empty($_SESSION['_schema_migrated_v3'])) {
        $alterStatements = [
            "ALTER TABLE sites ADD COLUMN concurrency INT DEFAULT 15",
            "ALTER TABLE sites ADD COLUMN allow_subdomains TINYINT(1) DEFAULT 1",
            "ALTER TABLE sites ADD COLUMN check_external_assets TINYINT(1) DEFAULT 0",
            "ALTER TABLE sites MODIFY COLUMN start_url VARCHAR(1000) NOT NULL",
            "ALTER TABLE sites MODIFY COLUMN allowed_domains VARCHAR(1000) NOT NULL",
            "ALTER TABLE sites MODIFY COLUMN max_duration DECIMAL(7,2) DEFAULT 60.00",
            "ALTER TABLE pages MODIFY COLUMN url VARCHAR(1000) NOT NULL",
            "ALTER TABLE pages MODIFY COLUMN title VARCHAR(500) NULL",
            "ALTER TABLE links MODIFY COLUMN source_url VARCHAR(1000) NOT NULL",
            "ALTER TABLE links MODIFY COLUMN raw_href VARCHAR(1000) NOT NULL",
            "ALTER TABLE links MODIFY COLUMN normalized_url VARCHAR(1000) NOT NULL",
            "ALTER TABLE links MODIFY COLUMN base_target_url VARCHAR(1000) NOT NULL",
            "ALTER TABLE links MODIFY COLUMN fragment VARCHAR(255) NULL",
            "ALTER TABLE links MODIFY COLUMN tag_snippet VARCHAR(500) NOT NULL",
            "ALTER TABLE links MODIFY COLUMN anchor_text VARCHAR(500) NULL",
            "ALTER TABLE findings MODIFY COLUMN normalized_url VARCHAR(1000) NOT NULL",
            "ALTER TABLE findings MODIFY COLUMN base_target_url VARCHAR(1000) NOT NULL",
            "ALTER TABLE findings MODIFY COLUMN fragment VARCHAR(255) NULL",
            "ALTER TABLE findings MODIFY COLUMN redirect_chain VARCHAR(1500) NULL",
            "ALTER TABLE findings MODIFY COLUMN error_detail VARCHAR(1000) NOT NULL",
            "INSERT IGNORE INTO users(username,password_hash,role) VALUES ('admin','\$2y\$12\$43mZR8SWB6qQg42EgREPhO/XbqbDeCG2lO4ZwxXrMMYr7hjQxDOaS','admin')",
            "INSERT IGNORE INTO users(username,password_hash,role) VALUES ('viewer','\$2y\$10\$s4Uvh65SUVsoeSJpR6oVLeSCK.K15KHRCHIUTc9BOB3OyX2O.nc9y','viewer')"
        ];
        foreach ($alterStatements as $sql) {
            try {
                @$pdo->exec($sql);
            } catch (Throwable $e) {
                // Column already exists or compatible
            }
        }
        $_SESSION['_schema_migrated_v3'] = true;
    }
}

try {
    if ($SQLITE_FILE !== '') {
        $pdo = new PDO('sqlite:' . $SQLITE_FILE, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        ls_init_schema($pdo, true);
    } else {
        try {
            $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $dbMissingEx) {
            // Attempt automatic database creation if MySQL is running but database wasn't created yet
            $pdoRoot = new PDO("mysql:host={$DB_HOST};charset=utf8mb4", $DB_USER, $DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `{$DB_NAME}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdoRoot = null;
            $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        ls_init_schema($pdo, false);
    }
} catch (PDOException $e) {
    http_response_code(500);
    exit('Không kết nối được MySQL. Hãy bật MySQL trong XAMPP Control Panel và kiểm tra config.php. Chi tiết: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}
function flash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}
function show_flash(): void {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="alert alert-' . e($f['type']) . ' alert-dismissible fade show shadow-sm" role="alert">'
            . e($f['message'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Đóng"></button></div>';
    }
}
function current_user(): ?array {
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}
function require_login(): void {
    if (!current_user()) {
        redirect('login.php');
    }
}
function require_admin(): void {
    if (defined('LINKSCOPE_WORKER') && LINKSCOPE_WORKER === true) {
        return;
    }
    require_login();
    if ((current_user()['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Bạn không có quyền thực hiện chức năng này (yêu cầu quyền Admin).');
    }
}
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function verify_csrf(): void {
    if (defined('LINKSCOPE_WORKER') && LINKSCOPE_WORKER === true) {
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('CSRF token không hợp lệ. Hãy tải lại trang và thử lại.');
    }
}

function status_label_vi(string $cat): string {
    return match ($cat) {
        'ok'               => 'Hoạt động (2xx)',
        'redirect'         => 'Chuyển hướng (3xx)',
        'http_error'       => 'Lỗi HTTP (4xx/5xx)',
        'connection_error' => 'Lỗi kết nối / DNS',
        'missing_anchor'   => 'Thiếu mục neo (#anchor)',
        'unknown'          => 'Chưa xác định (Timeout)',
        'unchecked'        => 'Chưa kiểm tra / Ngoài phạm vi',
        default            => $cat,
    };
}

function status_badge_class(string $cat): string {
    return match ($cat) {
        'ok'               => 'success',
        'redirect'         => 'warning',
        'http_error',
        'connection_error',
        'missing_anchor'   => 'danger',
        'unknown'          => 'info',
        'unchecked'        => 'secondary',
        default            => 'secondary',
    };
}

function resource_type_vi(string $type): string {
    return match ($type) {
        'page'     => 'Trang HTML',
        'link'     => 'Liên kết (<a>)',
        'image'    => 'Hình ảnh (<img>)',
        'resource' => 'Tài nguyên (CSS/JS/Media)',
        default    => $type,
    };
}

function get_testbed_start_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/broken_link_checker_php/index.php')), '/');
    return $scheme . '://' . $host . $scriptDir . '/testbed/index.php';
}

function layout_start(string $title): void {
    global $pdo;
    $unreadCount = 0;
    if (current_user() && isset($pdo)) {
        try {
            $unreadCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn();
        } catch (Throwable $e) {
            $unreadCount = 0;
        }
    }
    $currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
?><!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=e($title)?> · LinkScope Pro</title><meta name="theme-color" content="#101828">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root{--ink:#172033;--muted:#64748b;--line:#e6eaf2;--primary:#635bff;--primary-dark:#4f46e5;--bg:#f4f6fb;--success:#15966a;--danger:#dc4c64;--navy:#111827}
*{box-sizing:border-box}body{background:radial-gradient(ellipse at 8% 0%,#e9eaff 0,transparent 34%),var(--bg);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;font-size:15px;line-height:1.55}
.navbar{background:rgba(17,24,39,.98)!important;box-shadow:0 10px 30px #10182820;padding:.75rem 0;backdrop-filter:blur(16px)}.navbar .container{max-width:1320px}.navbar-brand{font-weight:850;letter-spacing:-.045em;font-size:1.22rem;display:flex;align-items:center}.brand-mark{display:inline-grid;place-items:center;width:36px;height:36px;background:linear-gradient(145deg,#817aff,#4f46e5);box-shadow:0 5px 15px #635bff55;border-radius:12px;margin-right:10px;color:#fff;font-size:1.25rem}.nav-link{color:#cbd5e1!important;font-size:.89rem;font-weight:600;padding:.55rem .8rem!important;border-radius:10px;transition:.2s;white-space:nowrap}.nav-link:hover,.nav-link.active{color:#fff!important;background:#ffffff16}
main.container{max-width:1320px}.page-title{font-weight:850;letter-spacing:-.045em;line-height:1.15}.muted{color:var(--muted)}.card{background:rgba(255,255,255,.96);border:1px solid #e9edf5;border-radius:20px;box-shadow:0 8px 30px #202c4b08;transition:box-shadow .2s,border-color .2s}.card:hover{border-color:#dfe4f3;box-shadow:0 12px 34px #202c4b0d}.metric{font-size:clamp(1.7rem,2.6vw,2.3rem);font-weight:850;letter-spacing:-.055em;line-height:1.15}.metric-icon{display:grid;place-items:center;width:46px;height:46px;border-radius:15px;background:linear-gradient(145deg,#f0efff,#e5e7ff);color:#5148e5;font-size:1.25rem}.form-label{font-size:.88rem;color:#303b50}.form-control,.form-select{border:1px solid #dfe4ee;border-radius:12px;padding:.68rem .88rem;background-color:#fff;min-height:44px}.form-control::placeholder{color:#a3acbc}.form-control:focus,.form-select:focus{border-color:#8882ff;box-shadow:0 0 0 .23rem #635bff19}.form-text{color:#64748b;font-size:.79rem}.btn{border-radius:11px;font-weight:700;padding:.62rem .98rem;transition:transform .15s,box-shadow .15s,background .15s}.btn:hover{transform:translateY(-1px)}.btn-primary{background:linear-gradient(135deg,#716aff,#5148e5);border-color:transparent;box-shadow:0 6px 14px #635bff28}.btn-primary:hover,.btn-primary:focus{background:linear-gradient(135deg,#625af2,#4338ca);border-color:transparent;box-shadow:0 8px 20px #635bff35}.btn-outline-secondary,.btn-outline-primary{border-color:#dfe4ee}.btn-outline-primary{color:#5148e5}.btn-outline-primary:hover{background:#f0efff;color:#4338ca;border-color:#d6d2ff}.table{--bs-table-bg:transparent;--bs-table-hover-bg:#f7f8ff}.table thead th{font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:#64748b;background:#f8f9fd;white-space:nowrap;border-bottom:1px solid #e9edf5}.table td,.table th{vertical-align:middle;padding:.85rem .75rem;border-color:#edf0f6}.table tbody tr:last-child td{border-bottom:0}.wrap-url{max-width:420px;overflow-wrap:anywhere;word-break:break-word;color:#475569}.status-row-error{background:#fff8f8}.status-row-ok{background:#f4fcf8}.status-row-warn{background:#fffdf4}.pill{border-radius:999px;padding:.38rem .68rem;font-size:.75rem;font-weight:750}.badge{font-weight:700;letter-spacing:.01em;padding:.42em .68em}.hero{position:relative;overflow:hidden;background:linear-gradient(120deg,#111827 0%,#1e1b4b 55%,#4338ca 100%);color:#fff;border-radius:24px;padding:30px 34px;box-shadow:0 16px 38px #24265a25}.hero:after{content:"";position:absolute;width:270px;height:270px;border:1px solid #ffffff14;border-radius:50%;right:-60px;top:-120px;box-shadow:0 0 0 35px #ffffff08,0 0 0 70px #ffffff05;pointer-events:none}.hero>*{position:relative;z-index:1}.hero p{color:#cbd5e1;max-width:760px}.small-label{font-size:.74rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#818cf8}.progress{height:9px;border-radius:20px;background:#edf0f7}.progress-bar{border-radius:20px;background:linear-gradient(90deg,#7770ff,#4f46e5)}.alert{border:0;border-radius:14px}.footer{color:#64748b}.print-only{display:none}
.text-bg-success{background-color:#dcfce7!important;color:#065f46!important}.text-bg-danger{background-color:#ffe4e6!important;color:#9f1239!important}.text-bg-warning{background-color:#fef3c7!important;color:#92400e!important}.text-bg-info{background-color:#e0f2fe!important;color:#075985!important}.text-bg-secondary{background-color:#f1f5f9!important;color:#475569!important}
.preset-btn{font-size:.8rem;padding:.42rem .78rem;border-radius:999px;font-weight:600}
.source-chip{display:inline-block;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:3px 8px;margin:2px 2px;font-size:.78rem;color:#334155}
#scanOverlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.72);backdrop-filter:blur(6px);z-index:9999;place-items:center}
@media print{body{background:#fff!important;font-size:10pt}.navbar,.footer,.no-print,form,.btn,button{display:none!important}main.container{max-width:none!important;padding:0!important}.card{box-shadow:none!important;border:1px solid #ddd!important;break-inside:avoid}.hero{background:#fff!important;color:#111!important;box-shadow:none!important;padding:0!important}.hero p{color:#444!important}.print-only{display:block!important}a{color:#111!important;text-decoration:none!important}.table{font-size:9pt}.table td,.table th{padding:5px!important}}
@media(max-width:767px){.hero{padding:22px;border-radius:20px}.card{border-radius:17px}.navbar{padding:.6rem 0}.nav-link{margin-top:3px}.table td,.table th{padding:.7rem .55rem}.wrap-url{min-width:170px}}
</style></head><body>
<div id="scanOverlay"><div class="card p-4 p-md-5 text-center shadow-lg" style="max-width:480px;width:92%"><div class="spinner-border text-primary mx-auto mb-3" style="width:3rem;height:3rem" role="status"></div><h3 class="h5 fw-bold mb-2">Đang quét & kiểm tra liên kết đa luồng...</h3><p class="muted small mb-3">Hệ thống đang duyệt BFS và gửi yêu cầu song song bằng Multi-cURL. Với website lớn có hàng trăm liên kết, quá trình này có thể mất từ vài giây đến vài chục giây.</p><div class="badge text-bg-info px-3 py-2">Thời gian đã chạy: <span id="scanTimer">0</span> giây</div></div></div>
<nav class="navbar navbar-expand-xl navbar-dark mb-4"><div class="container"><a class="navbar-brand" href="index.php"><span class="brand-mark">↗</span>LinkScope<span class="badge text-bg-primary ms-2" style="font-size:.65rem">PRO v3.0</span></a><button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Mở menu"><span class="navbar-toggler-icon"></span></button><div class="collapse navbar-collapse" id="mainNav"><?php if (current_user()): ?><div class="navbar-nav ms-auto align-items-xl-center gap-1"><a class="nav-link <?=$currentPage==='index.php'?'active':''?>" href="index.php">Tổng quan</a><a class="nav-link <?=$currentPage==='preview.php'?'active':''?>" href="preview.php">Phạm vi</a><a class="nav-link <?=$currentPage==='history.php'||$currentPage==='report.php'?'active':''?>" href="history.php">Lịch sử & Báo cáo</a><a class="nav-link <?=$currentPage==='compare.php'?'active':''?>" href="compare.php">So sánh</a><a class="nav-link <?=$currentPage==='tests.php'?'active':''?>" href="tests.php">🧪 Kiểm thử & Web mẫu</a><?php if ((current_user()['role'] ?? '') === 'admin'): ?><a class="nav-link <?=$currentPage==='schedules.php'?'active':''?>" href="schedules.php">Lịch định kỳ</a><a class="nav-link <?=$currentPage==='users.php'?'active':''?>" href="users.php">Người dùng</a><?php endif; ?><a class="nav-link <?=$currentPage==='notifications.php'?'active':''?>" href="notifications.php">Thông báo<?php if ($unreadCount > 0): ?> <span class="badge rounded-pill text-bg-danger"><?=$unreadCount?></span><?php endif; ?></a><span class="nav-link text-light opacity-75 small">👤 <?=e(current_user()['username'] ?? '')?> (<?=e(current_user()['role'] ?? '')?>)</span><a class="nav-link text-warning" href="logout.php">Đăng xuất</a></div><?php endif; ?></div></div></nav><main class="container pb-5"><?php show_flash(); }
function layout_end(): void { ?></main><footer class="container text-center small footer pb-4">LinkScope Pro v3.0 · Đề tài 13: Công cụ kiểm tra liên kết và tài nguyên bị lỗi trên website (Hỗ trợ Multi-cURL quét Web lớn & Kiểm thử tự động)</footer><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script>
document.querySelectorAll('form[data-scan-form="1"]').forEach(form => {
  form.addEventListener('submit', function(e) {
    const submitter = e.submitter;
    if (submitter && submitter.getAttribute('formaction') && submitter.getAttribute('formaction').indexOf('preview.php') !== -1) {
      return;
    }
    const overlay = document.getElementById('scanOverlay');
    if (overlay) {
      overlay.style.display = 'grid';
      let sec = 0;
      const timerEl = document.getElementById('scanTimer');
      setInterval(() => { sec++; if (timerEl) timerEl.textContent = String(sec); }, 1000);
    }
  });
});
</script></body></html><?php }
