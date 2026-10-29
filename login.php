<?php
require 'config.php';
if (current_user()) redirect('index.php');
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') { verify_csrf(); $u=trim((string)($_POST['username']??'')); $pw=(string)($_POST['password']??'');
 $st=$pdo->prepare('SELECT id,username,password_hash,role FROM users WHERE username=? LIMIT 1'); $st->execute([$u]); $user=$st->fetch();
 if ($user && password_verify($pw,$user['password_hash'])) { session_regenerate_id(true); $_SESSION['user']=['id'=>(int)$user['id'],'username'=>$user['username'],'role'=>$user['role']]; redirect('index.php'); }
 $error='Tên đăng nhập hoặc mật khẩu chưa đúng.';
}
layout_start('Đăng nhập'); ?>
<div class="row justify-content-center py-5"><div class="col-md-6 col-lg-4"><div class="card p-4 p-md-5"><div class="small-label mb-2">LINKSCOPE ACCOUNT</div><h1 class="h3 page-title mb-2">Đăng nhập</h1><p class="muted mb-4">Đăng nhập để quản lý các phiên kiểm tra website.</p><?php if($error!==''):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><div class="mb-3"><label class="form-label fw-semibold">Tên đăng nhập</label><input class="form-control" name="username" required autocomplete="username"></div><div class="mb-3"><label class="form-label fw-semibold">Mật khẩu</label><input class="form-control" type="password" name="password" required autocomplete="current-password"></div><button class="btn btn-primary w-100" type="submit">Đăng nhập</button></form><div class="small muted mt-3">Tài khoản demo sau khi import database: <code>admin</code> / <code>Admin@12345</code>. Hãy đổi mật khẩu trước khi triển khai thật.</div></div></div></div><?php layout_end(); ?>