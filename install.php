<?php
/*
 * install.php — pemasangan pertama. Membuat config.php (koneksi database)
 * dan password admin. Setelah terpasang, halaman ini terkunci otomatis.
 */
require_once __DIR__ . '/lib/core.php';

$done = app_installed();
$err = '';
if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg = [
        'db_host' => trim((string) (isset($_POST['db_host']) ? $_POST['db_host'] : 'localhost')),
        'db_name' => trim((string) (isset($_POST['db_name']) ? $_POST['db_name'] : '')),
        'db_user' => trim((string) (isset($_POST['db_user']) ? $_POST['db_user'] : '')),
        'db_pass' => (string) (isset($_POST['db_pass']) ? $_POST['db_pass'] : ''),
    ];
    $p1 = (string) (isset($_POST['admin_pass']) ? $_POST['admin_pass'] : '');
    $p2 = (string) (isset($_POST['admin_pass2']) ? $_POST['admin_pass2'] : '');
    if (strlen($p1) < 8) $err = 'Password admin minimal 8 karakter.';
    elseif ($p1 !== $p2) $err = 'Konfirmasi password tidak sama.';
    else {
        try {
            $pdo = new PDO('mysql:host=' . $cfg['db_host'] . ';dbname=' . $cfg['db_name'] . ';charset=utf8mb4', $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("SET time_zone = '+07:00'");
            db_migrate($pdo);
            $pdo->prepare("REPLACE INTO settings (k, v) VALUES ('admin_pass_hash', ?)")->execute([password_hash($p1, PASSWORD_DEFAULT)]);
            $php = "<?php\n// Dibuat otomatis oleh install.php — JANGAN dibagikan.\nreturn " . var_export($cfg, true) . ";\n";
            if (@file_put_contents(__DIR__ . '/config.php', $php) === false) {
                $err = 'Tidak bisa menulis config.php. Periksa izin folder.';
            } else {
                @chmod(__DIR__ . '/config.php', 0640);
                header('Location: admin/login.php');
                exit;
            }
        } catch (Exception $e) {
            $err = 'Koneksi database gagal: ' . $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pemasangan</title><link rel="stylesheet" href="admin/admin.css"></head>
<body><div class="login" style="max-width:440px"><div class="box">
<h1>Pemasangan Bot Streaming</h1>
<?php if ($done): ?>
  <div class="flash ok">Aplikasi sudah terpasang. Halaman ini dikunci.</div>
  <a class="btn" href="admin/login.php">Masuk admin</a>
<?php else: ?>
  <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
  <p class="muted">Buat database MySQL kosong dulu di cPanel (MySQL Databases), lalu isi di sini.</p>
  <form method="post" style="display:flex;flex-direction:column;gap:12px">
    <label>Host database<input name="db_host" value="localhost" required></label>
    <label>Nama database<input name="db_name" required></label>
    <label>User database<input name="db_user" required></label>
    <label>Password database<input type="password" name="db_pass"></label>
    <div class="sep"></div>
    <label>Password admin baru<input type="password" name="admin_pass" minlength="8" required></label>
    <label>Ulangi password admin<input type="password" name="admin_pass2" minlength="8" required></label>
    <button class="btn" type="submit">Pasang</button>
  </form>
<?php endif; ?>
</div></div></body></html>
