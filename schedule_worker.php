<?php
// CLI worker for Windows Task Scheduler. Run only from PHP CLI, not the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__);
require_once __DIR__ . '/config.php';
$st=$pdo->query("SELECT sc.id schedule_id,sc.site_id,sc.interval_minutes,s.name FROM scan_schedules sc JOIN sites s ON s.id=sc.site_id WHERE sc.enabled=1 AND sc.next_run_at IS NOT NULL AND sc.next_run_at<=NOW() ORDER BY sc.next_run_at ASC LIMIT 1");
$schedule=$st->fetch();
if(!$schedule){echo "No due schedules.\n";exit(0);}
$interval=max(60,min(10080,(int)$schedule['interval_minutes']));
$next=date('Y-m-d H:i:s',time()+$interval*60);
$up=$pdo->prepare('UPDATE scan_schedules SET last_run_at=NOW(), next_run_at=? WHERE id=?');
$up->execute([$next,(int)$schedule['schedule_id']]);
$_SERVER['REQUEST_METHOD']='POST';
$_POST=['site_id'=>(int)$schedule['site_id']];
define('LINKSCOPE_WORKER',true);
require __DIR__ . '/scan.php';
