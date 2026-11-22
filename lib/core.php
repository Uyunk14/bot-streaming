<?php
/*
 * core.php — pondasi aplikasi: konfigurasi, database (PDO), pengaturan,
 * helper HTTP/JSON. Semua file lain memuat file ini lebih dulu.
 *
 * Kode harus tetap jalan di PHP 7.4 s.d. 8.4.
 */

if (defined('APP_ROOT')) return;
define('APP_ROOT', dirname(__DIR__));
define('SCHEMA_VER', 1);

date_default_timezone_set('Asia/Jakarta');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
} else {
    // Cadangan bila ekstensi mbstring tidak aktif di server
    function mb_substr($s, $start, $len = null) { $r = iconv_substr((string) $s, $start, $len === null ? PHP_INT_MAX : $len, 'UTF-8'); return $r === false ? '' : $r; }
    function mb_strlen($s) { return (int) iconv_strlen((string) $s, 'UTF-8'); }
    function mb_check_encoding($s, $enc = 'UTF-8') { return (bool) preg_match('//u', (string) $s); }
    function mb_convert_encoding($s, $to, $from) { $r = @iconv($from, $to . '//IGNORE', (string) $s); return $r === false ? (string) $s : $r; }
    function mb_strimwidth($s, $start, $width, $marker = '') { return mb_strlen($s) > $width ? mb_substr($s, $start, $width - mb_strlen($marker)) . $marker : (string) $s; }
}

/* ---------- Konfigurasi database (config.php dibuat oleh install.php) ---------- */
function app_config($key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = APP_ROOT . '/config.php';
        $cfg = is_file($file) ? (array) require $file : [];
    }
    if ($key === null) return $cfg;
    return isset($cfg[$key]) ? $cfg[$key] : null;
}

function app_installed()
{
    return is_file(APP_ROOT . '/config.php');
}

/* ---------- Database ---------- */
function db()
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = app_config();
    if (!$c) throw new RuntimeException('Aplikasi belum dipasang. Buka install.php.');
    $dsn = 'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+07:00'");
    db_migrate($pdo);
    return $pdo;
}

