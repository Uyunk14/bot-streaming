<?php
/*
 * telegram.php — panggilan Bot API, validasi initData Mini App,
 * cek keanggotaan channel/grup.
 */
require_once __DIR__ . '/core.php';

function tg($method, array $params = [], $token = null)
{
    $token = $token ?: setting('bot_token');
    if (!$token) return ['ok' => false, 'description' => 'Token bot belum diisi'];
    $res = http_request('POST', 'https://api.telegram.org/bot' . $token . '/' . $method, ['Content-Type: application/json'], $params, 20);
    if (!$res['json']) {
        app_log('telegram', $method . ' gagal: ' . $res['error'] . ' ' . substr($res['body'], 0, 200));
        return ['ok' => false, 'description' => $res['error'] ?: 'Tidak ada jawaban'];
    }
    if (empty($res['json']['ok'])) app_log('telegram', $method . ' ' . substr($res['body'], 0, 300));
    return $res['json'];
}

/** Tombol inline yang membuka Mini App. */
function tg_app_button($text = null, $path = '')
{
    return ['inline_keyboard' => [[[
        'text' => $text ?: '🎬 Buka Aplikasi',
        'web_app' => ['url' => base_url('app/') . ($path !== '' ? '#' . $path : '')],
    ]]]];
}

function tg_send($chatId, $text, $markup = null, $extra = [])
{
    $p = array_merge(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true], $extra);
    if ($markup) $p['reply_markup'] = $markup;
    return tg('sendMessage', $p);
}

/** Kirim ke semua admin (ID dipisah koma di pengaturan). */
function tg_notify_admins($text)
{
    foreach (array_filter(array_map('trim', explode(',', setting('admin_ids')))) as $id) {
        tg_send($id, $text);
    }
}

/**
 * Validasi initData dari Telegram.WebApp. Mengembalikan data user (array)
 * bila sah, atau null.
 */
function tg_validate_init_data($initData, $maxAge = 86400)
{
    $token = setting('bot_token');
    if (!$initData || !$token) return null;
    $pairs = [];
    foreach (explode('&', $initData) as $part) {
        $kv = explode('=', $part, 2);
        if (count($kv) !== 2) continue;
        $pairs[urldecode($kv[0])] = urldecode($kv[1]);
    }
    if (empty($pairs['hash'])) return null;
    $hash = $pairs['hash'];
    unset($pairs['hash']);
    ksort($pairs);
    $lines = [];
    foreach ($pairs as $k => $v) $lines[] = $k . '=' . $v;
    $secret = hash_hmac('sha256', $token, 'WebAppData', true);
    $calc = hash_hmac('sha256', implode("\n", $lines), $secret);
    if (!hash_equals($calc, $hash)) return null;
    if (empty($pairs['auth_date']) || time() - (int) $pairs['auth_date'] > $maxAge) return null;
    $user = isset($pairs['user']) ? json_decode($pairs['user'], true) : null;
    return (is_array($user) && !empty($user['id'])) ? $user : null;
}

/** Simpan / perbarui data user Telegram. Mengembalikan baris users. */
function user_upsert(array $tgUser)
{
    $id = (int) $tgUser['id'];
    $now = date('Y-m-d H:i:s');
    $isNew = !q_val("SELECT 1 FROM users WHERE id = ?", [$id]);
    q(
        "INSERT INTO users (id, username, first_name, last_name, created_at, last_seen) VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE username = VALUES(username), first_name = VALUES(first_name), last_name = VALUES(last_name), last_seen = VALUES(last_seen), blocked = 0",
        [
            $id,
            mb_substr(isset($tgUser['username']) ? $tgUser['username'] : '', 0, 64),
            mb_substr(isset($tgUser['first_name']) ? $tgUser['first_name'] : '', 0, 128),
            mb_substr(isset($tgUser['last_name']) ? $tgUser['last_name'] : '', 0, 128),
            $now,
            $now,
        ]
    );
    if ($isNew) stat_inc('new_users');
    return q_row("SELECT * FROM users WHERE id = ?", [$id]);
}

/** Normalisasi @username / tautan t.me menjadi chat id untuk Bot API. */
function tg_chat_ref($value)
{
    $v = trim($value);
    if ($v === '') return '';
    if (preg_match('~t\.me/([A-Za-z0-9_]{4,})~', $v, $m)) return '@' . $m[1];
    if (preg_match('/^-?\d+$/', $v)) return $v;
    return '@' . ltrim($v, '@');
}

/** Tautan publik t.me untuk channel/grup (bila berupa username). */
function tg_chat_link($value)
{
    $v = trim($value);
    if (stripos($v, 't.me/') !== false) return (strpos($v, 'http') === 0 ? '' : 'https://') . $v;
    if (preg_match('/^-?\d+$/', $v)) return '';
    return 'https://t.me/' . ltrim($v, '@');
}

/**
 * Status keanggotaan wajib gabung untuk user. Hasil dicache 10 menit
 * (kecuali $fresh). Mengembalikan ['required'=>bool,'ok'=>bool,'items'=>[...]].
 */
function gate_status($userId, $fresh = false)
{
    $required = setting('gate_on') === '1';
    $items = [];
    $targets = ['channel' => setting('gate_channel'), 'group' => setting('gate_group')];
    $allOk = true;
    foreach ($targets as $kind => $val) {
        if (trim($val) === '') continue;
        $ok = true;
        if ($required) {
            $ck = 'gate:' . $userId . ':' . $kind . ':' . $val;
            $cached = $fresh ? null : cache_get($ck);
            if ($cached === null) {
                $res = tg('getChatMember', ['chat_id' => tg_chat_ref($val), 'user_id' => (int) $userId]);
                if (!empty($res['ok'])) {
                    $st = $res['result']['status'];
                    $ok = in_array($st, ['creator', 'administrator', 'member'], true)
                        || ($st === 'restricted' && !empty($res['result']['is_member']));
                } else {
                    // Bot bukan admin / chat salah: jangan kunci user, cukup catat.
                    $ok = true;
                    app_log('gate', 'getChatMember ' . $val . ': ' . (isset($res['description']) ? $res['description'] : '?'));
                }
                cache_set($ck, $ok ? 1 : 0, $ok ? 600 : 60);
            } else {
                $ok = (bool) $cached;
            }
        }
        if (!$ok) $allOk = false;
        $items[] = ['kind' => $kind, 'name' => '@' . ltrim(preg_replace('~^.*t\.me/~', '', $val), '@'), 'link' => tg_chat_link($val), 'joined' => $ok];
    }
    return ['required' => $required && count($items) > 0, 'ok' => !$required || $allOk, 'items' => $items];
}
