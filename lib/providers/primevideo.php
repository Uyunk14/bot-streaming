<?php
/*
 * Provider Prime Video (Indocast /api/primevideo/*).
 *
 * Video memakai DASH + DRM Widevine. WebView Mini App Telegram tidak mengizinkan
 * DRM, jadi provider ini "eksternal": tombol putar di Mini App membuka
 * app/pv.html (tautan bertanda tangan) yang diputar di Chrome Android.
 * iPhone/iPad (FairPlay saja) tidak bisa memutar.
 *
 * - id   = id judul Amazon (mis. B0C57F8RYQ)
 * - path = "movie" / "series" (jenis; detail Indocast tidak menyebut jenis)
 */
require_once dirname(__DIR__) . '/indocast.php';
require_once dirname(__DIR__) . '/primevideo.php';

class Provider_primevideo extends BaseProvider
{
    public $id = 'primevideo';
    public $name = 'Prime Video';
    public $external = true;

    /** Gambar Amazon: buang tempelan ukuran/logo -> URL dasar .jpg (diperkecil di app lewat ._SX..._). */
    public static function pic($u)
    {
        $u = (string) $u;
        if (preg_match('#^(https://[a-z0-9.-]+amazon\.com/images/S/(?:pv-target-images/[0-9a-f]{32,80}|sonata-images-prod/[^/?]+/[0-9a-f-]{36}))(?:\..*)?\.(jpe?g|png)$#i', $u, $m)) return $m[1] . '.' . strtolower($m[2]);
        return $u;
    }

    private static function err(array $r)
    {
        $r['error'] = 'Sumber film sedang bermasalah (' . $r['error'] . ')';
        return $r;
    }

    public static function item(array $r)
    {
        $type = (isset($r['type']) && $r['type'] === 'movie') ? 1 : 2;
        return [
            'id' => (string) (isset($r['id']) ? $r['id'] : ''),
            'path' => $type === 1 ? 'movie' : 'series',
            'type' => $type,
            'title' => trim((string) (isset($r['title']) ? $r['title'] : '')),
            'cover' => self::pic(isset($r['thumbnail']) ? $r['thumbnail'] : ''),
            'wide' => true,
            'rating' => 0,
            'genre' => (string) (isset($r['maturityRating']) && $r['maturityRating'] !== '' ? $r['maturityRating'] . '+' : ''),
            'year' => (string) (isset($r['releaseYear']) ? $r['releaseYear'] : ''),
            'country' => '',
            'badge' => '',
            'soon' => '',
        ];
    }

    private static function sectionsOf($d, &$seen)
    {
        $out = [];
        foreach ((isset($d['sections']) ? $d['sections'] : []) as $s) {
            $items = [];
            foreach ((isset($s['items']) ? $s['items'] : []) as $raw) {
                $it = self::item($raw);
                if ($it['id'] === '' || isset($seen[$s['sectionName'] . $it['id']])) continue;
                $seen[$s['sectionName'] . $it['id']] = 1;
                unset($it['soon'], $it['country'], $it['badge']);
                $items[] = $it;
                if (count($items) >= 15) break;
            }
            if ($items) $out[] = ['id' => (string) (isset($s['sectionId']) ? $s['sectionId'] : md5($s['sectionName'])), 'title' => trim((string) $s['sectionName']), 'items' => $items];
        }
        return $out;
    }

    public function home($force = false)
    {
        $ck = 'home:primevideo:v1';
        if (!$force) {
            $hit = cache_get($ck);
            if ($hit !== null) { stat_inc('cache_hits'); return ['ok' => true, 'data' => $hit, 'error' => '']; }
        }
        $m = pv_call('GET', 'home', [], 0, true);   // film
        $t = pv_call('GET', 'tv', [], 0, true);     // serial
        if (!$m['ok'] && !$t['ok']) {
            $old = cache_get($ck . ':backup');
            return $old !== null ? ['ok' => true, 'data' => $old, 'error' => ''] : self::err($m);
        }
        $hero = [];
        foreach ([$m, $t] as $r) {
            if (!$r['ok']) continue;
            foreach (array_slice(isset($r['data']['banners']) ? $r['data']['banners'] : [], 0, 4) as $b) {
                $it = self::item($b);
                if ($it['id'] !== '' && $it['cover'] !== '') $hero[] = $it;
            }
        }
        $seen = [];
        $ms = $m['ok'] ? self::sectionsOf($m['data'], $seen) : [];
        $ts = $t['ok'] ? self::sectionsOf($t['data'], $seen) : [];
        // Selang-seling film & serial supaya keduanya terlihat di atas
        $sections = [];
        for ($i = 0; $i < max(count($ms), count($ts)); $i++) {
            if (isset($ms[$i])) $sections[] = $ms[$i];
            if (isset($ts[$i])) $sections[] = $ts[$i];
        }
        $data = ['hero' => array_slice($hero, 0, 6), 'sections' => array_slice($sections, 0, 12)];
        cache_set($ck, $data, 21600);
        cache_set($ck . ':backup', $data, 7 * 86400);
        return ['ok' => true, 'data' => $data, 'error' => ''];
    }

    public function search($keyword, $page, $type)
    {
        if ((int) $page > 1) return ['ok' => true, 'data' => ['items' => [], 'has_more' => false, 'total' => 0], 'error' => ''];
        $r = pv_call('GET', 'search', ['q' => (string) $keyword], 1800);
        if (!$r['ok']) return self::err($r);
        $items = [];
        foreach ((isset($r['data']['results']) ? $r['data']['results'] : []) as $raw) {
            $it = self::item($raw);
            if ($it['id'] === '' || ($type && $it['type'] !== (int) $type)) continue;
            $items[] = $it;
        }
        return ['ok' => true, 'data' => ['items' => $items, 'has_more' => false, 'total' => count($items)], 'error' => ''];
    }

