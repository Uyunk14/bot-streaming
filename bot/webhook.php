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
    // Tombol menu bawah (reply keyboard) -> diperlakukan sama seperti perintahnya
    $btn = tg_menu_buttons();
    $cmd = isset($btn[$text]) ? $btn[$text] : strtolower(strtok($text, " @"));
    $kbShown = (bool) cache_get('kb1:' . $user['id']);

    if ($cmd === '/start' || $cmd === '/menu') {
        $welcome = str_replace('{name}', $name, e(setting('welcome_text')));
        $logo = setting('logo_url');
        if ($logo) {
            tg('sendPhoto', ['chat_id' => $chat, 'photo' => $logo, 'caption' => $welcome, 'parse_mode' => 'HTML', 'reply_markup' => tg_app_button()]);
        } else {
            tg_send($chat, $welcome, tg_app_button());
        }
        tg_send($chat, '👇 Semua menu ada di tombol bawah, tidak perlu ketik perintah.', tg_reply_keyboard());
        $kbShown = true;
        cache_set('kb1:' . $user['id'], 1, 365 * 86400);
    } elseif ($cmd === '/app') {
        tg_send($chat, 'Tekan tombol di bawah untuk membuka aplikasi.', tg_app_button());
    } elseif ($cmd === '/buyvip') {
        $v = user_is_vip($user);
        tg_send($chat, $v ? 'Perpanjang VIP lewat tombol di bawah.' : '👑 Jadi VIP untuk menonton semua episode & film penuh.', tg_app_button($v ? '👑 Perpanjang VIP' : '👑 Pilih Paket VIP', '/vip'));
    } elseif ($cmd === '/cari') {
        tg_send($chat, "🔎 Ketik judul film, drama, atau anime yang mau dicari.\nContoh: <i>Solo Leveling</i>");
    } elseif ($cmd === '/vip' || $cmd === '/akun') {
        if (user_is_vip($user)) {
            $t = "👑 Status: <b>VIP aktif</b>\nSampai: <b>" . date('d M Y H:i', strtotime($user['vip_until'])) . "</b>";
        } else {
            $t = "Status: <b>Gratis</b>\nEpisode 1 tiap series gratis dan film bisa dicoba " . (int) setting('free_minutes', '2') . " menit.\nJadi VIP untuk menonton semuanya.";
        }
        tg_send($chat, $t, tg_app_button(user_is_vip($user) ? '🎬 Buka Aplikasi' : '👑 Jadi VIP', user_is_vip($user) ? '' : '/vip'));
    } elseif ($cmd === '/drmtest' && in_array((string) $msg['from']['id'], array_map('trim', explode(',', setting('admin_ids'))), true)) {
        // Uji DRM Prime Video (khusus admin). Format: /drmtest [id_judul] [episode]
        $parts = preg_split('/\s+/', $text);
        $tid = isset($parts[1]) && preg_match('/^[A-Za-z0-9]{6,40}$/', $parts[1]) ? $parts[1] : '0NKUDJIM5VAE9079ZGUUVI3SS4';
        $ep = isset($parts[2]) ? max(1, (int) $parts[2]) : 1;
        $q = http_build_query(sign_params(['t' => 'pv', 'id' => $tid, 'ep' => $ep], 21600));
        $url = base_url('app/drmtest.html?' . $q);
        tg_send($chat, "🧪 <b>Tes DRM Prime Video</b>\nJudul: <code>" . e($tid) . "</code> · episode $ep\n\n1) Tekan <b>Tes di Telegram</b>.\n2) Bandingkan dengan <b>Buka di Chrome</b>.\nKirim screenshot hasilnya. Tautan berlaku 6 jam.", ['inline_keyboard' => [
            [['text' => '🧪 Tes di Telegram', 'web_app' => ['url' => $url]]],
            [['text' => '🌐 Buka di Chrome', 'url' => $url]],
        ]]);
    } elseif ($cmd === '/loginweb') {
        // Masuk ke versi web (browser biasa): tautan sekali pakai + kode 6 angka untuk laptop
        require_once dirname(__DIR__) . '/lib/weblogin.php';
        if (!empty($user['banned'])) { tg_send($chat, 'Akun kamu diblokir admin.'); exit; }
        $wl = wl_create($user['id']);
        if (!$wl) { tg_send($chat, 'Gagal membuat kode, coba lagi sebentar.'); exit; }
        $c = $wl['code'];
        tg_send($chat, "🌐 <b>Masuk ke $botName Web</b>\nTekan tombol di bawah untuk masuk di browser HP ini.\n\nDi laptop? Buka <b>" . e(preg_replace('#^https?://#', '', base_url())) . "</b> lalu masukkan kode:\n<code>" . substr($c, 0, 3) . ' ' . substr($c, 3) . "</code>\n\n<i>Berlaku 5 menit, sekali pakai. Jangan berikan ke orang lain.</i>",
            ['inline_keyboard' => [[['text' => '🌐 Masuk ke Web', 'url' => $wl['url']]]]]);
    } elseif ($cmd === '/logoutweb') {
        require_once dirname(__DIR__) . '/lib/weblogin.php';
        $n = wl_logout_all($user['id']);
        tg_send($chat, $n ? "✅ $n perangkat web sudah dikeluarkan." : 'Tidak ada perangkat web yang sedang masuk.', tg_reply_keyboard());
    } elseif ($cmd === '/help' || $cmd === '/bantuan') {
        tg_send($chat, "<b>$botName</b> — pakai tombol di bawah:\n\n🎬 <b>Buka Aplikasi</b> — nonton di Telegram\n👑 <b>Status VIP</b> — cek masa aktif VIP\n💎 <b>Beli VIP</b> — pilih paket VIP\n🔎 <b>Cari Judul</b> — cari film/drama/anime\n🌐 <b>Login Web</b> — nonton di browser HP/laptop\n🚪 <b>Keluar dari Web</b> — keluarkan semua perangkat web\n\nBisa juga langsung ketik judul untuk mencari.", tg_reply_keyboard());
        $kbShown = true;
        cache_set('kb1:' . $user['id'], 1, 365 * 86400);
    } else {
        // Teks biasa dianggap pencarian -> buka aplikasi di halaman cari
        $q = mb_substr($text, 0, 60);
        tg_send($chat, 'Cari “' . e($q) . '” di aplikasi:', tg_app_button('🔎 Cari “' . mb_substr($q, 0, 40) . '”', '/search?q=' . rawurlencode($q)));
    }
    // Pengguna lama yang belum pernah menerima tombol menu bawah: kirim sekali
    if (!$kbShown) {
        tg_send($chat, '👇 Sekarang semua menu ada di tombol bawah, tidak perlu ketik perintah.', tg_reply_keyboard());
        cache_set('kb1:' . $user['id'], 1, 365 * 86400);
    }
} catch (Exception $e) {
    app_log('webhook', $e->getMessage());
}
