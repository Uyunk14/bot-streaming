<?php
require __DIR__ . '/_inc.php';

if (admin_logged_in()) { header('Location: index.php'); exit; }

$err = '';
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$lockKey = 'login_fail:' . $ip;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fails = (int) cache_get($lockKey);
    if ($fails >= 5) {
        $err = 'Terlalu banyak percobaan. Tunggu 15 menit.';
    } elseif (csrf_check() && password_verify(post('password'), setting('admin_pass_hash'))) {
        session_regenerate_id(true);
        $_SESSION['adm'] = 1;
        $_SESSION['adm_hash'] = substr(setting('admin_pass_hash'), -16);
        cache_del($lockKey);
        header('Location: index.php');
        exit;
    } else {
        cache_set($lockKey, $fails + 1, 900);
        sleep(1);
        $err = 'Password salah.';
    }
}
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="admin.css?v=1"><style>:root{--accent:<?= e(setting('primary_color', '#6A4DF4')) ?>}</style></head>
<body><div class="login"><div class="box">
<h1><?= e(setting('bot_name')) ?> · Admin</h1>
<?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
<?= flash() ?>
<form method="post"><?= csrf_field() ?>
<label>Password<input type="password" name="password" autofocus required></label>
<div class="actions"><button class="btn" type="submit">Masuk</button></div>
</form></div></div></body></html>
