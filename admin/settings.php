<?php
require __DIR__ . '/_inc.php';
require_once dirname(__DIR__) . '/lib/indocast.php';
require_once dirname(__DIR__) . '/lib/gobiz.php';
admin_require();

$act = post('act');
if ($act && csrf_check()) {
    switch ($act) {
        case 'save':
            $plain = ['bot_name', 'logo_url', 'admin_ids', 'gate_channel', 'gate_group', 'gobiz_email', 'qris_static', 'welcome_text'];
            foreach ($plain as $k) setting_set($k, post($k));
            $color = post('primary_color');
            if (preg_match('/^#[0-9a-f]{6}$/i', $color)) setting_set('primary_color', $color);
            setting_set('free_episodes', (string) max(0, min(50, (int) post('free_episodes'))));
            setting_set('free_minutes', (string) max(0, min(60, (int) post('free_minutes'))));
            setting_set('pay_timeout', (string) max(5, min(60, (int) post('pay_timeout'))));
            setting_set('gate_on', post('gate_on') === '1' ? '1' : '0');
            $base = rtrim(post('base_url'), '/');
            if ($base === '' || preg_match('~^https://[^\s]+$~', $base)) setting_set('base_url', $base);
            $prov = [];
            foreach (provider_registry() as $id => $info) $prov[$id] = isset($_POST['prov'][$id]) ? 1 : 0;
            setting_set('providers', json_encode($prov));
            // Rahasia: hanya diganti bila diisi
            if (post('bot_token') !== '') setting_set('bot_token', post('bot_token'));
            if (post('indocast_key') !== '') { setting_set('indocast_key', post('indocast_key')); }
            $gbChanged = false;
            if (post('gobiz_password') !== '') { setting_set('gobiz_password', post('gobiz_password')); $gbChanged = true; }
            if ($gbChanged) (new Gobiz())->reset();
            flash('Pengaturan disimpan.');
            break;

        case 'webhook':
            $me = tg('getMe');
            if (empty($me['ok'])) { flash('Token bot salah: ' . (isset($me['description']) ? $me['description'] : ''), 'err'); break; }
            setting_set('bot_username', $me['result']['username']);
            if (setting('base_url') === '') setting_set('base_url', base_url());
            $url = base_url('bot/webhook.php');
            if (strpos($url, 'https://') !== 0) { flash('Situs harus memakai HTTPS untuk webhook & Mini App.', 'err'); break; }
            $r = tg('setWebhook', ['url' => $url, 'secret_token' => setting('webhook_secret'), 'allowed_updates' => ['message', 'my_chat_member'], 'drop_pending_updates' => true]);
            $r2 = tg('setChatMenuButton', ['menu_button' => ['type' => 'web_app', 'text' => mb_substr(setting('bot_name'), 0, 16) ?: 'Nonton', 'web_app' => ['url' => base_url('app/')]]]);
            tg('setMyCommands', ['commands' => [
                ['command' => 'start', 'description' => 'Buka aplikasi'],
                ['command' => 'vip', 'description' => 'Cek status VIP'],
                ['command' => 'help', 'description' => 'Bantuan'],
            ]]);
            if (!empty($r['ok']) && !empty($r2['ok'])) flash('Webhook & tombol Mini App terpasang untuk @' . $me['result']['username'] . '.');
            else flash('Gagal memasang: ' . (isset($r['description']) ? $r['description'] : '') . ' ' . (isset($r2['description']) ? $r2['description'] : ''), 'err');
            break;

        case 'test_indocast':
            $p = provider_get('filmbox');
            cache_del('ic:GET:filmbox/home:[]');
            $r = $p ? $p->home() : ['ok' => false, 'error' => 'Provider tidak ada'];
            if ($r['ok']) flash('Indocast tersambung: ' . count($r['data']['sections']) . ' kategori di beranda.');
            else flash($r['error'], 'err');
            break;

        case 'gobiz_login':
            $gb = new Gobiz();
            if ($gb->authenticate(true)) {
                flash('Login GoBiz berhasil' . (setting('gobiz_merchant') ? ' (merchant ' . setting('gobiz_merchant') . ').' : '.'));
            } else {
                flash($gb->lastError ?: 'Login GoBiz gagal.', 'err');
            }
            break;

        case 'gobiz_reset':
            (new Gobiz())->reset();
            flash('Token GoBiz dihapus. Tekan "Login GoBiz" untuk masuk ulang.');
            break;

        case 'password':
            $p1 = post('new_password');
            if (mb_strlen($p1) < 8) { flash('Password minimal 8 karakter.', 'err'); break; }
            if ($p1 !== post('new_password2')) { flash('Konfirmasi password tidak sama.', 'err'); break; }
            setting_set('admin_pass_hash', password_hash($p1, PASSWORD_DEFAULT));
            $_SESSION['adm_hash'] = substr(setting('admin_pass_hash'), -16);
            flash('Password admin diganti.');
            break;

        case 'clear_cache':
            q("DELETE FROM cache WHERE k <> ''");
            flash('Cache dikosongkan.');
            break;

        case 'new_cron_key':
            setting_set('cron_key', random_token(20));
            flash('Kunci cron baru dibuat. Perbarui perintah cron di cPanel.');
            break;
    }
    back();
}

