<?php
/*
 * API untuk Mini App.  /api/?a=<aksi>
 * Setiap permintaan wajib membawa header X-Init-Data (Telegram.WebApp.initData),
 * kecuali aksi "sub" yang memakai tautan bertanda tangan.
 */
require_once dirname(__DIR__) . '/lib/core.php';
require_once dirname(__DIR__) . '/lib/telegram.php';
require_once dirname(__DIR__) . '/lib/indocast.php';
require_once dirname(__DIR__) . '/lib/payment.php';

if (!app_installed()) json_out(['ok' => false, 'error' => 'Aplikasi belum dipasang.'], 503);

$action = isset($_GET['a']) ? (string) $_GET['a'] : '';
$in = $_GET;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (is_array($body)) $in = array_merge($in, $body);
}
function inp($k, $def = '')
{
    global $in;
    return isset($in[$k]) && !is_array($in[$k]) ? trim((string) $in[$k]) : $def;
}
function fail($msg, $code = 400, $extra = [])
{
    json_out(array_merge(['ok' => false, 'error' => $msg], $extra), $code);
}
function ok($data = [])
{
    json_out(array_merge(['ok' => true], $data));
}

try {
    /* ---------- Subtitle: SRT -> WebVTT (tanpa initData, pakai tanda tangan) ---------- */
    if ($action === 'sub') {
        if (!verify_params(array_intersect_key($_GET, array_flip(['p', 'id', 'path', 'se', 'ep', 'lang', 'x', 's'])))) {
            http_response_code(403);
            exit;
        }
        $prov = provider_get($_GET['p']);
        if (!$prov) { http_response_code(404); exit; }
        $vtt = cache_get('vtt:' . $_GET['p'] . ':' . $_GET['id'] . ':' . $_GET['se'] . ':' . $_GET['ep'] . ':' . $_GET['lang']);
        if ($vtt === null) {
            $r = $prov->play($_GET['id'], $_GET['path'], (int) $_GET['se'], (int) $_GET['ep'], 'in_id');
            $url = '';
            if ($r['ok']) foreach ($r['data']['subtitles'] as $s) if ($s['lang'] === $_GET['lang']) $url = $s['url'];
            $vtt = '';
            if ($url) {
                $res = http_request('GET', $url, [], null, 20);
                if ($res['code'] == 200 && $res['body'] !== '') $vtt = srt_to_vtt($res['body']);
            }
            cache_set('vtt:' . $_GET['p'] . ':' . $_GET['id'] . ':' . $_GET['se'] . ':' . $_GET['ep'] . ':' . $_GET['lang'], $vtt, $vtt ? 21600 : 300);
        }
        header('Content-Type: text/vtt; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('Access-Control-Allow-Origin: *');
        echo $vtt ?: "WEBVTT\n\n";
        exit;
    }

    /* ---------- Pemutar luar (app/pv.html, Prime Video): tautan bertanda tangan dari aksi play ---------- */
    if ($action === 'pv_play' || $action === 'pv_progress') {
        $g = array_intersect_key($_GET, array_flip(['t', 'p', 'id', 'path', 'se', 'ep', 'u', 'pv', 'x', 's']));
        if (!verify_params($g) || $g['t'] !== 'pw') fail('Tautan tonton sudah kedaluwarsa. Buka lagi dari bot Telegram.', 403);
        $prov = provider_get($g['p']);
        if (!$prov || empty($prov->external) || !isset(providers_enabled()[$g['p']])) fail('Sumber film tidak aktif.', 404);
        $u = q_row("SELECT * FROM users WHERE id = ?", [(int) $g['u']]);
        if (!$u || !empty($u['banned'])) fail('Akun tidak ditemukan.', 403);
        $se = (int) $g['se'];
        $ep = (int) $g['ep'];
        $det = $prov->detail($g['id'], $g['path']);
        if (!$det['ok']) fail($det['error'], 502);
        $d = $det['data'];

        if ($action === 'pv_progress') {
            q(
                "INSERT INTO history (user_id, provider, subject_id, detail_path, title, cover, stype, se, ep, position, duration, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE detail_path = VALUES(detail_path), title = VALUES(title), cover = VALUES(cover), stype = VALUES(stype),
                   se = VALUES(se), ep = VALUES(ep), position = VALUES(position), duration = VALUES(duration), updated_at = NOW()",
                [$u['id'], $prov->id, mb_substr($d['id'], 0, 40), $d['path'], mb_substr($d['title'], 0, 255), mb_substr($d['cover'], 0, 500),
                 $d['type'], $se, $ep, max(0, (int) inp('pos')), max(0, (int) inp('dur'))]
            );
            ok();
        }

        $r = $prov->stream($g['id'], $g['path'], $se, $ep);
        if (!$r['ok']) fail($r['error'], 502);
        // Episode berikutnya (hanya bila boleh ditonton: VIP atau termasuk gratis)
        $next = null;
        if ($d['type'] === 2) {
            $list = $prov->episodeList($d);
            foreach ($list as $i => $e) {
                if ($e['se'] !== $se || $e['ep'] !== $ep || !isset($list[$i + 1])) continue;
                $n = $list[$i + 1];
                $okNext = user_is_vip($u) || in_array($n['se'] . ':' . $n['ep'], free_episode_keys($prov, $d), true);
                $next = ['label' => 'Eps ' . $n['ep'], 'url' => $okNext ? pw_link($prov->id, $d['id'], $d['path'], $n['se'], $n['ep'], (int) $u['id'], 0) : ''];
            }
        }
        $h = q_row("SELECT se, ep, position, duration FROM history WHERE user_id = ? AND provider = ? AND subject_id = ?", [$u['id'], $prov->id, $d['id']]);
        $resume = ($h && (int) $h['se'] === $se && (int) $h['ep'] === $ep && $h['position'] > 10 && (!$h['duration'] || $h['position'] < $h['duration'] - 30)) ? (int) $h['position'] : 0;
        ok(['data' => array_merge($r['data'], [
            'title' => $d['title'],
            'label' => $d['type'] === 1 ? 'Film' : 'Season ' . $se . ' · Episode ' . $ep,
            'cover' => $d['cover'],
            'preview' => (int) $g['pv'],
            'resume' => ((int) $g['pv'] && $resume >= (int) $g['pv']) ? 0 : $resume,
            'next' => $next,
            'bot' => setting('bot_username'),
            'color' => setting('primary_color', '#6A4DF4'),
            'app' => setting('bot_name'),
        ])]);
    }

    /* ---------- UJI DRM Prime Video (tautan bertanda tangan dari /drmtest admin) ---------- */
    if ($action === 'pv_info' || $action === 'pv_license') {
        require_once dirname(__DIR__) . '/lib/primevideo.php';
        if ($action === 'pv_info') {
            if (!verify_params(array_intersect_key($_GET, array_flip(['t', 'id', 'ep', 'x', 's']))) || $_GET['t'] !== 'pv') fail('Tautan tes kedaluwarsa. Kirim /drmtest lagi ke bot.', 403);
            $r = pv_test_info($_GET['id'], isset($_GET['ep']) ? (int) $_GET['ep'] : 1);
            if (!$r['ok']) fail($r['error'], 502);
            ok(['data' => $r['data']]);
        }
        if (!verify_params(array_intersect_key($_GET, array_flip(['t', 'eid', 'sid', 'x', 's']))) || $_GET['t'] !== 'pvl') { http_response_code(403); exit; }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        $body = file_get_contents('php://input');
        if ($body === '' || strlen($body) > 65536) { http_response_code(400); exit; }
        $r = pv_license_forward($_GET['eid'], $_GET['sid'], $body);
        http_response_code($r['code']);
        header('Content-Type: ' . $r['type']);
        header('Cache-Control: no-store');
        echo $r['body'];
        exit;
    }

    /* ---------- Playlist HLS (provider berbasis m3u8, mis. iQIYI) ---------- */
    if ($action === 'm3u8') {
        if (!verify_params(array_intersect_key($_GET, array_flip(['p', 'path', 'se', 'ep', 'x', 's'])))) {
            http_response_code(403);
            exit;
        }
        $prov = provider_get($_GET['p']);
        if (!$prov || !method_exists($prov, 'playlist')) { http_response_code(404); exit; }
        $txt = $prov->playlist($_GET['path'], (int) $_GET['se'], (int) $_GET['ep'], !(isset($_GET['via']) && $_GET['via'] === 'p'));
        if ($txt === null) { http_response_code(404); exit; }
        header('Content-Type: application/vnd.apple.mpegurl');
        header('Cache-Control: private, max-age=600');
        header('Access-Control-Allow-Origin: *');
        echo $txt;
        exit;
    }

    /* ---------- Autentikasi Mini App ---------- */
    $initData = isset($_SERVER['HTTP_X_INIT_DATA']) ? $_SERVER['HTTP_X_INIT_DATA'] : '';
    $tgUser = tg_validate_init_data($initData);
    if (!$tgUser) fail('Buka aplikasi ini dari bot Telegram.', 401);
    $user = user_upsert($tgUser);
    if (!empty($user['banned'])) fail('Akun kamu diblokir admin.', 403);
    $isVip = user_is_vip($user);

    $needProvider = in_array($action, ['home', 'search', 'detail', 'play', 'prep'], true);
    $prov = null;
    if ($needProvider) {
        $pid = inp('p', 'filmbox');
        $enabled = providers_enabled();
        if (!isset($enabled[$pid])) fail('Sumber film tidak aktif.');
        $prov = provider_get($pid);
        if (!$prov) fail('Sumber film tidak dikenal.');
    }

    switch ($action) {
        case 'boot':
            $provs = [];
            foreach (providers_enabled() as $id => $info) $provs[] = ['id' => $id, 'name' => $info['name'], 'ext' => !empty($info['external']), 'hls' => !empty($info['hls'])];
            ok([
                'app' => [
                    'name' => setting('bot_name'),
                    'color' => setting('primary_color', '#6A4DF4'),
                    'logo' => setting('logo_url'),
                    'bot' => setting('bot_username'),
                    'free_minutes' => (int) setting('free_minutes', '2'),
                    'free_episodes' => (int) setting('free_episodes', '1'),
                    'providers' => $provs,
                ],
                'user' => user_public($user),
                'gate' => gate_status($user['id']),
            ]);

        case 'gate':
            ok(['gate' => gate_status($user['id'], true)]);

        case 'home':
            $r = $prov->home();
            if (!$r['ok']) fail($r['error'], 502);
            $hist = q_all("SELECT provider, subject_id AS id, detail_path AS path, title, cover, stype AS type, se, ep, position, duration
                           FROM history WHERE user_id = ? ORDER BY updated_at DESC LIMIT 10", [$user['id']]);
            ok(['data' => $r['data'], 'continue' => $hist]);

        case 'search':
            $kw = mb_substr(inp('q'), 0, 80);
            if (mb_strlen($kw) < 2) ok(['data' => ['items' => [], 'has_more' => false, 'total' => 0]]);
            $type = (int) inp('type', '0');
            $r = $prov->search($kw, (int) inp('page', '1'), in_array($type, [0, 1, 2], true) ? $type : 0);
            if (!$r['ok']) fail($r['error'], 502);
            ok(['data' => $r['data']]);

        case 'prep':
            // Dipanggil diam-diam saat halaman detail dibuka: link video episode pertama/lanjutan
            // diambil lebih dulu ke cache, jadi saat Tonton ditekan tidak menunggu Indocast lagi.
            $det = $prov->detail(inp('id'), inp('path'));
            if (!$det['ok']) ok(['ready' => false]);
            $d = $det['data'];
            $se = (int) inp('se', '0');
            $ep = (int) inp('ep', '0');
            if ($d['type'] === 1) { $se = 0; $ep = 0; }
            elseif (!episode_exists($prov, $d, $se, $ep)) ok(['ready' => false]);
            if (!$isVip && $d['type'] === 2 && !in_array($se . ':' . $ep, free_episode_keys($prov, $d), true)) ok(['ready' => false]);
            if (method_exists($prov, 'stream')) $r = $prov->stream(inp('id'), $d['path'], $se, $ep);
            else $r = $prov->play(inp('id'), inp('path'), $se, $ep, 'in_id');
            ok(['ready' => (bool) $r['ok']]);

        case 'detail':
            $r = $prov->detail(inp('id'), inp('path'));
            if (!$r['ok']) fail($r['error'], 502);
            $d = $r['data'];
            $free = free_episode_keys($prov, $d);
            $d['free'] = $free;
            $d['is_fav'] = (bool) q_val("SELECT 1 FROM favorites WHERE user_id = ? AND provider = ? AND subject_id = ?", [$user['id'], $prov->id, $d['id']]);
            $d['history'] = q_row("SELECT se, ep, position, duration FROM history WHERE user_id = ? AND provider = ? AND subject_id = ?", [$user['id'], $prov->id, $d['id']]);
            ok(['data' => $d, 'user' => user_public($user)]);

        case 'play':
            $gate = gate_status($user['id']);
            if (!$gate['ok']) fail('Gabung channel & grup dulu.', 403, ['gate' => $gate]);
            $id = inp('id');
            $path = inp('path');
            $det = $prov->detail($id, $path);
            if (!$det['ok']) fail($det['error'], 502);
            $d = $det['data'];
            $se = (int) inp('se', '0');
            $ep = (int) inp('ep', '0');
            if ($d['type'] === 1) { $se = 0; $ep = 0; }
            elseif (!episode_exists($prov, $d, $se, $ep)) fail('Episode tidak ditemukan.', 404);

            $preview = 0;
            if (!$isVip) {
                if ($d['type'] === 1) {
                    $preview = max(0, (int) setting('free_minutes', '2')) * 60;
                    if ($preview === 0) fail('Film ini khusus VIP.', 402, ['need_vip' => true]);
                } elseif (!in_array($se . ':' . $ep, free_episode_keys($prov, $d), true)) {
                    fail('Episode ini khusus VIP.', 402, ['need_vip' => true]);
                }
            }
            if (!empty($prov->external)) {
                // Video ber-DRM (Prime Video): diputar di Chrome lewat app/pv.html, tautan bertanda tangan 3 jam
                stat_inc('plays');
                q("INSERT INTO views (d, provider, subject_id, title, n) VALUES (CURDATE(), ?, ?, ?, 1) ON DUPLICATE KEY UPDATE n = n + 1, title = VALUES(title)", [$prov->id, $d['id'], mb_substr($d['title'], 0, 255)]);
                ok(['data' => [
                    'external' => pw_link($prov->id, $id, $d['path'], $se, $ep, (int) $user['id'], $preview),
                    'sources' => [], 'subtitles' => [], 'preview' => $preview, 'se' => $se, 'ep' => $ep,
                ]]);
            }
            $r = $prov->play($id, $path, $se, $ep, 'in_id');
            if (!$r['ok']) fail($r['error'], empty($r['unavailable']) ? 502 : 404);
            if (!$r['data']['sources']) fail('Video belum tersedia, coba episode lain.', 404);
            $subs = [];
            foreach ($r['data']['subtitles'] as $s) {
                $q = sign_params(['p' => $prov->id, 'id' => $id, 'path' => $path, 'se' => $se, 'ep' => $ep, 'lang' => $s['lang']], 10800);
                $subs[] = ['lang' => $s['lang'], 'label' => $s['label'], 'url' => base_url('api/?a=sub&' . http_build_query($q))];
            }
            // Urutkan: Indonesia dulu, lalu English
            usort($subs, function ($a, $b) {
                $rank = function ($l) { return ($l === 'in_id' || $l === 'id') ? 0 : (($l === 'en' || $l === 'en-US') ? 1 : 2); };
                return $rank($a['lang']) - $rank($b['lang']);
            });
            stat_inc('plays');
            q("INSERT INTO views (d, provider, subject_id, title, n) VALUES (CURDATE(), ?, ?, ?, 1) ON DUPLICATE KEY UPDATE n = n + 1, title = VALUES(title)", [$prov->id, $d['id'], mb_substr($d['title'], 0, 255)]);
            ok(['data' => [
                'sources' => array_map(function ($s) use ($prov, $path, $se, $ep) {
                    if (empty($s['hls'])) return ['res' => $s['res'], 'url' => $s['url'], 'proxy' => $s['proxy']];
                    // Playlist disajikan server kita (tautan bertanda tangan, berlaku 3 jam)
                    $u = base_url('api/?a=m3u8&' . http_build_query(sign_params(['p' => $prov->id, 'path' => $path, 'se' => $se, 'ep' => $ep], 10800)));
                    return ['res' => $s['res'], 'url' => $u, 'proxy' => $u . '&via=p', 'hls' => true];
                }, $r['data']['sources']),
                'subtitles' => $subs,
                'preview' => $preview,
                'se' => $se,
                'ep' => $ep,
            ]]);

        case 'progress':
            $pid = inp('p', 'filmbox');
            if (!isset(provider_registry()[$pid])) fail('Provider salah.');
            q(
                "INSERT INTO history (user_id, provider, subject_id, detail_path, title, cover, stype, se, ep, position, duration, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE detail_path = VALUES(detail_path), title = VALUES(title), cover = VALUES(cover), stype = VALUES(stype),
                   se = VALUES(se), ep = VALUES(ep), position = VALUES(position), duration = VALUES(duration), updated_at = NOW()",
                [$user['id'], $pid, mb_substr(inp('id'), 0, 40), mb_substr(inp('path'), 0, 255), mb_substr(inp('title'), 0, 255), mb_substr(inp('cover'), 0, 500),
                 (int) inp('type', '1'), (int) inp('se'), (int) inp('ep'), max(0, (int) inp('pos')), max(0, (int) inp('dur'))]
            );
            ok();

        case 'history':
            ok(['items' => q_all("SELECT provider, subject_id AS id, detail_path AS path, title, cover, stype AS type, se, ep, position, duration, updated_at
                                  FROM history WHERE user_id = ? ORDER BY updated_at DESC LIMIT 50", [$user['id']])]);

        case 'history_del':
            q("DELETE FROM history WHERE user_id = ? AND provider = ? AND subject_id = ?", [$user['id'], inp('p'), inp('id')]);
            ok();

        case 'favorites':
            ok(['items' => q_all("SELECT provider, subject_id AS id, detail_path AS path, title, cover, stype AS type
                                  FROM favorites WHERE user_id = ? ORDER BY created_at DESC LIMIT 100", [$user['id']])]);

        case 'fav':
            $pid = inp('p', 'filmbox');
            if (!isset(provider_registry()[$pid])) fail('Provider salah.');
            $sid = mb_substr(inp('id'), 0, 40);
            if ($sid === '') fail('Data kurang.');
            if (q_val("SELECT 1 FROM favorites WHERE user_id = ? AND provider = ? AND subject_id = ?", [$user['id'], $pid, $sid])) {
                q("DELETE FROM favorites WHERE user_id = ? AND provider = ? AND subject_id = ?", [$user['id'], $pid, $sid]);
                ok(['is_fav' => false]);
            }
            $type = (int) inp('type', '1');
            q("INSERT INTO favorites (user_id, provider, subject_id, detail_path, title, cover, stype, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                [$user['id'], $pid, $sid, mb_substr(inp('path'), 0, 255), mb_substr(inp('title'), 0, 255), mb_substr(inp('cover'), 0, 500), $type]);
            if ($type === 2) watch_subject_add($pid, $sid, inp('path'), inp('title'), inp('cover'), (int) inp('eps', '0'));
            ok(['is_fav' => true]);

        case 'notify':
            q("UPDATE users SET notify = ? WHERE id = ?", [inp('on') === '1' ? 1 : 0, $user['id']]);
            ok(['notify' => inp('on') === '1']);

        case 'plans':
            ok(['plans' => plans_active(), 'user' => user_public($user)]);

        case 'pay':
            $r = payment_create($user, (int) inp('plan'));
            if (!$r['ok']) fail($r['error']);
            ok(['payment' => payment_public($r['payment'])]);

        case 'pay_status':
            $p = q_row("SELECT * FROM payments WHERE ref = ? AND user_id = ?", [inp('ref'), $user['id']]);
            if (!$p) fail('Tagihan tidak ditemukan.', 404);
            $p = payment_check($p);
            $fresh = q_row("SELECT * FROM users WHERE id = ?", [$user['id']]);
            ok(['payment' => payment_public($p), 'user' => user_public($fresh)]);

        case 'pay_cancel':
            q("UPDATE payments SET status = 'cancel' WHERE ref = ? AND user_id = ? AND status = 'pending'", [inp('ref'), $user['id']]);
            ok();

        default:
            fail('Aksi tidak dikenal.', 404);
    }
} catch (Exception $e) {
    app_log('api', $action . ' ' . $e->getMessage());
    fail('Terjadi gangguan di server, coba lagi.', 500);
}

/* ---------- Helper khusus API ---------- */
function user_public(array $u)
{
    return [
        'id' => (int) $u['id'],
        'name' => trim($u['first_name'] . ' ' . $u['last_name']),
        'username' => $u['username'],
        'vip' => user_is_vip($u),
        'vip_until' => $u['vip_until'] ? strtotime($u['vip_until']) : 0,
        'notify' => (bool) $u['notify'],
    ];
}

/** Tautan pemutar luar (app/pv.html) bertanda tangan, berlaku 3 jam. $pv = batas detik gratis (0 = penuh). */
function pw_link($pid, $id, $path, $se, $ep, $uid, $pv)
{
    return base_url('app/pv.html?' . http_build_query(sign_params([
        't' => 'pw', 'p' => $pid, 'id' => (string) $id, 'path' => (string) $path, 'se' => (int) $se, 'ep' => (int) $ep, 'u' => (int) $uid, 'pv' => (int) $pv,
    ], 10800)));
}

/** Kunci "se:ep" episode gratis = N episode pertama dari season pertama. */
function free_episode_keys(BaseProvider $prov, array $d)
{
    if ($d['type'] === 1) return [];
    $n = max(0, (int) setting('free_episodes', '1'));
    $keys = [];
    foreach (array_slice($prov->episodeList($d), 0, $n) as $e) $keys[] = $e['se'] . ':' . $e['ep'];
    return $keys;
}

function episode_exists(BaseProvider $prov, array $d, $se, $ep)
{
    foreach ($prov->episodeList($d) as $e) if ($e['se'] === $se && $e['ep'] === $ep) return true;
    return false;
}

function watch_subject_add($pid, $sid, $path, $title, $cover, $eps)
{
    q("INSERT IGNORE INTO watch_subjects (provider, subject_id, detail_path, title, cover, ep_count, checked_at) VALUES (?, ?, ?, ?, ?, ?, NOW())",
        [$pid, $sid, mb_substr($path, 0, 255), mb_substr($title, 0, 255), mb_substr($cover, 0, 500), (int) $eps]);
}

function srt_to_vtt($srt)
{
    $srt = preg_replace('/^\xEF\xBB\xBF/', '', $srt);
    if (!mb_check_encoding($srt, 'UTF-8')) $srt = mb_convert_encoding($srt, 'UTF-8', 'Windows-1252');
    $srt = str_replace(["\r\n", "\r"], "\n", $srt);
    $srt = preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $srt);
    return "WEBVTT\n\n" . trim($srt) . "\n";
}
