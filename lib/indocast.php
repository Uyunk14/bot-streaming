<?php
/*
 * indocast.php — klien API Indocast + cache + daftar provider.
 *
 * Menambah provider baru: buat file lib/providers/<id>.php berisi class
 * Provider_<id> extends BaseProvider, lalu daftarkan di provider_registry().
 */
require_once __DIR__ . '/core.php';

/**
 * Panggil API Indocast dengan cache.
 * @return array ['ok'=>bool, 'data'=>mixed, 'error'=>string]
 */
function indocast_call($method, $path, array $params = [], $ttl = 600, $force = false)
{
    $key = setting('indocast_key');
    if ($key === '') return ['ok' => false, 'data' => null, 'error' => 'API key Indocast belum diisi di admin.'];

    $base = rtrim(setting('indocast_base', 'https://indocast.site/api'), '/');
    $url = $base . '/' . ltrim($path, '/');
    $ckey = 'ic:' . $method . ':' . $path . ':' . json_encode($params);

    if ($ttl > 0 && !$force) {
        $hit = cache_get($ckey);
        if ($hit !== null) {
            stat_inc('cache_hits');
            return ['ok' => true, 'data' => $hit, 'error' => ''];
        }
    }

    $headers = ['x-api-key: ' . $key, 'Accept: application/json'];
    if ($method === 'GET') {
        if ($params) $url .= '?' . http_build_query($params);
        $res = http_request('GET', $url, $headers, null, 25);
    } else {
        $headers[] = 'Content-Type: application/json';
        $res = http_request('POST', $url, $headers, $params, 25);
    }
    stat_inc('api_calls');

    $j = $res['json'];
    if (!$j || !isset($j['code']) || (int) $j['code'] !== 0) {
        $msg = $j && isset($j['message']) ? $j['message'] : ($res['error'] ?: 'HTTP ' . $res['code']);
        app_log('indocast', $method . ' ' . $path . ' ' . json_encode($params) . ' -> ' . $msg);
        return ['ok' => false, 'data' => null, 'error' => 'Sumber film sedang bermasalah (' . $msg . ')'];
    }
    $data = isset($j['data']) ? $j['data'] : null;
    if ($ttl > 0 && $data !== null) cache_set($ckey, $data, $ttl);
    return ['ok' => true, 'data' => $data, 'error' => ''];
}

abstract class BaseProvider
{
    public $id = '';
    public $name = '';
    /** @param bool $force ambil ulang dari Indocast (dipakai cron untuk memanaskan cache) */
    abstract public function home($force = false);
    abstract public function search($keyword, $page, $type);
    abstract public function detail($id, $path);
    abstract public function play($id, $path, $se, $ep, $lang);

    /** Daftar episode berurutan [ ['se'=>..,'ep'=>..], ... ] dari detail. */
    public function episodeList(array $detail)
    {
        $out = [];
        foreach ($detail['seasons'] as $s) {
            foreach ($s['episodes'] as $ep) $out[] = ['se' => (int) $s['season'], 'ep' => (int) $ep];
        }
        return $out;
    }
}

/** Daftar semua provider yang tersedia di kode (id => [nama, file]). */
function provider_registry()
{
    return [
        'filmbox' => ['name' => 'Filmbox', 'note' => 'Film, series, anime'],
        // 'drakorid' => ['name' => 'DrakorID', 'note' => 'menyusul'],
    ];
}

/** Provider yang diaktifkan admin. */
function providers_enabled()
{
    $on = json_decode(setting('providers', '{}'), true);
    if (!is_array($on)) $on = [];
    $out = [];
    foreach (provider_registry() as $id => $info) {
        if (!empty($on[$id])) $out[$id] = $info;
    }
    return $out;
}

/** @return BaseProvider|null */
function provider_get($id)
{
    $id = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $id));
    $reg = provider_registry();
    if (!isset($reg[$id])) return null;
    $file = __DIR__ . '/providers/' . $id . '.php';
    if (!is_file($file)) return null;
    require_once $file;
    $cls = 'Provider_' . $id;
    return class_exists($cls) ? new $cls() : null;
}
