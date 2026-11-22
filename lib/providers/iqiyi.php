<?php
/*
 * Provider iQIYI (Indocast /api/iqiyi/*).
 *
 * - id   = id album iQIYI, path = "pathdetail" (dipakai /detail?pathplay=).
 * - Video berupa HLS: Indocast mengirim isi playlist m3u8 (segmen dibungkus
 *   stream-proxy Indocast). Playlist disajikan ulang oleh api/?a=m3u8 dengan
 *   tautan segmen langsung ke server iQIYI (CORS terbuka) agar tidak membebani
 *   server kita; versi "proxy" tetap lewat Indocast sebagai cadangan.
 * - Episode VIP iQIYI saat ini dijawab 404 "No program found" oleh Indocast
 *   (akun mereka belum VIP) -> ditampilkan "belum tersedia".
 */
require_once dirname(__DIR__) . '/indocast.php';

class Provider_iqiyi extends BaseProvider
{
    public $id = 'iqiyi';
    public $name = 'iQIYI';
    const LANG = 'id_id';

    private static function https($u)
    {
        $u = (string) $u;
        return strpos($u, 'http://') === 0 ? 'https://' . substr($u, 7) : $u;
    }

    public static function item(array $r, $wide = false)
    {
        $cover = isset($r['cover']) && is_array($r['cover']) ? $r['cover'] : [];
        $banner = isset($r['banner']) && is_array($r['banner']) ? $r['banner'] : [];
        $pic = $wide
            ? (!empty($banner['background']) ? $banner['background'] : (!empty($cover['landscape']) ? $cover['landscape'] : ''))
            : (!empty($cover['potrait']) ? $cover['potrait'] : (!empty($banner['cover']) ? $banner['cover'] : (!empty($cover['landscape']) ? $cover['landscape'] : '')));
        $genres = isset($r['genres']) && is_array($r['genres']) ? array_slice($r['genres'], 0, 3) : [];
        $year = substr(preg_replace('/\D/', '', (string) (isset($r['releaseDate']) ? $r['releaseDate'] : '')), 0, 4);
        return [
            'id' => (string) (isset($r['id']) ? $r['id'] : ''),
            'path' => (string) (isset($r['pathdetail']) ? $r['pathdetail'] : ''),
            'type' => (isset($r['contentType']) && $r['contentType'] === 'movie') ? 1 : 2,
            'title' => trim((string) (isset($r['title']) ? $r['title'] : '')),
            'cover' => self::https($pic),
            'rating' => !empty($r['voteAverage']) ? round((float) $r['voteAverage'], 1) : 0,
            'genre' => implode(', ', $genres),
            'year' => $year,
            'country' => '',
            'badge' => '',
            'soon' => '',
        ];
    }

    private static function valid(array $it, array $raw)
    {
        return $it['id'] !== '' && $it['path'] !== '' && (!isset($raw['hasVideo']) || $raw['hasVideo']);
    }

    public function home($force = false)
    {
        $ck = 'home:iqiyi:v1';
        if (!$force) {
            $hit = cache_get($ck);
            if ($hit !== null) { stat_inc('cache_hits'); return ['ok' => true, 'data' => $hit, 'error' => '']; }
        }
        $r = indocast_call('GET', 'iqiyi/home', ['page' => '1', 'lang' => self::LANG], 0, true);
        if (!$r['ok']) {
            $old = cache_get($ck . ':backup');
            return $old !== null ? ['ok' => true, 'data' => $old, 'error' => ''] : $r;
        }
        $d = $r['data'];
        $hero = [];
        foreach ((isset($d['sliderItems']) ? $d['sliderItems'] : []) as $raw) {
            $it = self::item($raw, true);
            if (self::valid($it, $raw) && $it['cover'] !== '') $hero[] = $it;
        }
        $sections = [];
        foreach ((isset($d['sections']) ? $d['sections'] : []) as $s) {
            $items = [];
            $ids = [];
            foreach ((isset($s['items']) ? $s['items'] : []) as $raw) {
                $it = self::item($raw);
                if (!self::valid($it, $raw) || isset($ids[$it['id']])) continue;
                $ids[$it['id']] = 1;
                unset($it['soon'], $it['country'], $it['badge']);
                $items[] = $it;
                if (count($items) >= 15) break;
            }
            if ($items) $sections[] = ['id' => (string) (isset($s['sectionId']) ? $s['sectionId'] : md5($s['title'])), 'title' => trim((string) $s['title']), 'items' => $items];
        }
        $data = ['hero' => array_slice($hero, 0, 6), 'sections' => $sections];
        cache_set($ck, $data, 21600);
        cache_set($ck . ':backup', $data, 7 * 86400);
        return ['ok' => true, 'data' => $data, 'error' => ''];
    }

