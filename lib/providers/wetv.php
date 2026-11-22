<?php
/*
 * Provider WeTV (Indocast /api/wetv/*).
 *
 * - id = path = cid WeTV. Episode diberi nomor urut 1..N (nomor asli WeTV bisa "0-1"/Part),
 *   vid tiap episode dicari lagi dari detail.
 * - Video HLS tanpa enkripsi: playlist dari Indocast (/wetv/stream, tanpa key, CORS terbuka),
 *   segmen langsung ke CDN WeTV. Beberapa kualitas (144p..720p). Subtitle SRT.
 * - Judul "Sewa" (rental), Trailer, Teaser tidak bisa diputar -> dilewati.
 */
require_once dirname(__DIR__) . '/indocast.php';
require_once dirname(__DIR__) . '/imgproxy.php';

class Provider_wetv extends BaseProvider
{
    public $id = 'wetv';
    public $name = 'WeTV';
    const LANG = 'id';

    private static function skip(array $r)
    {
        $b = isset($r['badge']) ? (string) $r['badge'] : '';
        $id = isset($r['id']) ? (string) $r['id'] : '';
        return $id === '' || strpos($id, '_') !== false || in_array($b, ['Sewa', 'Trailer', 'Teaser'], true);
    }

    /** Gambar WeTV: akhiran /0 = ukuran asli (±600 KB); app memperkecil lewat thumb(). */
    private static function vpic($u)
    {
        return preg_replace('#/(\d+)$#', '/0', (string) $u);
    }

    public static function item(array $r, $wide = false)
    {
        $n = isset($r['episodeTotal']) ? (int) $r['episodeTotal'] : (isset($r['episodes']) && !is_array($r['episodes']) ? (int) $r['episodes'] : 0);
        $score = isset($r['score']) && is_numeric($r['score']) ? round((float) $r['score'], 1) : 0;
        $cover = $wide
            // banner WeTV cuma ada ukuran 1080p (±216 KB) -> diperkecil + WebP lewat server (±30 KB)
            ? img_url(preg_replace('#/(\d+)$#', '/1080', (string) (isset($r['coverHorizontal']) ? $r['coverHorizontal'] : '')), 720)
            : self::vpic(isset($r['coverVertical']) ? $r['coverVertical'] : '');
        return [
            'id' => (string) $r['id'],
            'path' => (string) $r['id'],
            'type' => $n === 1 ? 1 : 2,
            'title' => trim((string) (isset($r['title']) ? $r['title'] : '')),
            'cover' => $cover,
            'rating' => $score,
            'genre' => (string) (isset($r['region']) ? $r['region'] : ''),
            'year' => (string) (isset($r['year']) ? $r['year'] : ''),
            'country' => '',
            'badge' => '',
            'soon' => '',
        ];
    }

    private static function listOf($rows, &$ids, $max = 15)
    {
        $out = [];
        foreach ((array) $rows as $r) {
            if (!is_array($r) || self::skip($r)) continue;
            $it = self::item($r);
            if (isset($ids[$it['id']]) || $it['title'] === '') continue;
            $ids[$it['id']] = 1;
            unset($it['soon'], $it['country'], $it['badge']);
            $out[] = $it;
            if (count($out) >= $max) break;
        }
        return $out;
    }

    public function home($force = false)
    {
        $ck = 'home:wetv:v2';
        if (!$force) {
            $hit = cache_get($ck);
            if ($hit !== null) { stat_inc('cache_hits'); return ['ok' => true, 'data' => $hit, 'error' => '']; }
        }
        $r = indocast_call('GET', 'wetv/home', ['lang' => self::LANG], 0, true);
        if (!$r['ok']) {
            $old = cache_get($ck . ':backup');
            return $old !== null ? ['ok' => true, 'data' => $old, 'error' => ''] : $r;
        }
        $s = isset($r['data']['sections']) ? $r['data']['sections'] : [];
        $hero = [];
        foreach ((isset($s['banners']) ? $s['banners'] : []) as $b) {
            if (self::skip($b)) continue;
            $it = self::item($b, true);
            if ($it['cover'] !== '') $hero[] = $it;
        }
        $ids = [];
        $sections = [];
        $add = function ($title, $rows, $film = false) use (&$sections, &$ids) {
            $items = self::listOf($rows, $ids);
            if ($film) foreach ($items as &$x) $x['type'] = 1;
            unset($x);
            if ($items) $sections[] = ['id' => md5($title), 'title' => $title, 'items' => $items];
        };
        $add('Populer di WeTV', isset($s['wetvHot']) ? $s['wetvHot'] : []);
        $add('Terbaru', isset($s['terbaru']) ? $s['terbaru'] : []);
        // Kanal tambahan: Film (kanal lain sering kosong dari Indocast)
        foreach (['10062' => 'Film: '] as $ch => $prefix) {
            $c = indocast_call('GET', 'wetv/channel', ['id' => $ch, 'lang' => self::LANG], 0, true);
            if (!$c['ok']) continue;
            foreach ((isset($c['data']['modules']) ? $c['data']['modules'] : []) as $m) {
                if (isset($m['moduleType']) && $m['moduleType'] === 'MODULE_TYPE_CAROUSEL') continue;
                $add($prefix . trim((string) $m['moduleName']), isset($m['items']) ? $m['items'] : [], $ch === '10062');
            }
        }
        $data = ['hero' => array_slice($hero, 0, 6), 'sections' => array_slice($sections, 0, 10)];
        cache_set($ck, $data, 21600);
        cache_set($ck . ':backup', $data, 7 * 86400);
        return ['ok' => true, 'data' => $data, 'error' => ''];
    }