$s = settings_all(true);
$provOn = json_decode($s['providers'], true) ?: [];
$gb = (new Gobiz())->statusInfo();
$wh = setting('bot_token') ? tg('getWebhookInfo') : null;
$whUrl = $wh && !empty($wh['ok']) ? $wh['result']['url'] : '';
$cronUrl = base_url('cron/run.php?key=' . $s['cron_key']);
$lastCron = (int) setting('cron_last', '0');

admin_head('Pengaturan', 'settings');
?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save">

<div class="box"><h2>Tampilan</h2><div class="grid">
  <label>Nama bot<input name="bot_name" value="<?= e($s['bot_name']) ?>" maxlength="40"></label>
  <label>Warna primer<input type="color" name="primary_color" value="<?= e($s['primary_color']) ?>"></label>
  <label>URL logo <small>(gambar persegi, opsional)</small><input name="logo_url" value="<?= e($s['logo_url']) ?>" placeholder="https://…/logo.png"></label>
</div>
<label style="margin-top:14px">Pesan sambutan /start <small>{name} = nama user</small><textarea name="welcome_text" rows="3"><?= e($s['welcome_text']) ?></textarea></label>
</div>

<div class="box"><h2>Telegram</h2><div class="grid">
  <label>Token bot <small><?= $s['bot_token'] ? 'tersimpan — kosongkan bila tidak diganti' : 'dari @BotFather' ?></small><input type="password" name="bot_token" autocomplete="off" placeholder="<?= $s['bot_token'] ? '••••••••' : '123456:ABC…' ?>"></label>
  <label>ID Telegram admin <small>pisahkan koma; dapat kabar penjualan</small><input name="admin_ids" value="<?= e($s['admin_ids']) ?>" placeholder="123456789"></label>
  <label>Alamat situs <small>harus HTTPS</small><input name="base_url" value="<?= e($s['base_url'] ?: base_url()) ?>"></label>
</div>
<p class="muted" style="margin:12px 0 0">Bot: <?= $s['bot_username'] ? '@' . e($s['bot_username']) : '—' ?> · Webhook: <?= $whUrl ? '<span class="pill ok">terpasang</span>' : '<span class="pill warn">belum</span>' ?>
<?php if ($wh && !empty($wh['result']['last_error_message'])): ?> · Error terakhir: <?= e($wh['result']['last_error_message']) ?><?php endif; ?></p>
</div>

<div class="box"><h2>Sumber film (Indocast)</h2>
  <label>API key <small><?= $s['indocast_key'] ? 'tersimpan — kosongkan bila tidak diganti' : 'dari dashboard Indocast' ?></small><input type="password" name="indocast_key" autocomplete="off" placeholder="<?= $s['indocast_key'] ? '••••••••' : 'indocast_…' ?>"></label>
  <div style="display:flex;flex-direction:column;gap:8px;margin-top:14px">
  <?php foreach (provider_registry() as $id => $info): ?>
    <label class="check"><input type="checkbox" name="prov[<?= e($id) ?>]" value="1" <?= !empty($provOn[$id]) ? 'checked' : '' ?>> <?= e($info['name']) ?> <span class="muted" style="font-weight:400"><?= e($info['note']) ?></span></label>
  <?php endforeach; ?>
  </div>
</div>

<div class="box"><h2>Akses gratis</h2><div class="grid">
  <label>Episode gratis per series<input type="number" name="free_episodes" min="0" max="50" value="<?= (int) $s['free_episodes'] ?>"></label>
  <label>Gratis tonton film (menit) <small>0 = film khusus VIP</small><input type="number" name="free_minutes" min="0" max="60" value="<?= (int) $s['free_minutes'] ?>"></label>
