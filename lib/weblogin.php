<?php
/*
 * weblogin.php — masuk ke versi web (browser biasa) lewat bot.
 *
 * Alur: di bot /loginweb (tombol "🌐 Login Web") -> bot memberi tautan sekali pakai
 * (app/?login=TOKEN) + kode 6 angka untuk laptop. Keduanya berlaku 5 menit, sekali pakai.
 * Ditukar di api/?a=web_login menjadi sesi web 30 hari (header X-Web-Session).
 * Yang disimpan di database hanya hash SHA-256 dari token, bukan token aslinya.
 */
require_once __DIR__ . '/core.php';

const WL_TTL = 300;              // kode / tautan login: 5 menit
const WS_TTL = 30 * 86400;       // sesi web: 30 hari sejak terakhir dipakai

function wl_tables()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (setting('weblogin_ver') === '1') return;
    db()->exec("CREATE TABLE IF NOT EXISTS web_logins (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        code CHAR(6) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at INT NOT NULL,
        used TINYINT NOT NULL DEFAULT 0,
        KEY code (code),
        KEY token_hash (token_hash),
        KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS web_sessions (
        token_hash CHAR(64) NOT NULL PRIMARY KEY,
        user_id BIGINT NOT NULL,
        device VARCHAR(60) NOT NULL DEFAULT '',
        created_at INT NOT NULL,
        last_seen INT NOT NULL,
        KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    setting_set('weblogin_ver', '1');
}

/** Buat tautan + kode login baru (kode lama milik user ini dibatalkan). */
function wl_create($userId)
{
    wl_tables();
    $now = time();
    q("DELETE FROM web_logins WHERE user_id = ? OR expires_at < ?", [(int) $userId, $now - 3600]);
    $code = '';
    for ($i = 0; $i < 20; $i++) {
        $c = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        if (!q_val("SELECT 1 FROM web_logins WHERE code = ? AND used = 0 AND expires_at >= ?", [$c, $now])) { $code = $c; break; }
    }
    if ($code === '') return null;
    $token = random_token(24);
    q("INSERT INTO web_logins (user_id, code, token_hash, expires_at) VALUES (?, ?, ?, ?)",
        [(int) $userId, $code, hash('sha256', $token), $now + WL_TTL]);
    return ['code' => $code, 'token' => $token, 'url' => base_url('app/?login=' . $token)];
}

/** Nama perangkat singkat dari User-Agent (untuk kabar di bot). */
function wl_device()
{
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    $os = preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/iPhone|iPad/i', $ua) ? 'iPhone/iPad'
        : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Mac OS/i', $ua) ? 'Mac' : (preg_match('/Linux/i', $ua) ? 'Linux' : 'Perangkat lain'))));
    $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera' : (preg_match('/Firefox\//', $ua) ? 'Firefox'
        : (preg_match('/SamsungBrowser/', $ua) ? 'Samsung Internet' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Browser')))));
    return $br . ', ' . $os;
}

/**
 * Tukar kode 6 angka ATAU token tautan menjadi sesi web.
 * @return array ['ok'=>bool, 'error'=>string, 'session'=>string, 'user_id'=>int]
 */
function wl_redeem($code, $token)
{
    wl_tables();
    $now = time();
    // Batasi percobaan kode per IP (10 kali / 10 menit) supaya kode tidak bisa ditebak
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $rk = 'wl:try:' . $ip;
    $tries = (int) cache_get($rk);
    if ($tries >= 10) return ['ok' => false, 'error' => 'Terlalu banyak percobaan. Tunggu 10 menit lalu minta kode baru di bot.'];

    if ($token !== '' && preg_match('/^[a-f0-9]{48}$/', $token)) {
        $row = q_row("SELECT * FROM web_logins WHERE token_hash = ? AND used = 0 AND expires_at >= ?", [hash('sha256', $token), $now]);
    } elseif (preg_match('/^\d{6}$/', $code)) {
        $row = q_row("SELECT * FROM web_logins WHERE code = ? AND used = 0 AND expires_at >= ?", [$code, $now]);
    } else {
        $row = null;
    }
    if (!$row) {
        cache_set($rk, $tries + 1, 600);
        return ['ok' => false, 'error' => $token !== '' ? 'Tautan login sudah dipakai atau kedaluwarsa. Tekan 🌐 Login Web lagi di bot.' : 'Kode salah atau kedaluwarsa. Minta kode baru di bot.'];
    }
    // Tandai terpakai secara atomik (dua tab yang menukar bersamaan -> hanya satu yang berhasil)
    $st = q("UPDATE web_logins SET used = 1 WHERE id = ? AND used = 0", [(int) $row['id']]);
    if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'Kode sudah dipakai. Minta kode baru di bot.'];

    $sess = random_token(32);
    q("INSERT INTO web_sessions (token_hash, user_id, device, created_at, last_seen) VALUES (?, ?, ?, ?, ?)",
        [hash('sha256', $sess), (int) $row['user_id'], mb_substr(wl_device(), 0, 60), $now, $now]);
    return ['ok' => true, 'error' => '', 'session' => $sess, 'user_id' => (int) $row['user_id']];
}

/** User dari token sesi web, atau null. */
function wl_session_user($sess)
{
    if (!preg_match('/^[a-f0-9]{64}$/', (string) $sess)) return null;
    wl_tables();
    $h = hash('sha256', $sess);
    $s = q_row("SELECT user_id, last_seen FROM web_sessions WHERE token_hash = ?", [$h]);
    if (!$s) return null;
    $now = time();
    if ((int) $s['last_seen'] < $now - WS_TTL) { q("DELETE FROM web_sessions WHERE token_hash = ?", [$h]); return null; }
    if ((int) $s['last_seen'] < $now - 600) {
        q("UPDATE web_sessions SET last_seen = ? WHERE token_hash = ?", [$now, $h]);
        q("UPDATE users SET last_seen = ? WHERE id = ?", [date('Y-m-d H:i:s'), (int) $s['user_id']]);
    }
    return q_row("SELECT * FROM users WHERE id = ?", [(int) $s['user_id']]);
}

function wl_logout($sess)
{
    if (!preg_match('/^[a-f0-9]{64}$/', (string) $sess)) return;
    wl_tables();
    q("DELETE FROM web_sessions WHERE token_hash = ?", [hash('sha256', $sess)]);
}

/** Keluarkan semua perangkat web milik user. @return int jumlah sesi */
function wl_logout_all($userId)
{
    wl_tables();
    return q("DELETE FROM web_sessions WHERE user_id = ?", [(int) $userId])->rowCount();
}

/** Bersihkan kode & sesi kedaluwarsa (cron). */
function wl_cleanup()
{
    if (setting('weblogin_ver') !== '1') return;
    q("DELETE FROM web_logins WHERE expires_at < ?", [time() - 3600]);
    q("DELETE FROM web_sessions WHERE last_seen < ?", [time() - WS_TTL]);
}
