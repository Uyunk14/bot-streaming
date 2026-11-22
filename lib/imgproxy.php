<?php
/*
 * imgproxy.php — gambar poster lewat server kita: api/?a=img&u=..&w=..&k=..
 *
 * Dipakai provider yang domain gambarnya diblokir DNS di Indonesia (mis. Animekompi)
 * atau gambarnya terlalu besar. Gambar diperkecil (GD, bila ada) dan disimpan di
 * storage/img/ supaya permintaan berikutnya langsung dari disk.
 */
require_once __DIR__ . '/core.php';

function img_key($u, $w)
{
    return substr(hash_hmac('sha256', 'img|' . $u . '|' . (int) $w, setting('app_secret')), 0, 16);
}

/** URL gambar lewat server kita (tanda tangan tanpa kedaluwarsa supaya bisa disimpan di cache HP). */
function img_url($u, $w = 300)
{
    $u = (string) $u;
    if ($u === '') return '';
    return base_url('api/?a=img&' . http_build_query(['w' => (int) $w, 'u' => $u, 'k' => img_key($u, $w)]));
}

function img_serve($u, $w, $k)
{
    $w = max(80, min(1280, (int) $w));
    if (!preg_match('#^https?://#i', $u) || !hash_equals(img_key($u, $w), (string) $k)) {
        http_response_code(403);
        exit;
    }
    $dir = APP_ROOT . '/storage/img';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/' . sha1($u . '|' . $w) . '.jpg';

    if (!is_file($file) || filesize($file) < 100) {
        $body = '';
        // 1) langsung; 2) lewat proxy Indocast (bila domain diblokir DNS dari server)
        $tries = [$u, 'https://indocast.site/api/animekompi/proxy?url=' . rawurlencode($u)];
        foreach ($tries as $src) {
            $res = http_request('GET', $src, ['Accept: image/*'], null, 15);
            if ($res['code'] == 200 && strlen($res['body']) > 200 && !preg_match('#^\s*(<|\{)#', $res['body'])) { $body = $res['body']; break; }
        }
        if ($body === '') {
            http_response_code(404);
            header('Cache-Control: public, max-age=300');
            exit;
        }
        $out = $body;
        if (function_exists('imagecreatefromstring')) {
            $im = @imagecreatefromstring($body);
            if ($im) {
                $ow = imagesx($im);
                $oh = imagesy($im);
                if ($ow > $w) {
                    $nh = (int) round($oh * $w / $ow);
                    $dst = imagecreatetruecolor($w, $nh);
                    imagecopyresampled($dst, $im, 0, 0, 0, 0, $w, $nh, $ow, $oh);
                    imagedestroy($im);
                    $im = $dst;
                }
                ob_start();
                imagejpeg($im, null, 80);
                $out = ob_get_clean();
                imagedestroy($im);
            }
        }
        @file_put_contents($file, $out, LOCK_EX);
    } else {
        $out = file_get_contents($file);
    }
    $finfo = function_exists('getimagesizefromstring') ? @getimagesizefromstring($out) : false;
    header('Content-Type: ' . ($finfo && !empty($finfo['mime']) ? $finfo['mime'] : 'image/jpeg'));
    header('Cache-Control: public, max-age=2592000, immutable');
    header('Content-Length: ' . strlen($out));
    echo $out;
    exit;
}

/** Bersihkan gambar yang tidak dipakai > 30 hari (dipanggil cron tiap jam). */
function img_cleanup()
{
    $n = 0;
    foreach ((array) glob(APP_ROOT . '/storage/img/*.jpg') as $f) {
        if (@fileatime($f) < time() - 30 * 86400 && @filemtime($f) < time() - 30 * 86400) { @unlink($f); $n++; }
        if ($n > 500) break;
    }
    return $n;
}
