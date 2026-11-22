<?php
require __DIR__ . '/_inc.php';
require_once dirname(__DIR__) . '/lib/broadcast.php';
admin_require();

$act = post('act');
if ($act && csrf_check()) {
    if ($act === 'send' || $act === 'test') {
        $aud = in_array(post('audience'), ['all', 'vip', 'free'], true) ? post('audience') : 'all';
        $text = post('text');
        $photo = post('photo');
        $btn = mb_substr(post('button_text'), 0, 64);
        if ($text === '') { flash('Pesan masih kosong.', 'err'); back(); }
        if (mb_strlen($text) > ($photo ? 1000 : 4000)) { flash('Pesan terlalu panjang.', 'err'); back(); }
        if ($photo !== '' && !preg_match('~^https://~', $photo)) { flash('URL gambar harus https://', 'err'); back(); }
        if ($act === 'test') {
            $admins = array_filter(array_map('trim', explode(',', setting('admin_ids'))));
            if (!$admins) { flash('Isi ID Telegram admin di Pengaturan dulu.', 'err'); back(); }
            $r = broadcast_send_one($admins[0], ['text' => $text, 'photo' => $photo, 'button_text' => $btn]);
            flash($r === true ? 'Pesan uji terkirim ke admin.' : 'Gagal: ' . $r, $r === true ? 'ok' : 'err');
        } else {
            $total = (int) q_val("SELECT COUNT(*) FROM users WHERE " . audience_where($aud));
            q("INSERT INTO broadcasts (audience, text, photo, button_text, status, total, created_at) VALUES (?, ?, ?, ?, 'queued', ?, NOW())", [$aud, $text, $photo, $btn, $total]);
            flash("Broadcast dijadwalkan ke $total pengguna. Dikirim bertahap oleh cron.");
        }
    } elseif ($act === 'stop') {
        q("UPDATE broadcasts SET status = 'stopped', finished_at = NOW() WHERE id = ? AND status IN ('queued','sending')", [(int) post('id')]);
        flash('Broadcast dihentikan.');
    }
    back();
}

$counts = [];
foreach (['all', 'vip', 'free'] as $a) $counts[$a] = (int) q_val("SELECT COUNT(*) FROM users WHERE " . audience_where($a));
$list = q_all("SELECT * FROM broadcasts ORDER BY id DESC LIMIT 30");
$st = ['queued' => ['Antre', 'warn'], 'sending' => ['Mengirim', 'warn'], 'done' => ['Selesai', 'ok'], 'stopped' => ['Dihentikan', '']];

admin_head('Broadcast', 'broadcast');
?>
<div class="box"><h2>Kirim broadcast</h2>
<form method="post"><?= csrf_field() ?>
<div class="grid">
  <label>Kirim ke<select name="audience">
    <option value="all">Semua pengguna (<?= $counts['all'] ?>)</option>
    <option value="vip">Hanya VIP (<?= $counts['vip'] ?>)</option>
    <option value="free">Bukan VIP (<?= $counts['free'] ?>)</option>
  </select></label>
  <label>URL gambar <small>opsional, https://</small><input name="photo" placeholder="https://…/poster.jpg"></label>
  <label>Teks tombol Mini App <small>kosong = tanpa tombol</small><input name="button_text" value="🎬 Buka Aplikasi" maxlength="64"></label>
</div>
<label style="margin-top:14px">Pesan <small>boleh pakai &lt;b&gt;tebal&lt;/b&gt;, &lt;i&gt;miring&lt;/i&gt;</small><textarea name="text" rows="6" required></textarea></label>
<div class="actions">
  <button class="btn" name="act" value="send" onclick="return confirm('Kirim ke pengguna terpilih?')">Kirim</button>
  <button class="btn ghost" name="act" value="test">Kirim uji ke admin dulu</button>
</div>
<p class="muted" style="margin:10px 0 0">Dikirim bertahap (±25 pesan/detik) oleh cron supaya tidak kena batas Telegram.</p>
</form></div>

<div class="box tbl"><h2>Riwayat</h2><table>
<thead><tr><th>Waktu</th><th>Pesan</th><th>Tujuan</th><th>Progres</th><th>Status</th><th></th></tr></thead>
<tbody>
<?php if (!$list): ?><tr><td colspan="6" class="muted">Belum ada broadcast.</td></tr><?php endif; ?>
<?php foreach ($list as $b): $s = $st[$b['status']]; ?>
<tr>
  <td class="muted"><?= date('d M H:i', strtotime($b['created_at'])) ?></td>
  <td><?= e(mb_strimwidth(strip_tags($b['text']), 0, 70, '…')) ?></td>
  <td><?= ['all' => 'Semua', 'vip' => 'VIP', 'free' => 'Bukan VIP'][$b['audience']] ?></td>
  <td><?= (int) $b['sent'] ?> terkirim · <?= (int) $b['failed'] ?> gagal / <?= (int) $b['total'] ?></td>
  <td><span class="pill <?= $s[1] ?>"><?= $s[0] ?></span></td>
  <td style="text-align:right"><?php if (in_array($b['status'], ['queued', 'sending'], true)): ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="stop"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn red sm">Hentikan</button></form>
  <?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php admin_foot();