    /** Pencarian iQIYI tidak berhalaman: semua hasil dikirim sekaligus. */
    public function search($keyword, $page, $type)
    {
        if ((int) $page > 1) return ['ok' => true, 'data' => ['items' => [], 'has_more' => false, 'total' => 0], 'error' => ''];
        $r = indocast_call('GET', 'iqiyi/search', ['q' => (string) $keyword, 'lang' => self::LANG], 1800);
        if (!$r['ok']) return $r;
        $items = [];
        $ids = [];
        foreach ((isset($r['data']['sections']) ? $r['data']['sections'] : []) as $s) {
            foreach ((isset($s['items']) ? $s['items'] : []) as $raw) {
                $it = self::item($raw);
                if (!self::valid($it, $raw) || isset($ids[$it['id']])) continue;
                if ($type && $it['type'] !== (int) $type) continue;
                $ids[$it['id']] = 1;
                $items[] = $it;
            }
        }
        return ['ok' => true, 'data' => ['items' => $items, 'has_more' => false, 'total' => count($items)], 'error' => ''];
    }

    /** Detail mentah + peta (season:episode) -> slug episode. */
    private function raw($path, $ttl = 3600)
    {
        return indocast_call('GET', 'iqiyi/detail', ['pathplay' => (string) $path, 'lang' => self::LANG], $ttl);
    }

    public function detail($id, $path, $ttl = 3600)
    {
        $r = $this->raw($path, $ttl);
        if (!$r['ok']) return $r;
        $d = $r['data'];
        $seasons = [];
        foreach (self::episodeMap($d) as $sn => $eps) {
            $seasons[] = ['season' => (int) $sn, 'episodes' => array_keys($eps)];
        }
        $rawCount = 0;
        foreach ((isset($d['seasons']) ? $d['seasons'] : []) as $s) $rawCount += count(isset($s['episodes']) ? $s['episodes'] : []);

        // Jenis tidak dikirim di detail: film = 1 video yang slug-nya sama dengan judul / tanpa "-episode-".
        $first = isset($d['seasons'][0]['episodes'][0]['slug']) ? $d['seasons'][0]['episodes'][0]['slug'] : '';
        $isMovie = $rawCount === 1 && ($first === $path || stripos($first, '-episode-') === false);
        $raw = $d;
        $raw['pathdetail'] = $path;
        $raw['contentType'] = $isMovie ? 'movie' : 'tv_series';
        $it = self::item($raw);
        if ($it['id'] === '') $it['id'] = (string) $id;
        $it['description'] = trim((string) (isset($d['overview']) ? $d['overview'] : ''));
        $it['languages'] = '';
        $it['trailer'] = '';
        $it['seasons'] = $isMovie ? [['season' => 1, 'episodes' => [0]]] : ($seasons ?: [['season' => 1, 'episodes' => []]]);
        return ['ok' => true, 'data' => $it, 'error' => ''];
    }

    private function episodeSlug($path, $se, $ep)
    {
        $r = $this->raw($path);
        if (!$r['ok']) return '';
        if ((int) $ep === 0) { // film: video pertama
            foreach ((isset($r['data']['seasons']) ? $r['data']['seasons'] : []) as $s) {
                foreach ((isset($s['episodes']) ? $s['episodes'] : []) as $e) if (!empty($e['slug'])) return (string) $e['slug'];
            }
            return '';
        }
        $map = self::episodeMap($r['data']);
        return isset($map[(int) $se][(int) $ep]) ? $map[(int) $se][(int) $ep] : '';
    }