function q($sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function q_row($sql, array $params = [])
{
    $r = q($sql, $params)->fetch();
    return $r ? $r : null;
}
function q_all($sql, array $params = [])
{
    return q($sql, $params)->fetchAll();
}
function q_val($sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/* ---------- Migrasi otomatis ---------- */
function db_migrate(PDO $pdo)
{
    try {
        $ver = (int) $pdo->query("SELECT v FROM settings WHERE k = 'schema_ver'")->fetchColumn();
    } catch (Exception $e) {
        $ver = 0;
    }
    if ($ver >= SCHEMA_VER) return;

    $tables = [
        "CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(64) NOT NULL PRIMARY KEY,
            v MEDIUMTEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS users (
            id BIGINT NOT NULL PRIMARY KEY,
            username VARCHAR(64) NOT NULL DEFAULT '',
            first_name VARCHAR(128) NOT NULL DEFAULT '',
            last_name VARCHAR(128) NOT NULL DEFAULT '',
            vip_until DATETIME NULL,
            notify TINYINT NOT NULL DEFAULT 1,
            blocked TINYINT NOT NULL DEFAULT 0,
            banned TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            KEY idx_vip (vip_until),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS plans (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL,
            days INT NOT NULL,
            price INT NOT NULL,
            label VARCHAR(64) NOT NULL DEFAULT '',
            active TINYINT NOT NULL DEFAULT 1,
            sort INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ref VARCHAR(32) NOT NULL,
            user_id BIGINT NOT NULL,
            plan_id INT NOT NULL,
            plan_name VARCHAR(64) NOT NULL,
            days INT NOT NULL,
            price INT NOT NULL,
            unique_code INT NOT NULL,
            amount INT NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            paid_at DATETIME NULL,
            last_check INT NOT NULL DEFAULT 0,
            note VARCHAR(255) NOT NULL DEFAULT '',
            UNIQUE KEY uq_ref (ref),
            KEY idx_status (status),
            KEY idx_user (user_id),
            KEY idx_amount (amount)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS favorites (
            user_id BIGINT NOT NULL,
            provider VARCHAR(20) NOT NULL,
            subject_id VARCHAR(40) NOT NULL,
            detail_path VARCHAR(255) NOT NULL,
            title VARCHAR(255) NOT NULL,
            cover VARCHAR(500) NOT NULL DEFAULT '',
            stype TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, provider, subject_id),
            KEY idx_subject (provider, subject_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS history (
            user_id BIGINT NOT NULL,
            provider VARCHAR(20) NOT NULL,
            subject_id VARCHAR(40) NOT NULL,
            detail_path VARCHAR(255) NOT NULL,
            title VARCHAR(255) NOT NULL,
            cover VARCHAR(500) NOT NULL DEFAULT '',
            stype TINYINT NOT NULL DEFAULT 1,
            se INT NOT NULL DEFAULT 0,
            ep INT NOT NULL DEFAULT 0,
            position INT NOT NULL DEFAULT 0,
            duration INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, provider, subject_id),
            KEY idx_updated (user_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS watch_subjects (
            provider VARCHAR(20) NOT NULL,
            subject_id VARCHAR(40) NOT NULL,
            detail_path VARCHAR(255) NOT NULL,
            title VARCHAR(255) NOT NULL,
            cover VARCHAR(500) NOT NULL DEFAULT '',
            ep_count INT NOT NULL DEFAULT 0,
            checked_at DATETIME NOT NULL,
            PRIMARY KEY (provider, subject_id),
            KEY idx_checked (checked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS cache (
            k CHAR(40) NOT NULL PRIMARY KEY,
            v MEDIUMTEXT NOT NULL,
            expires_at INT NOT NULL,
            KEY idx_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS broadcasts (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            audience VARCHAR(10) NOT NULL DEFAULT 'all',
            text TEXT NOT NULL,
            photo VARCHAR(500) NOT NULL DEFAULT '',
            button_text VARCHAR(64) NOT NULL DEFAULT '',
            status VARCHAR(10) NOT NULL DEFAULT 'queued',
            total INT NOT NULL DEFAULT 0,
            sent INT NOT NULL DEFAULT 0,
            failed INT NOT NULL DEFAULT 0,
            last_user BIGINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            finished_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS stats_daily (
            d DATE NOT NULL,
            k VARCHAR(32) NOT NULL,
            n INT NOT NULL DEFAULT 0,
            PRIMARY KEY (d, k)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS views (
            d DATE NOT NULL,
            provider VARCHAR(20) NOT NULL,
            subject_id VARCHAR(40) NOT NULL,
            title VARCHAR(255) NOT NULL,
            n INT NOT NULL DEFAULT 0,
            PRIMARY KEY (d, provider, subject_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $sql) $pdo->exec($sql);

    // Nilai awal pengaturan (hanya diisi bila belum ada)
    $defaults = [
        'bot_name' => 'Nonton',
        'primary_color' => '#6A4DF4',
        'logo_url' => '',
        'bot_token' => '',
        'bot_username' => '',
        'admin_ids' => '',
        'webhook_secret' => random_token(24),
        'indocast_key' => '',
        'indocast_base' => 'https://indocast.site/api',
        'providers' => '{"filmbox":1}',
        'free_episodes' => '1',
        'free_minutes' => '2',
        'gate_on' => '0',
        'gate_channel' => '',
        'gate_group' => '',
        'gobiz_email' => '',
        'gobiz_password' => '',
        'gobiz_access' => '',
        'gobiz_refresh' => '',
        'gobiz_merchant' => '',
        'gobiz_state' => '{}',
        'qris_static' => '',
        'pay_timeout' => '15',
        'cron_key' => random_token(20),
        'app_secret' => random_token(32),
        'welcome_text' => "Halo {name}! 🎬\nNonton film, drama, dan anime langsung di Telegram.\nTekan tombol di bawah untuk mulai.",
        'base_url' => '',
    ];
    $ins = $pdo->prepare("INSERT IGNORE INTO settings (k, v) VALUES (?, ?)");
    foreach ($defaults as $k => $v) $ins->execute([$k, $v]);
    $pdo->prepare("REPLACE INTO settings (k, v) VALUES ('schema_ver', ?)")->execute([(string) SCHEMA_VER]);
}

/* ---------- Pengaturan (tabel settings) ---------- */
function setting($key, $default = '')
{
    $all = settings_all();
    return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : $default;
}
function settings_all($reload = false)
{
    static $cache = null;
    if ($cache === null || $reload) {
        $cache = [];
        foreach (q_all("SELECT k, v FROM settings") as $r) $cache[$r['k']] = $r['v'];
    }
    return $cache;
}
function setting_set($key, $value)
{
    q("REPLACE INTO settings (k, v) VALUES (?, ?)", [$key, (string) $value]);
    settings_all(true);
}

/* ---------- Statistik harian ---------- */
function stat_inc($key, $n = 1)
{
    try {
        q("INSERT INTO stats_daily (d, k, n) VALUES (CURDATE(), ?, ?) ON DUPLICATE KEY UPDATE n = n + VALUES(n)", [$key, (int) $n]);
    } catch (Exception $e) {
    }
}

/* ---------- Cache (tabel cache) ---------- */
function cache_get($key)
{
    $row = q_row("SELECT v, expires_at FROM cache WHERE k = ?", [sha1($key)]);
    if (!$row || (int) $row['expires_at'] < time()) return null;
    return json_decode($row['v'], true);
}
function cache_set($key, $value, $ttl)
{
    q("REPLACE INTO cache (k, v, expires_at) VALUES (?, ?, ?)", [sha1($key), json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time() + (int) $ttl]);
}
function cache_del($key)
{
    q("DELETE FROM cache WHERE k = ?", [sha1($key)]);
}

/* ---------- Helper umum ---------- */
function random_token($bytes = 16)
{
    return bin2hex(random_bytes($bytes));
}

function e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function rupiah($n)
{
    return 'Rp ' . number_format((int) $n, 0, ',', '.');
}

function base_url($path = '')
{
    $saved = rtrim(setting('base_url', ''), '/');
    if ($saved === '' && isset($_SERVER['HTTP_HOST'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        $sub = '';
        if ($docRoot) {
            $root = realpath(APP_ROOT);
            if ($root && strpos($root, $docRoot) === 0) $sub = str_replace('\\', '/', substr($root, strlen($docRoot)));
        }
        $saved = ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim($sub, '/');
    }
    return $saved . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function app_log($name, $msg)
{
    $dir = APP_ROOT . '/storage/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/' . preg_replace('/[^a-z0-9_-]/i', '', $name) . '.log';
    if (is_file($file) && filesize($file) > 2 * 1024 * 1024) @rename($file, $file . '.old');
    @file_put_contents($file, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}

/**
 * Permintaan HTTP sederhana (cURL). Mengembalikan
 * ['code' => int, 'body' => string, 'json' => array|null, 'error' => string].
 */
function http_request($method, $url, array $headers = [], $body = null, $timeout = 20)
{
    if (!function_exists('curl_init')) return ['code' => 0, 'body' => '', 'json' => null, 'error' => 'Ekstensi cURL tidak aktif'];
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_ENCODING => '',
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = $raw === false ? curl_error($ch) : '';
    curl_close($ch);
    $raw = $raw === false ? '' : $raw;
    $json = json_decode($raw, true);
    return ['code' => $code, 'body' => $raw, 'json' => is_array($json) ? $json : null, 'error' => $err];
}

/** Tanda tangan HMAC untuk tautan bertanggal kedaluwarsa (mis. subtitle). */
function sign_params(array $params, $ttl = 7200)
{
    $params['x'] = time() + $ttl;
    ksort($params);
    $params['s'] = substr(hash_hmac('sha256', http_build_query($params), setting('app_secret')), 0, 32);
    return $params;
}
function verify_params(array $params)
{
    if (empty($params['s']) || empty($params['x']) || (int) $params['x'] < time()) return false;
    $sig = $params['s'];
    unset($params['s']);
    ksort($params);
    return hash_equals(substr(hash_hmac('sha256', http_build_query($params), setting('app_secret')), 0, 32), (string) $sig);
}

/** Pengguna VIP aktif? */
function user_is_vip($user)
{
    return $user && !empty($user['vip_until']) && strtotime($user['vip_until']) > time();
}
