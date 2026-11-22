<?php
/*
 * gobiz.php — QRIS dinamis + cek mutasi GoBiz (akun khusus bot ini).
 * Diadaptasi dari Qris.php & Gobiz.php milik proyek sayunk.
 *
 * ANTI-BADAI LOGIN (jangan dihapus):
 *  - token disimpan & dipercaya; login ulang hanya bila 401 / belum ada token
 *  - login gagal -> cooldown 15 menit
 *  - percobaan login maksimal 1x / 30 detik
 */
require_once __DIR__ . '/core.php';

/* ---------- QRIS statis -> dinamis ---------- */
function qris_dynamic($amount, $static)
{
    $amount = (string) intval($amount);
    $qris = trim($static);
    $qris = substr($qris, 0, strlen($qris) - 4);           // buang CRC lama
    $qris = str_replace('010211', '010212', $qris);         // statis -> dinamis
    $parts = explode('5802ID', $qris);
    $tag = '54' . str_pad(strlen($amount), 2, '0', STR_PAD_LEFT) . $amount . '5802ID';
    $dyn = $parts[0] . $tag . (isset($parts[1]) ? $parts[1] : '');
    return $dyn . qris_crc16($dyn);
}

function qris_crc16($str)
{
    $crc = 0xFFFF;
    $len = strlen($str);
    for ($i = 0; $i < $len; $i++) {
        $crc ^= (ord($str[$i]) << 8);
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x8000) ? ((($crc << 1) ^ 0x1021) & 0xFFFF) : (($crc << 1) & 0xFFFF);
        }
    }
    return strtoupper(str_pad(dechex($crc & 0xFFFF), 4, '0', STR_PAD_LEFT));
}

class Gobiz
{
    private $base = 'https://api.gobiz.co.id';
    private $reqId;
    private $lastCode = 0;
    public $lastError = '';
    const COOLDOWN_SEC = 900;