    /**
     * Peta [season][episode] => slug. Daftar iQIYI ikut memuat "Pratinjau",
     * "Extra", trailer dengan nomor episode yang sama -> dilewati.
     */
    private static function episodeMap(array $d)
    {
        $map = [];
        foreach ((isset($d['seasons']) ? $d['seasons'] : []) as $s) {
            $sn = (int) (isset($s['seasonNumber']) ? $s['seasonNumber'] : 1);
            foreach ((isset($s['episodes']) ? $s['episodes'] : []) as $e) {
                if (empty($e['slug'])) continue;
                $t = (string) (isset($e['title']) ? $e['title'] : '');
                if (preg_match('/pratinjau|preview|trailer|teaser|extra|cuplikan|behind the scenes/i', $t)) continue;
                $n = (int) $e['episodeNumber'];
                $good = stripos($e['slug'], '-episode-') !== false;
                if (!isset($map[$sn][$n]) || ($good && stripos($map[$sn][$n], '-episode-') === false)) $map[$sn][$n] = (string) $e['slug'];
            }
        }
        ksort($map);
        foreach ($map as $k => $m) { ksort($m); $map[$k] = $m; }
        return $map;
    }

    /** Jawaban mentah /iqiyi/play (berisi teks m3u8). Disimpan 1 jam. */
    public function playRaw($path, $se, $ep)
    {
        $slug = $this->episodeSlug($path, $se, $ep);
        if ($slug === '') return ['ok' => false, 'data' => null, 'error' => 'Episode tidak ditemukan.'];
        $r = indocast_call('GET', 'iqiyi/play', ['slug' => $slug, 'lang' => self::LANG], 3600);
        if (!$r['ok']) {
            if (stripos($r['error'], 'No program') !== false || stripos($r['error'], '404') !== false) {
                $r['error'] = 'Video ini belum tersedia di server iQIYI. Coba episode lain atau cek lagi nanti.';
                $r['unavailable'] = true;
            }
            return $r;
        }
        if (empty($r['data']['m3u8']) || strpos($r['data']['m3u8'], '#EXTM3U') === false) {
            return ['ok' => false, 'data' => null, 'error' => 'Video belum tersedia, coba episode lain.', 'unavailable' => true];
        }
        return $r;
    }

    /**
     * Sumber video "hls" tanpa url: api/index.php mengisi url bertanda tangan
     * ke api/?a=m3u8 (langsung) dan proxy (lewat Indocast).
     */
    public function play($id, $path, $se, $ep, $lang = '')
    {
        $r = $this->playRaw($path, $se, $ep);
        if (!$r['ok']) return $r;
        $d = $r['data'];
        $q = (int) (isset($d['quality']) ? $d['quality'] : 0);
        $res = [100 => 240, 200 => 360, 300 => 480, 500 => 480, 600 => 720, 800 => 1080];
        $subs = [];
        foreach ((isset($d['subtitles']) && is_array($d['subtitles']) ? $d['subtitles'] : []) as $s) {
            if (empty($s['subUrl'])) continue;
            $subs[] = ['lang' => (string) $s['lang'], 'label' => (string) $s['label'], 'url' => (string) $s['subUrl'], 'proxy' => ''];
        }
        return ['ok' => true, 'data' => [
            'sources' => [['res' => isset($res[$q]) ? $res[$q] : 0, 'url' => '', 'proxy' => '', 'hls' => true]],
            'subtitles' => $subs,
            'format' => 'HLS',
        ], 'error' => ''];
    }

    /** Isi playlist m3u8. $direct = segmen langsung ke iQIYI (bukan lewat Indocast). */
    public function playlist($path, $se, $ep, $direct = true)
    {
        $r = $this->playRaw($path, $se, $ep);
        if (!$r['ok']) return null;
        $m = str_replace(["\r\n", "\r"], "\n", (string) $r['data']['m3u8']);
        $base = rtrim(setting('indocast_base', 'https://indocast.site/api'), '/');
        $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', $base);
        $out = [];
        foreach (explode("\n", $m) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                if ($direct && preg_match('#/stream-proxy\?(?:.*&)?url=([^&]+)#', $line, $mm)) {
                    $u = rawurldecode($mm[1]);
                    if (preg_match('#^https?://#', $u)) $line = $u;
                } elseif ($line[0] === '/') {
                    $line = $origin . $line;
                }
            }
            $out[] = $line;
        }
        $txt = implode("\n", $out);
        if (strpos($txt, '#EXT-X-ENDLIST') === false) $txt = rtrim($txt) . "\n#EXT-X-ENDLIST";
        return $txt . "\n";
    }
}
