<?php
/*
 * Webhook bot Telegram. Dipasang otomatis dari Admin → Pengaturan →
 * "Pasang webhook & tombol Mini App". Diamankan dengan secret_token.
 */
require_once dirname(__DIR__) . '/lib/core.php';
require_once dirname(__DIR__) . '/lib/telegram.php';

if (!app_installed()) { http_response_code(503); exit; }

$secret = isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) ? $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] : '';
if (!hash_equals((string) setting('webhook_secret'), (string) $secret)) { http_response_code(403); exit; }

$update = json_decode(file_get_contents('php://input'), true);
http_response_code(200);
if (!is_array($update)) exit;

try {
    // User memblokir / membuka blokir bot
    if (isset($update['my_chat_member'])) {
        $m = $update['my_chat_member'];
        if ($m['chat']['type'] === 'private') {
            $st = $m['new_chat_member']['status'];
            q("UPDATE users SET blocked = ? WHERE id = ?", [in_array($st, ['kicked', 'left'], true) ? 1 : 0, (int) $m['chat']['id']]);
        }
        exit;
    }

    if (!isset($update['message'])) exit;
    $msg = $update['message'];
    if ($msg['chat']['type'] !== 'private' || empty($msg['from'])) exit;

    $user = user_upsert($msg['from']);
    $chat = $msg['chat']['id'];
    $text = isset($msg['text']) ? trim($msg['text']) : '';
    $name = e($msg['from']['first_name']);
    $botName = e(setting('bot_name'));

    if ($text === '' ) exit;
    $cmd = strtolower(strtok($text, " @"));

    if ($cmd === '/start' || $cmd === '/menu') {
        $welcome = str_replace('{name}', $name, e(setting('welcome_text')));
        $logo = setting('logo_url');
        if ($logo) {
            tg('sendPhoto', ['chat_id' => $chat, 'photo' => $logo, 'caption' => $welcome, 'parse_mode' => 'HTML', 'reply_markup' => tg_app_button()]);
        } else {
            tg_send($chat, $welcome, tg_app_button());
        }
    } elseif ($cmd === '/vip' || $cmd === '/akun') {
        if (user_is_vip($user)) {
            $t = "👑 Status: <b>VIP aktif</b>\nSampai: <b>" . date('d M Y H:i', strtotime($user['vip_until'])) . "</b>";
        } else {
            $t = "Status: <b>Gratis</b>\nEpisode 1 tiap series gratis dan film bisa dicoba " . (int) setting('free_minutes', '2') . " menit.\nJadi VIP untuk menonton semuanya.";
        }
        tg_send($chat, $t, tg_app_button(user_is_vip($user) ? '🎬 Buka Aplikasi' : '👑 Jadi VIP', user_is_vip($user) ? '' : '/vip'));
    } elseif ($cmd === '/help' || $cmd === '/bantuan') {
        tg_send($chat, "<b>$botName</b>\n/start — buka aplikasi\n/vip — cek status VIP\n\nCari judul langsung di aplikasi lewat tombol di bawah.", tg_app_button());
    } else {
        // Teks biasa dianggap pencarian -> buka aplikasi di halaman cari
        $q = mb_substr($text, 0, 60);
        tg_send($chat, 'Cari “' . e($q) . '” di aplikasi:', tg_app_button('🔎 Cari “' . mb_substr($q, 0, 40) . '”', '/search?q=' . rawurlencode($q)));
    }
} catch (Exception $e) {
    app_log('webhook', $e->getMessage());
}