    private function raw($id, $ttl = 3600)
    {
        $r = pv_call('GET', 'detail', ['id' => (string) $id], $ttl);
        if (!$r['ok']) return self::err($r);
        if (empty($r['data']['data'])) return ['ok' => false, 'data' => null, 'error' => 'Judul tidak ditemukan.'];
        return ['ok' => true, 'data' => $r['data']['data'], 'error' => ''];
    }

    public function detail($id, $path, $ttl = 3600)
    {
        $r = $this->raw($id, $ttl);
        if (!$r['ok']) return $r;
        $d = $r['data'];
        $count = 0;
        $seasons = [];
        foreach ((isset($d['seasons']) ? $d['seasons'] : []) as $s) {
            $eps = [];
            foreach ((isset($s['episodes']) ? $s['episodes'] : []) as $e) if (!empty($e['id'])) $eps[] = (int) $e['episodeNumber'];
            $eps = array_values(array_unique($eps));
            sort($eps);
            $count += count($eps);
            if ($eps) $seasons[] = ['season' => (int) (isset($s['seasonNumber']) ? $s['seasonNumber'] : 1), 'episodes' => $eps];
        }
        $isMovie = $path === 'movie' || ($path !== 'series' && $count === 1);
        $cover = isset($d['cover']['potrait']) ? $d['cover']['potrait'] : '';
        $genres = isset($d['genres']) && is_array($d['genres']) ? array_slice($d['genres'], 0, 3) : [];
        $it = [
            'id' => (string) (isset($d['id']) ? $d['id'] : $id),
            'path' => $isMovie ? 'movie' : 'series',
            'type' => $isMovie ? 1 : 2,
            'title' => trim((string) $d['title']),
            'cover' => self::pic($cover),
            'rating' => !empty($d['voteAverage']) ? round((float) $d['voteAverage'], 1) : 0,
            'genre' => implode(', ', $genres),
            'year' => (string) (isset($d['releaseYear']) ? $d['releaseYear'] : ''),
            'country' => '',
            'badge' => '',
            'soon' => '',
            'description' => trim((string) (isset($d['overview']) ? $d['overview'] : '')),
            'languages' => '',
            'trailer' => '',
            'external' => true,
        ];
        $it['seasons'] = $isMovie ? [['season' => 1, 'episodes' => [0]]] : ($seasons ?: [['season' => 1, 'episodes' => []]]);
        return ['ok' => true, 'data' => $it, 'error' => ''];
    }

    /** Diputar di luar Mini App (lihat api/?a=play -> external). */
    public function play($id, $path, $se, $ep, $lang = '')
    {
        return ['ok' => true, 'data' => ['sources' => [['res' => 0, 'url' => '', 'proxy' => '', 'external' => true]], 'subtitles' => [], 'format' => 'DASH-DRM'], 'error' => ''];
    }

    /** Data putar untuk app/pv.html: link DASH, lisensi (lewat server kita), subtitle. */
    public function stream($id, $path, $se, $ep)
    {
        $r = $this->raw($id);
        if (!$r['ok']) return $r;
        $d = $r['data'];
        $e = null;
        foreach ((isset($d['seasons']) ? $d['seasons'] : []) as $s) {
            foreach ((isset($s['episodes']) ? $s['episodes'] : []) as $x) {
                if (empty($x['id'])) continue;
                if ((int) $ep === 0) { $e = $x; break 2; }
                if ((int) (isset($s['seasonNumber']) ? $s['seasonNumber'] : 1) === (int) $se && (int) $x['episodeNumber'] === (int) $ep) { $e = $x; break 2; }
            }
        }
        if (!$e) return ['ok' => false, 'data' => null, 'error' => 'Episode tidak ditemukan.'];
        $p = pv_call('POST', 'play', ['id' => $e['id'], 'shortid' => (string) $e['shortid']], 1800);
        if (!$p['ok']) return self::err($p);
        $pl = $p['data'];
        $stream = isset($pl['dashUrl']) ? $pl['dashUrl'] : (isset($pl['streamUrl']) ? $pl['streamUrl'] : '');
        if ($stream === '') return ['ok' => false, 'data' => null, 'error' => 'Video belum tersedia.'];
        $subs = [];
        foreach ((isset($pl['subtitles']) ? $pl['subtitles'] : []) as $s) {
            if (empty($s['url']) || !in_array($s['lang'], ['id', 'en'], true) || isset($subs[$s['lang']])) continue;
            $subs[$s['lang']] = ['lang' => $s['lang'], 'label' => $s['lang'] === 'id' ? 'Indonesia' : 'English', 'url' => $s['url'], 'proxy' => isset($s['url_proxy']) ? $s['url_proxy'] : ''];
        }
        ksort($subs); // en, id -> diurutkan ulang di bawah (Indonesia dulu)
        $subs = array_values(array_reverse($subs));
        $lic = sign_params(['t' => 'pvl', 'eid' => $e['id'], 'sid' => (string) $e['shortid']], 21600);
        return ['ok' => true, 'error' => '', 'data' => [
            'stream' => $stream,
            'stream_proxy' => isset($pl['dashUrl_proxy']) ? $pl['dashUrl_proxy'] : (isset($pl['streamUrl_proxy']) ? $pl['streamUrl_proxy'] : ''),
            'license' => base_url('api/?a=pv_license&' . http_build_query($lic)),
            'subtitles' => $subs,
            'duration' => isset($e['durationSeconds']) ? (int) $e['durationSeconds'] : 0,
            'ep_title' => isset($e['title']) ? (string) $e['title'] : '',
        ]];
    }
}
