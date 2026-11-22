<?php
/*
 * Kerangka admin: sesi, login, CSRF, tata letak.
 */
require_once dirname(__DIR__) . '/lib/core.php';
require_once dirname(__DIR__) . '/lib/telegram.php';

if (!app_installed()) { header('Location: ../install.php'); exit; }

session_name('stadm');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function admin_logged_in()
{
    return !empty($_SESSION['adm']) && isset($_SESSION['adm_hash']) && $_SESSION['adm_hash'] === substr(setting('admin_pass_hash'), -16);
}

function admin_require()
{
    if (!admin_logged_in()) { header('Location: login.php'); exit; }
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = random_token(16);
    return $_SESSION['csrf'];
}
function csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}
function csrf_check()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return false;
    if (empty($_POST['csrf']) || !hash_equals(csrf_token(), (string) $_POST['csrf'])) {
        flash('Sesi kedaluwarsa, ulangi lagi.', 'err');
        return false;
    }
    return true;
}

function flash($msg = null, $type = 'ok')
{
    if ($msg !== null) { $_SESSION['flash'][] = [$type, $msg]; return; }
    $out = '';
    foreach (isset($_SESSION['flash']) ? $_SESSION['flash'] : [] as $f) $out .= '<div class="flash ' . e($f[0]) . '">' . e($f[1]) . '</div>';
    unset($_SESSION['flash']);
    return $out;
}

function back($url = null)
{
    header('Location: ' . ($url ?: strtok($_SERVER['REQUEST_URI'], '#')));
    exit;
}

function post($k, $def = '')
{
    return isset($_POST[$k]) && !is_array($_POST[$k]) ? trim((string) $_POST[$k]) : $def;
}

function admin_head($title, $active)
{
    $menu = [
        'index' => ['Dasbor', 'index.php'],
        'settings' => ['Pengaturan', 'settings.php'],
        'plans' => ['Paket VIP', 'plans.php'],
        'payments' => ['Transaksi QRIS', 'payments.php'],
        'users' => ['Pengguna', 'users.php'],
        'broadcast' => ['Broadcast', 'broadcast.php'],
    ];
    $color = setting('primary_color', '#6A4DF4');
    if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) $color = '#6A4DF4';
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' · ' . e(setting('bot_name')) . ' Admin</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="admin.css?v=1"><style>:root{--accent:' . e($color) . '}</style></head><body><div class="wrap">';
    echo '<aside class="side"><div class="brand"><span class="logo"></span>' . e(setting('bot_name')) . ' · Admin</div><nav>';
    foreach ($menu as $k => $m) echo '<a href="' . $m[1] . '"' . ($k === $active ? ' class="on"' : '') . '>' . $m[0] . '</a>';
    echo '<a href="logout.php" class="out">Keluar</a></nav></aside><main class="main">';
    echo '<h1>' . e($title) . '</h1>' . flash();
}

function admin_foot()
{
    echo '</main></div></body></html>';
}
