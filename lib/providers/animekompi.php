<?php
/*
 * Provider Animekompi (Indocast /api/animekompi/*) — anime subtitle Indonesia (hardsub).
 *
 * - id = path = slug anime (mis. ao-ashi-season-2).
 * - Video MP4: "Server GDrive" lewat proxy Indocast (720p) + Pixeldrain sebagai server cadangan.
 * - Gambar dari domain animekompi diblokir DNS di Indonesia -> lewat api/?a=img (diperkecil).
 * - Tidak ada endpoint cari: dicari dari daftar A-Z (±4.800 judul) yang disimpan ringkas 1 hari.
 */
require_once dirname(__DIR__) . '/indocast.php';
require_once dirname(__DIR__) . '/imgproxy.php';

class Provider_animekompi extends BaseProvider
{
    public $id = 'animekompi';
    public $name = 'Animekompi';

    public static function item(array $r, $w = 300)
    {
        $genres = isset($r['genres']) && is_array($r['genres']) ? array_slice($r['genres'], 0, 3) : [];
        $type = isset($r['type']) ? (string) $r['type'] : '';
        return [
            'id' => (string) $r['path'],
            'path' => (string) $r['path'],
            'type' => $type === 'Movie' ? 1 : 2,
            'title' => trim((string) (isset($r['title']) ? $r['title'] : '')),
            'cover' => img_url(isset($r['cover_url']) ? $r['cover_url'] : '', $w),
            'rating' => !empty($r['rating']) ? round((float) $r['rating'], 1) : 0,
            'genre' => implode(', ', array_filter(array_map(function ($g) { return is_array($g) ? (string) (isset($g['name']) ? $g['name'] : '') : (string) $g; }, $genres))),
            'year' => (string) (isset($r['release_year']) ? $r['release_year'] : ''),
            'country' => '',
            'badge' => '',
            'soon' => '',
        ];
    }

    private static function listOf($rows, &$ids, $max = 15)
    {
        $out = [];
        foreach ((array) $rows as $r) {
            if (!is_array($r) || empty($r['path']) || isset($ids[$r['path']])) continue;
            $ids[$r['path']] = 1;
            $it = self::item($r);
            unset($it['soon'], $it['country'], $it['badge']);
            $out[] = $it;
            if (count($out) >= $max) break;
        }
        return $out;
    }

    public function home($force = false)
    {
        $ck = 'home:animekompi:v1';
        if (!$force) {
            $hit = cache_get($ck);
            if ($hit !== null) { stat_inc('cache_hits'); return ['ok' => true, 'data' => $hit, 'error' => '']; }
        }
        $r = indocast_call('GET', 'animekompi/home', ['page' => '1'], 0, true);
        if (!$r['ok']) {
            $old = cache_get($ck . ':backup');
            return $old !== null ? ['ok' => true, 'data' => $old, 'error' => ''] : $r;
        }
        $d = $r['data'];
        $hero = [];
        foreach ((isset($d['sliders']) ? $d['sliders'] : []) as $s) {
            if (empty($s['path'])) continue;
            $hero[] = self::item($s, 720);
        }
        $ids = [];
        $sections = [];
        $add = function ($title, $rows) use (&$sections, &$ids) {
            $items = self::listOf($rows, $ids);
            if ($items) $sections[] = ['id' => md5($title), 'title' => $title, 'items' => $items];
        };
        $add('Trending Hari Ini', isset($d['trending_today']) ? $d['trending_today'] : []);
        $add('Update Terbaru', isset($d['latest_updates']) ? $d['latest_updates'] : []);
        foreach (['action' => 'Action', 'romance' => 'Romance', 'isekai' => 'Isekai', 'comedy' => 'Komedi'] as $g => $label) {
            $x = indocast_call('GET', 'animekompi/genre-detail', ['genre' => $g, 'page' => '1'], 0, true);
            if ($x['ok']) $add($label, isset($x['data']['animes']) ? $x['data']['animes'] : []);
        }
        $data = ['hero' => array_slice($hero, 0, 6), 'sections' => $sections];
        cache_set($ck, $data, 21600);
        cache_set($ck . ':backup', $data, 7 * 86400);
        if ($force) $this->index(); // cron ikut menyiapkan indeks pencarian (±4 MB, sekali sehari)
        return ['ok' => true, 'data' => $data, 'error' => ''];
    }

    /** Indeks ringkas semua judul (dari daftar A-Z), disimpan 1 hari (+ cadangan 7 hari). */
    private function index()
    {
        $ck = 'animekompi:index:v1';
        $hit = cache_get($ck);
        if ($hit !== null) return $hit;
        $r = indocast_call('GET', 'animekompi/list', [], 0, true);
        if (!$r['ok']) {
            $old = cache_get($ck . ':backup');
            return $old !== null ? $old : [];
        }
        $idx = [];
        foreach ((array) $r['data'] as $g) {
            foreach ((isset($g['animes']) ? $g['animes'] : []) as $a) {
                if (empty($a['path'])) continue;
                $idx[] = [(string) $a['path'], (string) $a['title'], (string) (isset($a['cover_url']) ? $a['cover_url'] : ''),
                    (float) (isset($a['rating']) ? $a['rating'] : 0), (string) (isset($a['type']) ? $a['type'] : ''),
                    (string) (isset($a['release_year']) ? $a['release_year'] : ''), (int) (isset($a['view']) ? $a['view'] : 0)];
            }
        }
        if ($idx) {
            cache_set($ck, $idx, 86400);
            cache_set($ck . ':backup', $idx, 7 * 86400);
        }
        return $idx;
    }

