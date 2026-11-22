<?php
require __DIR__ . '/_inc.php';
require_once dirname(__DIR__) . '/lib/payment.php';
admin_require();

$act = post('act');
if ($act && csrf_check()) {
    $p = q_row("SELECT * FROM payments WHERE id = ?", [(int) post('id')]);
    if (!$p) flash('Tagihan tidak ditemukan.', 'err');
    elseif ($act === 'check') {
        $p = payment_check($p, true);
        flash('Status #' . $p['ref'] . ': ' . $p['status'] . '.', $p['status'] === 'paid' ? 'ok' : 'err');
    } elseif ($act === 'mark_paid') {
        if (payment_mark_paid($p['id'], 'Manual admin')) flash('#' . $p['ref'] . ' ditandai lunas, VIP aktif.');
        else flash('Tagihan sudah lunas / dibatalkan.', 'err');
    }
    back();
}

$status = isset($_GET['status']) ? (string) $_GET['status'] : '';
$qref = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$where = [];
$params = [];
if (in_array($status, ['pending', 'paid', 'expired', 'cancel'], true)) { $where[] = 'p.status = ?'; $params[] = $status; }
if ($qref !== '') { $where[] = '(p.ref = ? OR p.user_id = ? OR p.amount = ?)'; $params[] = $qref; $params[] = (int) $qref; $params[] = (int) $qref; }
$rows = q_all("SELECT p.*, u.username, u.first_name FROM payments p LEFT JOIN users u ON u.id = p.user_id"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY p.id DESC LIMIT 200", $params);
$label = ['pending' => ['Menunggu', 'warn'], 'paid' => ['Lunas', 'ok'], 'expired' => ['Kedaluwarsa', ''], 'cancel' => ['Dibatalkan', '']];

admin_head('Transaksi QRIS', 'payments');
?>
<form class="box" method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
  <label style="flex:1 1 200px">Cari kode / ID user / nominal<input name="q" value="<?= e($qref) ?>"></label>
  <label style="flex:0 1 180px">Status<select name="status"><option value="">Semua</option>
    <?php foreach ($label as $k => $v): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v[0] ?></option><?php endforeach; ?>
  </select></label>
  <button class="btn">Saring</button>
</form>
<div class="box tbl"><table>
<thead><tr><th>Waktu</th><th>Kode</th><th>Pengguna</th><th>Paket</th><th>Nominal</th><th>Status</th><th></th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="7" class="muted">Belum ada transaksi.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): $l = $label[$r['status']]; ?>
<tr>
  <td class="muted"><?= date('d M H:i', strtotime($r['created_at'])) ?></td>
  <td class="mono"><?= e($r['ref']) ?></td>
  <td><?= e($r['first_name'] ?: '-') ?><br><span class="muted" style="font-size:12px"><?= $r['username'] ? '@' . e($r['username']) . ' · ' : '' ?><?= (int) $r['user_id'] ?></span></td>
  <td><?= e($r['plan_name']) ?></td>
  <td><b><?= rupiah($r['amount']) ?></b><br><span class="muted" style="font-size:12px">kode unik <?= (int) $r['unique_code'] ?></span></td>
  <td><span class="pill <?= $l[1] ?>"><?= $l[0] ?></span><?= $r['note'] ? '<br><span class="muted" style="font-size:12px">' . e($r['note']) . '</span>' : '' ?></td>
  <td style="text-align:right;white-space:nowrap">
  <?php if ($r['status'] === 'pending'): ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="check"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn ghost sm">Cek GoBiz</button></form>
  <?php endif; ?>
  <?php if ($r['status'] !== 'paid'): ?>
    <form method="post" class="inline" onsubmit="return confirm('Tandai lunas & aktifkan VIP? Pastikan uang benar-benar masuk.')"><?= csrf_field() ?><input type="hidden" name="act" value="mark_paid"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn sm">Tandai lunas</button></form>
  <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php admin_foot();
