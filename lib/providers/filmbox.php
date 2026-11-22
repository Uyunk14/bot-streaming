<?php
/*
 * Provider Filmbox (Indocast /api/filmbox/*).
 * Semua hasil dinormalisasi ke format umum supaya Mini App tidak perlu
 * tahu bentuk asli tiap provider.
 *
 * Item umum: id, path, type (1 film / 2 series), title, cover, rating,
 *            genre, year, country, badge
 */
require_once dirname(__DIR__) . '/indocast.php';

class Provider_filmbox extends BaseProvider
{
    public $id = 'filmbox';
    public $name = 'Filmbox';

    public static function item(array $r)
    {
        return [
            'id' => (string) (isset($r['subjectId']) ? $r['subjectId'] : ''),
            'path' => (string) (isset($r['detailPath']) ? $r['detailPath'] : ''),
            'type' => (int) (isset($r['subjectType']) ? $r['subjectType'] : 1),
            'title' => trim((string) (isset($r['title']) ? $r['title'] : '')),
            'cover' => (string) (isset($r['cover_url']) ? $r['cover_url'] : ''),
            'rating' => isset($r['rating']) ? round((float) $r['rating'], 1) : 0,
            'genre' => str_replace(["\n", ','], [', ', ', '], (string) (isset($r['genre']) ? $r['genre'] : '')),
            'year' => substr((string) (isset($r['releaseDate']) ? $r['releaseDate'] : ''), 0, 4),
            'country' => (string) (isset($r['country']) ? $r['country'] : ''),
            'badge' => (string) (isset($r['corner_badge']) ? $r['corner_badge'] : ''),
            'soon' => (string) (isset($r['appointmentDate']) ? $r['appointmentDate'] : ''),
        ];
    }

    /** Hanya film (1) dan series (2) yang bisa diputar; buang banner promo. */
    private static function valid(array $it)
    {
        return $it['id'] !== '' && $it['id'] !== '0' && $it['path'] !== '' && in_array($it['type'], [1, 2], true);
    }

    public function home()
    {
        $r = indocast_call('GET', 'filmbox/home', [], 900);
        if (!$r['ok']) return $r;
        $d = $r['data'];
        $hero = [];
        foreach ((isset($d['hero_banners']) ? $d['hero_banners'] : []) as $b) {
            $it = self::item($b);
            if (self::valid($it)) $hero[] = $it;
        }
        $sections = [];
        $seen = [];
        foreach ((isset($d['content_sections']) ? $d['content_sections'] : []) as $s) {
            $sid = isset($s['section_id']) ? $s['section_id'] : md5(json_encode($s));
            if (isset($seen[$sid])) continue; // API mengirim beberapa section dobel
            $seen[$sid] = 1;
            $items = [];
            $ids = [];
            foreach ((isset($s['items']) ? $s['items'] : []) as $raw) {
                $it = self::item($raw);
                if (!self::valid($it) || isset($ids[$it['id']])) continue;
                $ids[$it['id']] = 1;
                $items[] = $it;
            }
            if ($items) $sections[] = ['id' => (string) $sid, 'title' => trim((string) $s['section_title']), 'items' => $items];
        }
        return ['ok' => true, 'data' => ['hero' => array_slice($hero, 0, 8), 'sections' => $sections], 'error' => ''];
    }

    public function search($keyword, $page, $type)
    {
        $r = indocast_call('POST', 'filmbox/search', [
            'keyword' => (string) $keyword,
            'page' => (string) max(1, (int) $page),
            'perPage' => '24',
            'subjectType' => (string) (int) $type,
        ], 1800);
        if (!$r['ok']) return $r;
        $items = [];
        foreach ((isset($r['data']['items']) ? $r['data']['items'] : []) as $raw) {
            $it = self::item($raw);
            if (self::valid($it)) $items[] = $it;
        }
        $pg = isset($r['data']['pager']) ? $r['data']['pager'] : [];
        return ['ok' => true, 'data' => [
            'items' => $items,
            'has_more' => !empty($pg['hasMore']),
            'total' => isset($pg['totalCount']) ? (int) $pg['totalCount'] : count($items),
        ], 'error' => ''];
    }

    public function detail($id, $path, $ttl = 3600)
    {
        $r = indocast_call('GET', 'filmbox/details', ['detailPath' => (string) $path, 'id' => (string) $id], $ttl);
        if (!$r['ok']) return $r;
        $d = $r['data'];
        $it = self::item($d);
        $it['description'] = (string) (isset($d['description']) ? $d['description'] : '');
        $it['languages'] = (string) (isset($d['bahasa']) ? $d['bahasa'] : '');
        $it['trailer'] = (string) (isset($d['trailer_url']) ? $d['trailer_url'] : '');
        $seasons = [];
        foreach ((isset($d['seasons_list']) ? $d['seasons_list'] : []) as $s) {
            $eps = array_values(array_unique(array_map('intval', isset($s['episodes']) ? $s['episodes'] : [])));
            sort($eps);
            if ($it['type'] === 1) $eps = [0];
            $seasons[] = ['season' => (int) $s['season'], 'episodes' => $eps];
        }
        usort($seasons, function ($a, $b) { return $a['season'] - $b['season']; });
        if (!$seasons) $seasons = [['season' => 1, 'episodes' => $it['type'] === 1 ? [0] : []]];
        $it['seasons'] = $seasons;
        return ['ok' => true, 'data' => $it, 'error' => ''];
    }

    /** Film diputar dengan se=0 & ep=0 (sesuai dokumentasi Indocast). */
    public function play($id, $path, $se, $ep, $lang = 'in_id')
    {
        $r = indocast_call('GET', 'filmbox/getplay', [
            'subjectId' => (string) $id,
            'detailPath' => (string) $path,
            'se' => (string) (int) $se,
            'ep' => (string) (int) $ep,
            'lang' => (string) $lang,
        ], 5400); // link video berlaku 2 jam -> cache 90 menit
        if (!$r['ok']) return $r;
        $d = $r['data'];
        $sources = [];
        foreach ((isset($d['hls']) && is_array($d['hls']) ? $d['hls'] : []) as $h) {
            if (empty($h['url'])) continue;
            $sources[] = [
                'res' => (int) $h['resolutions'],
                'url' => (string) $h['url'],
                'proxy' => (string) (isset($h['url_proxy']) ? $h['url_proxy'] : ''),
            ];
        }
        if (!$sources && !empty($d['vid_url'])) {
            $sources[] = ['res' => (int) (isset($d['quality']) ? $d['quality'] : 0), 'url' => (string) $d['vid_url'], 'proxy' => (string) (isset($d['vid_url_proxy']) ? $d['vid_url_proxy'] : '')];
        }
        usort($sources, function ($a, $b) { return $b['res'] - $a['res']; });
        $subs = [];
        foreach ((isset($d['subtitles']) && is_array($d['subtitles']) ? $d['subtitles'] : []) as $s) {
            if (empty($s['url'])) continue;
            $subs[] = ['lang' => (string) $s['lang'], 'label' => (string) $s['label'], 'url' => (string) $s['url'], 'proxy' => (string) (isset($s['url_proxy']) ? $s['url_proxy'] : '')];
        }
        return ['ok' => true, 'data' => ['sources' => $sources, 'subtitles' => $subs, 'format' => (string) (isset($d['format']) ? $d['format'] : 'MP4')], 'error' => ''];
    }
}
