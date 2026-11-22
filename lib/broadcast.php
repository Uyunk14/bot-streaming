<?php
/*
 * broadcast.php — pengiriman broadcast bertahap & kabar episode baru.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/indocast.php';

function audience_where($a)
{
    if ($a === 'vip') return "blocked = 0 AND banned = 0 AND vip_until > NOW()";
    if ($a === 'free') return "blocked = 0 AND banned = 0 AND (vip_until IS NULL OR vip_until <= NOW())";
    return "blocked = 0 AND banned = 0";
}

/**
 * Kirim satu pesan broadcast. Mengembalikan true, atau teks error.
 * Mengembalikan 'retry:<detik>' bila kena batas kecepatan Telegram.
 */
function broadcast_send_one($chatId, array $b)
{
    $markup = $b['button_text'] !== '' ? tg_app_button($b['button_text']) : null;
    if ($b['photo'] !== '') {
        $p = ['chat_id' => $chatId, 'photo' => $b['photo'], 'caption' => $b['text'], 'parse_mode' => 'HTML'];
        if ($markup) $p['reply_markup'] = $markup;
        $r = tg('sendPhoto', $p);
    } else {
        $r = tg_send($chatId, $b['text'], $markup);
    }
    if (!empty($r['ok'])) return true;
    if (isset($r['error_code']) && (int) $r['error_code'] === 429) {
        return 'retry:' . (isset($r['parameters']['retry_after']) ? (int) $r['parameters']['retry_after'] : 5);
    }
    if (isset($r['error_code']) && (int) $r['error_code'] === 403) {
        q("UPDATE users SET blocked = 1 WHERE id = ?", [(int) $chatId]); // user memblokir bot
    }
    return isset($r['description']) ? $r['description'] : 'gagal';
}

/** Proses antrean broadcast selama maksimal $seconds detik. */
function broadcast_process($seconds = 40)
{
    $deadline = microtime(true) + $seconds;
    $b = q_row("SELECT * FROM broadcasts WHERE status IN ('sending','queued') ORDER BY id ASC LIMIT 1");
    if (!$b) return 0;
    if ($b['status'] === 'queued') q("UPDATE broadcasts SET status = 'sending' WHERE id = ?", [$b['id']]);
    $sent = 0;
    while (microtime(true) < $deadline) {
        $users = q_all("SELECT id FROM users WHERE id > ? AND " . audience_where($b['audience']) . " ORDER BY id ASC LIMIT 25", [(int) $b['last_user']]);
        if (!$users) {
            q("UPDATE broadcasts SET status = 'done', finished_at = NOW() WHERE id = ?", [$b['id']]);
            break;
        }
        foreach ($users as $u) {
            // Berhenti bila admin menekan "Hentikan"
            if (q_val("SELECT status FROM broadcasts WHERE id = ?", [$b['id']]) === 'stopped') return $sent;
            $r = broadcast_send_one($u['id'], $b);
            if (is_string($r) && strpos($r, 'retry:') === 0) { sleep(min(30, (int) substr($r, 6))); return $sent; }
            $col = $r === true ? 'sent' : 'failed';
            q("UPDATE broadcasts SET $col = $col + 1, last_user = ? WHERE id = ?", [(int) $u['id'], $b['id']]);
            $b['last_user'] = $u['id'];
            $sent++;
            usleep(40000); // ±25 pesan per detik
            if (microtime(true) >= $deadline) break;
        }
    }
    return $sent;
}

/**
 * Cek judul series yang difavoritkan; bila jumlah episode bertambah,
 * kabari user yang memfavoritkannya (notify = 1).
 */
function new_episode_scan($limit = 8)
{
    $subs = q_all("SELECT * FROM watch_subjects WHERE checked_at < DATE_SUB(NOW(), INTERVAL 3 HOUR) ORDER BY checked_at ASC LIMIT " . (int) $limit);
    $notified = 0;
    foreach ($subs as $s) {
        q("UPDATE watch_subjects SET checked_at = NOW() WHERE provider = ? AND subject_id = ?", [$s['provider'], $s['subject_id']]);
        $fans = (int) q_val("SELECT COUNT(*) FROM favorites WHERE provider = ? AND subject_id = ?", [$s['provider'], $s['subject_id']]);
        if (!$fans) { q("DELETE FROM watch_subjects WHERE provider = ? AND subject_id = ?", [$s['provider'], $s['subject_id']]); continue; }
        $prov = provider_get($s['provider']);
        if (!$prov) continue;
        $r = $prov->detail($s['subject_id'], $s['detail_path'], 600);
        if (!$r['ok']) continue;
        $eps = $prov->episodeList($r['data']);
        $count = count($eps);
        if ($count > (int) $s['ep_count'] && (int) $s['ep_count'] > 0) {
            $last = end($eps);
            $text = "🆕 <b>" . e($r['data']['title']) . "</b>\nEpisode baru sudah tayang: Season " . $last['se'] . " Episode " . $last['ep'] . ".";
            $btn = tg_app_button('▶️ Tonton sekarang', '/d/' . rawurlencode($s['provider']) . '/' . rawurlencode($s['subject_id']) . '/' . rawurlencode($s['detail_path']));
            $users = q_all("SELECT u.id FROM favorites f JOIN users u ON u.id = f.user_id
                            WHERE f.provider = ? AND f.subject_id = ? AND u.notify = 1 AND u.blocked = 0 AND u.banned = 0", [$s['provider'], $s['subject_id']]);
            foreach ($users as $u) {
                $res = tg_send($u['id'], $text, $btn);
                if (empty($res['ok']) && isset($res['error_code']) && (int) $res['error_code'] === 403) q("UPDATE users SET blocked = 1 WHERE id = ?", [$u['id']]);
                $notified++;
                usleep(40000);
            }
        }
        if ($count !== (int) $s['ep_count']) {
            q("UPDATE watch_subjects SET ep_count = ? WHERE provider = ? AND subject_id = ?", [$count, $s['provider'], $s['subject_id']]);
        }
    }
    return $notified;
}
