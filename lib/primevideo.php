<?php
/*
 * primevideo.php — klien Indocast /api/primevideo/* (tahap UJI DRM).
 *
 * Video Prime Video memakai DASH + DRM Widevine. Lisensi diminta lewat
 * Indocast (wajib x-api-key), jadi server kita menjadi perantara:
 * api/?a=pv_license meneruskan "challenge" dari pemutar apa adanya.
 * Bentuk jawaban Prime berbeda dari provider lain ("success", bukan "code").
 */
require_once __DIR__ . '/core.php';

function pv_call($method, $path, array $params = [], $ttl = 0, $force = false)
{
    $key = setting('indocast_key');
    if ($key === '') return ['ok' => false, 'data' => null, 'error' => 'API key Indocast belum diisi.'];
    $ckey = 'pv:' . $method . ':' . $path . ':' . json_encode($params);
    if ($ttl > 0 && !$force) {
        $hit = cache_get($ckey);
        if ($hit !== null) { stat_inc('cache_hits'); return ['ok' => true, 'data' => $hit, 'error' => '']; }
    }
    $url = rtrim(setting('indocast_base', 'https://indocast.site/api'), '/') . '/primevideo/' . ltrim($path, '/');
    $h = ['x-api-key: ' . $key, 'Accept: application/json'];
    if ($method === 'GET') {
        $res = http_request('GET', $url . ($params ? '?' . http_build_query($params) : ''), $h, null, 25);
    } else {
        $h[] = 'Content-Type: application/json';
        $res = http_request('POST', $url, $h, $params, 25);
    }
    stat_inc('api_calls');
    $j = $res['json'];
    if (!$j || empty($j['success']) && (!isset($j['status']) || $j['status'] !== 'success')) {
        $msg = $j && isset($j['message']) ? $j['message'] : ($res['error'] ?: 'HTTP ' . $res['code']);
        app_log('primevideo', $method . ' ' . $path . ' ' . json_encode($params) . ' -> ' . $msg);
        return ['ok' => false, 'data' => null, 'error' => (string) $msg];
    }
    if ($ttl > 0) cache_set($ckey, $j, $ttl);
    return ['ok' => true, 'data' => $j, 'error' => ''];
}

/** Data uji: detail judul + link putar episode pertama (atau ke-$ep). */
function pv_test_info($titleId, $ep = 1)
{
    $d = pv_call('GET', 'detail', ['id' => $titleId]);
    if (!$d['ok']) return $d;
    $det = $d['data']['data'];
    $eps = isset($det['seasons'][0]['episodes']) ? $det['seasons'][0]['episodes'] : [];
    if (!$eps) return ['ok' => false, 'error' => 'Judul ini tidak punya video.'];
    $e = $eps[0];
    foreach ($eps as $x) if ((int) $x['episodeNumber'] === (int) $ep) $e = $x;

    $p = pv_call('POST', 'play', ['id' => $e['id'], 'shortid' => $e['shortid']]);
    if (!$p['ok']) return $p;
    $pl = $p['data'];
    $subs = [];
    foreach ((isset($pl['subtitles']) ? $pl['subtitles'] : []) as $s) {
        if (!in_array($s['lang'], ['id', 'en'], true) || empty($s['url'])) continue;
        $subs[$s['lang']] = ['lang' => $s['lang'], 'label' => $s['label'], 'url' => $s['url'], 'proxy' => isset($s['url_proxy']) ? $s['url_proxy'] : '', 'format' => isset($s['format']) ? $s['format'] : ''];
    }
    $lic = sign_params(['t' => 'pvl', 'eid' => $e['id'], 'sid' => $e['shortid']], 21600);
    return ['ok' => true, 'error' => '', 'data' => [
        'title' => $det['title'],
        'episode' => count($eps) > 1 ? 'Episode ' . $e['episodeNumber'] : 'Film',
        'cover' => isset($det['cover']['landscape']) ? $det['cover']['landscape'] : '',
        'drm' => !empty($pl['isDrm']),
        'stream' => isset($pl['dashUrl']) ? $pl['dashUrl'] : (isset($pl['streamUrl']) ? $pl['streamUrl'] : ''),
        'stream_proxy' => isset($pl['dashUrl_proxy']) ? $pl['dashUrl_proxy'] : (isset($pl['streamUrl_proxy']) ? $pl['streamUrl_proxy'] : ''),
        'license' => base_url('api/?a=pv_license&' . http_build_query($lic)),
        'cert' => isset($pl['licenseServers']['certificate_base64']) ? $pl['licenseServers']['certificate_base64'] : '',
        'audio' => isset($pl['audioConf']['displayName']) ? $pl['audioConf']['displayName'] : '',
        'subtitles' => array_values($subs),
    ]];
}

/** Teruskan challenge lisensi DRM ke Indocast, kembalikan jawabannya apa adanya. */
function pv_license_forward($eid, $sid, $body)
{
    $url = rtrim(setting('indocast_base', 'https://indocast.site/api'), '/') . '/primevideo/license?' . http_build_query(['id' => $eid, 'shortid' => $sid]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . setting('indocast_key'), 'Content-Type: application/octet-stream'],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HEADER => false,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = $raw === false ? curl_error($ch) : '';
    curl_close($ch);
    if ($raw === false || $code >= 400) {
        app_log('primevideo', 'license ' . $eid . ' -> HTTP ' . $code . ' ' . $err . ' ' . substr((string) $raw, 0, 200));
    }
    return ['code' => $code ?: 502, 'type' => $type ?: 'application/octet-stream', 'body' => $raw === false ? '' : $raw];
}
