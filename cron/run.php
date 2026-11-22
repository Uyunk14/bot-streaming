<?php
/*
 * Cron utama — jalankan tiap 1 menit:
 *   curl -s "https://domain/cron/run.php?key=KUNCI" >/dev/null 2>&1
 * atau lewat CLI:  php /path/cron/run.php
 *
 * Tugas: cek pembayaran QRIS, kirim broadcast, kabar episode baru,
 * bersihkan cache kedaluwarsa.
 */
require_once dirname(__DIR__) . '/lib/core.php';
require_once dirname(__DIR__) . '/lib/payment.php';
require_once dirname(__DIR__) . '/lib/broadcast.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: application/json');
    $key = isset($_GET['key']) ? (string) $_GET['key'] : '';
    if (!app_installed() || !hash_equals((string) setting('cron_key'), $key)) { http_response_code(403); echo '{"ok":false}'; exit; }
}
set_time_limit(120);
ignore_user_abort(true);

// Kunci supaya dua cron tidak berjalan bersamaan
$lockDir = APP_ROOT . '/storage';
if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);
$lock = fopen($lockDir . '/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo json_encode(['ok' => false, 'msg' => 'cron masih berjalan']); exit; }

$out = ['ok' => true, 'paid' => 0, 'expired' => 0, 'broadcast' => 0, 'episode_notif' => 0];
try {
    setting_set('cron_last', (string) time());

    // 1. Pembayaran pending (yang paling lama dicek lebih dulu)
    $pend = q_all("SELECT * FROM payments WHERE status = 'pending' ORDER BY last_check ASC LIMIT 20");
    foreach ($pend as $p) {
        $n = payment_check($p, true);
        if ($n['status'] === 'paid') $out['paid']++;
        if ($n['status'] === 'expired') $out['expired']++;
    }

    // 1b. Panaskan cache beranda tiap 10 menit supaya pembuka Mini App tidak menunggu Indocast
    $out['home_refreshed'] = [];
    foreach (providers_enabled() as $pid => $info) {
        if (cache_get('warm:' . $pid) !== null) continue;
        $prov = provider_get($pid);
        if ($prov && $prov->home(true)['ok']) {
            cache_set('warm:' . $pid, 1, 600);
            $out['home_refreshed'][] = $pid;
        }
    }

    // 2. Broadcast (maks ±40 detik per menit)
    $out['broadcast'] = broadcast_process(40);

    // 3. Kabar episode baru
    $out['episode_notif'] = new_episode_scan(8);

    // 4. Bersih-bersih
    q("DELETE FROM cache WHERE expires_at < ? LIMIT 2000", [time()]);
    if ((int) date('i') === 0) {
        q("UPDATE payments SET status = 'expired' WHERE status = 'pending' AND expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        q("DELETE FROM views WHERE d < DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
    }
} catch (Exception $e) {
    app_log('cron', $e->getMessage());
    $out = ['ok' => false, 'error' => 'lihat storage/logs/cron.log'];
}
flock($lock, LOCK_UN);
echo json_encode($out);