</div></div>

<div class="box"><h2>Wajib gabung channel &amp; grup</h2>
  <label class="check"><input type="checkbox" name="gate_on" value="1" <?= $s['gate_on'] === '1' ? 'checked' : '' ?>> Aktifkan wajib gabung</label>
  <div class="grid" style="margin-top:12px">
    <label>Channel<input name="gate_channel" value="<?= e($s['gate_channel']) ?>" placeholder="@namachannel"></label>
    <label>Grup<input name="gate_group" value="<?= e($s['gate_group']) ?>" placeholder="@namagrup"></label>
  </div>
  <p class="muted" style="margin:10px 0 0">Bot harus dijadikan <b>admin</b> di channel &amp; grup agar bisa mengecek keanggotaan. Untuk grup privat, isi ID angka (-100…) — tapi tombol Gabung butuh username publik.</p>
</div>

<div class="box"><h2>Pembayaran QRIS (GoBiz — akun khusus bot ini)</h2><div class="grid">
  <label>Email GoBiz<input name="gobiz_email" value="<?= e($s['gobiz_email']) ?>" autocomplete="off"></label>
  <label>Password GoBiz <small><?= $s['gobiz_password'] ? 'tersimpan — kosongkan bila tidak diganti' : '' ?></small><input type="password" name="gobiz_password" autocomplete="new-password" placeholder="<?= $s['gobiz_password'] ? '••••••••' : '' ?>"></label>
  <label>Batas waktu bayar (menit)<input type="number" name="pay_timeout" min="5" max="60" value="<?= (int) $s['pay_timeout'] ?>"></label>
</div>
<label style="margin-top:14px">Teks QRIS statis <small>hasil scan QRIS merchant, diawali 000201…</small><textarea name="qris_static" rows="2"><?= e($s['qris_static']) ?></textarea></label>
<p class="muted" style="margin:10px 0 0">Status: <?= $gb['logged_in'] ? '<span class="pill ok">login</span>' : '<span class="pill warn">belum login</span>' ?>
<?= $gb['merchant'] ? ' · merchant ' . e($gb['merchant']) : '' ?>
<?= $gb['cooldown'] ? ' · <b>jeda login ' . ceil($gb['cooldown'] / 60) . ' menit</b>' : '' ?>
<?= $gb['last_error'] ? ' · ' . e($gb['last_error']) : '' ?></p>
</div>

<div class="actions" style="margin-bottom:16px"><button class="btn" type="submit">Simpan semua</button></div>
</form>

<div class="box"><h2>Tindakan</h2>
  <div class="actions" style="margin-top:0">
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="webhook"><button class="btn ghost">Pasang webhook &amp; tombol Mini App</button></form>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="test_indocast"><button class="btn ghost">Tes Indocast</button></form>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="gobiz_login"><button class="btn ghost">Login GoBiz</button></form>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="gobiz_reset"><button class="btn ghost">Reset token GoBiz</button></form>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="clear_cache"><button class="btn ghost">Kosongkan cache</button></form>
  </div>
</div>

<div class="box"><h2>Cron (wajib)</h2>
  <p style="margin:0 0 8px">Di cPanel → <b>Cron Jobs</b>, tambahkan perintah ini tiap <b>1 menit</b> (<code>* * * * *</code>):</p>
  <p style="margin:0"><code>curl -s "<?= e($cronUrl) ?>" &gt;/dev/null 2&gt;&amp;1</code></p>
  <p class="muted" style="margin:10px 0 0">Terakhir jalan: <?= $lastCron ? date('d M Y H:i:s', $lastCron) : 'belum pernah' ?>. Cron mengecek pembayaran, mengirim broadcast &amp; kabar episode baru.</p>
  <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="act" value="new_cron_key"><button class="btn ghost sm">Buat kunci cron baru</button></form>
</div>

<div class="box"><h2>Ganti password admin</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="password">
    <div class="grid"><label>Password baru<input type="password" name="new_password" minlength="8" autocomplete="new-password"></label>
    <label>Ulangi<input type="password" name="new_password2" minlength="8" autocomplete="new-password"></label></div>
    <div class="actions"><button class="btn ghost">Ganti password</button></div>
  </form>
</div>
<?php admin_foot();