    public function search($keyword, $page, $type)
    {
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim((string) $keyword))));
        if (!$words) return ['ok' => true, 'data' => ['items' => [], 'has_more' => false, 'total' => 0], 'error' => ''];
        $hits = [];
        foreach ($this->index() as $a) {
            $t = mb_strtolower($a[1] . ' ' . str_replace('-', ' ', $a[0]));
            $okw = true;
            foreach ($words as $w) if (mb_strpos($t, $w) === false) { $okw = false; break; }
            if (!$okw) continue;
            $it = self::item(['path' => $a[0], 'title' => $a[1], 'cover_url' => $a[2], 'rating' => $a[3], 'type' => $a[4], 'release_year' => $a[5]]);
            if ($type && $it['type'] !== (int) $type) continue;
            $it['_v'] = $a[6] + (mb_strpos(mb_strtolower($a[1]), implode(' ', $words)) === 0 ? 1e9 : 0);
            $hits[] = $it;
        }
        usort($hits, function ($x, $y) { return $y['_v'] <=> $x['_v']; });
        $page = max(1, (int) $page);
        $per = 24;
        $items = array_map(function ($i) { unset($i['_v']); return $i; }, array_slice($hits, ($page - 1) * $per, $per));
        return ['ok' => true, 'data' => ['items' => $items, 'has_more' => count($hits) > $page * $per, 'total' => count($hits)], 'error' => ''];
    }

    private function raw($path, $ttl = 3600)
    {
        $r = indocast_call('GET', 'animekompi/detail', ['path' => (string) $path], $ttl);
        if (!$r['ok']) return $r;
        if (empty($r['data']['title'])) return ['ok' => false, 'data' => null, 'error' => 'Judul tidak ditemukan.'];
        return $r;
    }

    /** Daftar episode berurutan: [nomor => episode_id]. Nomor asli dipakai bila unik, kalau tidak nomor urut. */
    private static function episodeMap(array $d)
    {
        $list = isset($d['episode_list']) ? $d['episode_list'] : [];
        $rows = [];
        foreach ($list as $e) if (!empty($e['episode_id'])) $rows[] = $e;
        usort($rows, function ($a, $b) { return (float) $a['episode_number'] <=> (float) $b['episode_number']; });
        $map = [];
        $numeric = true;
        foreach ($rows as $e) {
            $n = (string) $e['episode_number'];
            if (!preg_match('/^\d+$/', $n) || (int) $n < 1 || isset($map[(int) $n])) { $numeric = false; break; }
            $map[(int) $n] = (string) $e['episode_id'];
        }
        if (!$numeric) {
            $map = [];
            foreach ($rows as $i => $e) $map[$i + 1] = (string) $e['episode_id'];
        }
        return $map;
    }

    public function detail($id, $path, $ttl = 3600)
    {
        $r = $this->raw($path !== '' ? $path : $id, $ttl);
        if (!$r['ok']) return $r;
        $d = $r['data'];
        $map = self::episodeMap($d);
        $isMovie = (isset($d['type']) && $d['type'] === 'Movie' && count($map) <= 1);
        $raw = $d;
        $raw['path'] = $path !== '' ? $path : $id;
        $raw['release_year'] = substr((string) (isset($d['release_date']) ? $d['release_date'] : ''), 0, 4);
        $it = self::item($raw, 720);
        $it['type'] = $isMovie ? 1 : 2;
        $it['description'] = trim((string) (isset($d['synopsis']) ? $d['synopsis'] : ''));
        $it['languages'] = 'Subtitle Indonesia';
        $it['trailer'] = '';
        $it['seasons'] = $isMovie ? [['season' => 1, 'episodes' => [0]]] : [['season' => 1, 'episodes' => array_keys($map)]];
        return ['ok' => true, 'data' => $it, 'error' => ''];
    }

    public function play($id, $path, $se, $ep, $lang = '')
    {
        $r = $this->raw($path !== '' ? $path : $id);
        if (!$r['ok']) return $r;
        $map = self::episodeMap($r['data']);
        $eid = (int) $ep === 0 ? reset($map) : (isset($map[(int) $ep]) ? $map[(int) $ep] : '');
        if (!$eid) return ['ok' => false, 'data' => null, 'error' => 'Episode tidak ditemukan.'];
        $p = indocast_call('GET', 'animekompi/play', ['episode_id' => $eid], 1800); // link Google berlaku ±6 jam
        if (!$p['ok']) return $p;
        $sources = [];
        foreach ((isset($p['data']['servers']) ? $p['data']['servers'] : []) as $s) {
            $name = (string) (isset($s['name']) ? $s['name'] : '');
            if (!empty($s['url'])) {
                $sources[] = ['res' => 720, 'label' => 'Server ' . (count($sources) + 1), 'url' => (string) $s['url'], 'proxy' => ''];
            } elseif (!empty($s['embed_url']) && preg_match('#^https://pixeldrain\.com/api/file/[A-Za-z0-9]+$#', $s['embed_url'])) {
                $sources[] = ['res' => 0, 'label' => 'Server ' . (count($sources) + 1), 'url' => (string) $s['embed_url'], 'proxy' => ''];
            }
        }
        if (!$sources) return ['ok' => false, 'data' => null, 'error' => 'Video episode ini belum tersedia, coba lagi nanti.', 'unavailable' => true];
        return ['ok' => true, 'data' => ['sources' => $sources, 'subtitles' => [], 'format' => 'MP4'], 'error' => ''];
    }
}