    public function __construct()
    {
        $this->reqId = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    public function configured()
    {
        return setting('gobiz_email') !== '' && setting('gobiz_password') !== '' && setting('qris_static') !== '';
    }

    private function state($key = null, $val = null)
    {
        $j = json_decode(setting('gobiz_state', '{}'), true);
        if (!is_array($j)) $j = [];
        if ($val === null) return isset($j[$key]) ? $j[$key] : null;
        $j[$key] = $val;
        setting_set('gobiz_state', json_encode($j));
        return $val;
    }

    public function statusInfo()
    {
        $until = (int) $this->state('cooldown_until');
        return [
            'logged_in' => setting('gobiz_access') !== '',
            'merchant' => setting('gobiz_merchant'),
            'cooldown' => ($until && time() < $until) ? ($until - time()) : 0,
            'last_error' => (string) $this->state('last_error'),
        ];
    }

    /** Lupakan token (mis. setelah email/password diganti). */
    public function reset()
    {
        setting_set('gobiz_access', '');
        setting_set('gobiz_refresh', '');
        setting_set('gobiz_merchant', '');
        setting_set('gobiz_state', '{}');
    }

    public function authenticate($force = false)
    {
        $until = (int) $this->state('cooldown_until');
        if (!$force && $until && time() < $until) { $this->lastError = 'Sedang cooldown login GoBiz.'; return false; }

        $access = setting('gobiz_access');
        if ($access && !$force) return $access;

        $last = (int) $this->state('last_attempt');
        if ($last && time() - $last < 30) { $this->lastError = 'Login GoBiz di-throttle, tunggu 30 detik.'; return false; }
        $this->state('last_attempt', time());

        $refresh = setting('gobiz_refresh');
        if ($refresh) {
            $tok = $this->req('POST', '/goid/token', 'token', null, [
                'client_id' => 'go-biz-web-new', 'grant_type' => 'refresh_token',
                'data' => ['refresh_token' => $refresh],
            ]);
            if (isset($tok['access_token'])) return $this->onLogin($tok, $refresh);
        }

        $email = setting('gobiz_email');
        $pass = setting('gobiz_password');
        if (!$email || !$pass) { $this->lastError = 'Email/password GoBiz belum diisi.'; return false; }
        $this->req('POST', '/goid/login/request', 'login', null, ['email' => $email, 'login_type' => 'password', 'client_id' => 'go-biz-web-new']);
        $tok = $this->req('POST', '/goid/token', 'token', null, [
            'client_id' => 'go-biz-web-new', 'grant_type' => 'password',
            'data' => ['email' => $email, 'password' => $pass],
        ]);
        if (isset($tok['access_token'])) return $this->onLogin($tok, '');

        $msg = '';
        if (is_array($tok)) $msg = isset($tok['errors'][0]['message']) ? $tok['errors'][0]['message'] : (isset($tok['message']) ? $tok['message'] : '');
        $this->lastError = 'Login GoBiz gagal (HTTP ' . $this->lastCode . ') ' . $msg;
        $this->state('cooldown_until', time() + self::COOLDOWN_SEC);
        $this->state('last_error', $this->lastError);
        if (function_exists('tg_notify_admins')) tg_notify_admins('⚠️ ' . e($this->lastError) . "\nLogin dijeda 15 menit.");
        return false;
    }

    private function onLogin($tok, $oldRefresh)
    {
        setting_set('gobiz_access', $tok['access_token']);
        setting_set('gobiz_refresh', isset($tok['refresh_token']) ? $tok['refresh_token'] : $oldRefresh);
        $this->state('cooldown_until', 0);
        $this->state('last_error', '');
        if (!setting('gobiz_merchant')) {
            $me = $this->req('GET', '/v1/users/me', 'me', $tok['access_token'], null);
            if (!empty($me['user']['merchant_id'])) setting_set('gobiz_merchant', $me['user']['merchant_id']);
        }
        return $tok['access_token'];
    }

    /** True bila ada pembayaran masuk sebesar $amount di rentang waktu. */
    public function isPaid($amount, $startTs, $endTs)
    {
        $access = $this->authenticate(false);
        if (!$access) return false;
        $mid = setting('gobiz_merchant');
        if (!$mid) { $this->onLogin(['access_token' => $access], setting('gobiz_refresh')); $mid = setting('gobiz_merchant'); }
        if (!$mid) { $this->lastError = 'merchant_id GoBiz belum ada.'; return false; }

        $json = $this->req('POST', '/journals/search', 'journals', $access, $this->payload($amount, $startTs, $endTs, $mid));
        if ($this->lastCode == 401) {
            $access = $this->authenticate(true);
            if (!$access) return false;
            $json = $this->req('POST', '/journals/search', 'journals', $access, $this->payload($amount, $startTs, $endTs, setting('gobiz_merchant')));
        }
        if ($this->lastCode >= 400 || !is_array($json)) return false;
        return !empty($json['hits']);
    }

    private function payload($amount, $startTs, $endTs, $mid)
    {
        $iso = function ($ts) { return gmdate('Y-m-d\TH:i:s.000\Z', $ts); };
        return [
            'from' => 0, 'size' => 50,
            'sort' => ['time' => ['order' => 'desc']],
            'query' => [['op' => 'and', 'clauses' => [
                ['field' => 'metadata.transaction.transaction_time', 'op' => 'gte', 'value' => $iso($startTs)],
                ['field' => 'metadata.transaction.transaction_time', 'op' => 'lte', 'value' => $iso($endTs)],
                ['field' => 'metadata.transaction.merchant_id', 'op' => 'equal', 'value' => $mid],
                ['field' => 'metadata.transaction.gross_amount', 'op' => 'equal', 'value' => (int) round($amount * 100)],
            ]]],
        ];
    }

    private function req($method, $path, $kind, $bearer, $body)
    {
        $portal = 'https://portal.gofoodmerchant.co.id';
        $app = 'https://app.gobiz.com';
        $h = [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
            'Authentication-Type: go-id',
            'Authorization: ' . ($bearer ? 'Bearer ' . $bearer : 'Bearer'),
            'Cache-Control: no-cache',
            'Content-Type: application/json',
            'Gojek-Country-Code: ID',
            'Gojek-Timezone: Asia/Jakarta',
            'Pragma: no-cache',
            'User-Agent: Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Mobile Safari/537.36',
            'X-AppVersion: platform-v3.82.1',
            'X-PhoneMake: Google', 'X-PhoneModel: Nexus 5', 'X-Platform: Web',
            'X-User-Locale: id-ID', 'X-User-Type: merchant',
            'x-DeviceOS: Web', 'x-appId: go-biz-web-dashboard', 'x-uniqueid: ' . $this->reqId,
        ];
        if ($kind === 'token') { $h[] = 'Origin: ' . $app; $h[] = 'Referer: ' . $app . '/'; }
        elseif ($kind === 'journals') { $h[] = 'Accept: application/json, text/plain, */*, application/vnd.journal.v1+json'; $h[] = 'Origin: ' . $portal; $h[] = 'Referer: ' . $portal . '/'; }
        else { $h[] = 'Origin: ' . $portal; $h[] = 'Referer: ' . $portal . '/'; }

        $res = http_request($method, $this->base . $path, $h, $body, 25);
        $this->lastCode = $res['code'];
        if ($res['error']) { $this->lastError = 'cURL: ' . $res['error']; app_log('gobiz', $kind . ' CURLERR ' . $res['error']); return null; }
        if ($res['code'] >= 400) app_log('gobiz', $kind . ' HTTP' . $res['code'] . ' ' . substr($res['body'], 0, 300));
        return $res['json'];
    }
}