    public function search($keyword, $page, $type)
    {
        $page = max(1, (int) $page);
        $q = ['q' => (string) $keyword, 'page' => (string) $page, 'lang' => self::LANG];
        $r = indocast_call('GET', 'wetv/search', $q, 1800);
        if (!$r['ok']) { usleep(400000); $r = indocast_call('GET', 'wetv/search', $q, 1800); } // Indocast kadang 502
        if (!$r['ok']) return $r;
        $d = is_array($r['data']) ? $r['data'] : [];
        $ids = [];
        $items = [];
        foreach (self::listOf(isset($d['items']) ? $d['items'] : [], $ids, 100) as $it) {
            if ($type && $it['type'] !== (int) $type) continue;
            $items[] = $it;
        }
        $total = isset($d['totalResults']) ? (int) $d['totalResults'] : count($items);
        return ['ok' => true, 'data' => ['items' => $items, 'has_more' => $page * 10 < $total, 'total' => $total], 'error' => ''];
    }

    private function raw($cid, $ttl = 3600)
    {
        $r = indocast_call('GET', 'wetv/detail', ['cid' => (string) $cid, 'lang' => self::LANG], $ttl);
        if (!$r['ok']) return $r;
        if (empty($r['data']['series']['id'])) return ['ok' => false, 'data' => null, 'error' => 'Judul tidak ditemukan.'];
        return $r;
    }

    public function detail($id, $path, $ttl = 3600)
    {
        $r = $this->raw($id !== '' ? $id : $path, $ttl);
        if (!$r['ok']) return $r;
        $s = $r['data']['series'];
        $eps = isset($r['data']['episodes']) ? $r['data']['episodes'] : [];
        $n = count($eps);
        $isMovie = $n === 1;
        $genres = isset($s['genres']) && is_array($s['genres']) ? array_slice(array_map('strval', $s['genres']), 0, 3) : [];
        $it = [
            'id' => (string) $s['id'],
            'path' => (string) $s['id'],
            'type' => $isMovie ? 1 : 2,
            'title' => trim((string) $s['title']),
            'cover' => self::vpic(isset($s['coverVertical']) ? $s['coverVertical'] : ''),
            'rating' => is_numeric(isset($s['score']) ? $s['score'] : '') ? round((float) $s['score'], 1) : 0,
            'genre' => implode(', ', $genres),
            'year' => (string) (isset($s['year']) ? $s['year'] : ''),
            'country' => (string) (isset($s['region']) ? $s['region'] : ''),
            'badge' => '',
            'soon' => '',
            'description' => trim((string) (isset($s['description']) ? $s['description'] : '')),
            'languages' => '',
            'trailer' => '',
        ];
        $it['seasons'] = $isMovie ? [['season' => 1, 'episodes' => [0]]] : [['season' => 1, 'episodes' => $n ? range(1, $n) : []]];
        return ['ok' => true, 'data' => $it, 'error' => ''];
    }

    public function play($id, $path, $se, $ep, $lang = '')
    {
        $r = $this->raw($id !== '' ? $id : $path);
        if (!$r['ok']) return $r;
        $eps = isset($r['data']['episodes']) ? $r['data']['episodes'] : [];
        $i = (int) $ep === 0 ? 0 : (int) $ep - 1;
        if (!isset($eps[$i]['vid'])) return ['ok' => false, 'data' => null, 'error' => 'Episode tidak ditemukan.'];
        $p = indocast_call('GET', 'wetv/play', ['cid' => (string) $r['data']['series']['id'], 'vid' => (string) $eps[$i]['vid'], 'defn' => 'shd'], 1800);
        if (!$p['ok']) return $p;
        $d = $p['data'];
        $sources = [];
        foreach ((isset($d['resolutions']) ? $d['resolutions'] : []) as $x) {
            if (empty($x['url']) || !empty($x['drm'])) continue;
            $sources[] = ['res' => (int) $x['resolution'], 'url' => (string) $x['url'], 'proxy' => '', 'hls' => true];
        }
        if (!$sources && !empty($d['url'])) $sources[] = ['res' => 0, 'url' => (string) $d['url'], 'proxy' => '', 'hls' => true];
        usort($sources, function ($a, $b) { return $b['res'] - $a['res']; });
        $subs = [];
        foreach ((isset($d['subtitles']) ? $d['subtitles'] : []) as $s) {
            if (empty($s['url'])) continue;
            $l = strtolower((string) $s['lang']);
            $subs[] = ['lang' => $l, 'label' => $l === 'id' ? 'Indonesia' : (string) $s['name'], 'url' => (string) $s['url'], 'proxy' => ''];
        }
        if (!$sources) return ['ok' => false, 'data' => null, 'error' => 'Video ini belum tersedia di server WeTV.', 'unavailable' => true];
        return ['ok' => true, 'data' => ['sources' => $sources, 'subtitles' => $subs, 'format' => 'HLS'], 'error' => ''];
    }
}
