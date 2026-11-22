<?php
require __DIR__ . '/_inc.php';
admin_require();

$act = post('act');
if ($act && csrf_check()) {
    $id = (int) post('id');
    if ($act === 'save') {
        $name = mb_substr(post('name'), 0, 64);
        $days = (int) post('days');
        $price = (int) preg_replace('/\D/', '', post('price'));
        $label = mb_substr(post('label'), 0, 64);
        $sort = (int) post('sort');
        if ($name === '' || $days < 1 || $price < 1000) {
            flash('Isi nama, durasi minimal 1 hari, dan harga minimal Rp 1.000.', 'err');
        } elseif ($id) {
            q("UPDATE plans SET name = ?, days = ?, price = ?, label = ?, sort = ? WHERE id = ?", [$name, $days, $price, $label, $sort, $id]);
            flash('Paket diperbarui.');
        } else {
            q("INSERT INTO plans (name, days, price, label, sort, active) VALUES (?, ?, ?, ?, ?, 1)", [$name, $days, $price, $label, $sort]);
            flash('Paket ditambahkan.');
        }
    } elseif ($act === 'toggle') {
        q("UPDATE plans SET active = 1 - active WHERE id = ?", [$id]);
        flash('Status paket diubah.');
    } elseif ($act === 'delete') {
        if (q_val("SELECT 1 FROM payments WHERE plan_id = ?", [$id])) {
            q("UPDATE plans SET active = 0 WHERE id = ?", [$id]);
            flash('Paket sudah pernah dibeli, jadi dinonaktifkan (tidak dihapus).');
        } else {
            q("DELETE FROM plans WHERE id = ?", [$id]);
            flash('Paket dihapus.');
        }
    }
    back('plans.php');
}

$edit = isset($_GET['edit']) ? q_row("SELECT * FROM plans WHERE id = ?", [(int) $_GET['edit']]) : null;
$plans = q_all("SELECT p.*, (SELECT COUNT(*) FROM payments x WHERE x.plan_id = p.id AND x.status = 'paid') sold FROM plans p ORDER BY sort ASC, price ASC");

admin_head('Paket VIP', 'plans');
?>
<div class="box"><h2><?= $edit ? 'Ubah paket' : 'Tambah paket' ?></h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
<div class="grid">
  <label>Nama paket<input name="name" required maxlength="64" value="<?= e($edit ? $edit['name'] : '') ?>" placeholder="7 Hari"></label>
  <label>Durasi (hari)<input type="number" name="days" min="1" required value="<?= e($edit ? $edit['days'] : '') ?>"></label>
  <label>Harga (Rp)<input type="number" name="price" min="1000" required value="<?= e($edit ? $edit['price'] : '') ?>"></label>
  <label>Label kecil <small>opsional</small><input name="label" maxlength="64" value="<?= e($edit ? $edit['label'] : '') ?>" placeholder="Paling laris"></label>
  <label>Urutan<input type="number" name="sort" value="<?= e($edit ? $edit['sort'] : count($plans) + 1) ?>"></label>
</div>
<div class="actions"><button class="btn"><?= $edit ? 'Simpan perubahan' : '+ Tambah paket' ?></button><?php if ($edit): ?><a class="btn ghost" href="plans.php">Batal</a><?php endif; ?></div>
</form></div>

<div class="box tbl"><table>
<thead><tr><th>Urutan</th><th>Nama</th><th>Durasi</th><th>Harga</th><th>Label</th><th>Terjual</th><th>Status</th><th></th></tr></thead>
<tbody>
<?php if (!$plans): ?><tr><td colspan="8" class="muted">Belum ada paket.</td></tr><?php endif; ?>
<?php foreach ($plans as $p): ?>
<tr>
  <td class="muted"><?= (int) $p['sort'] ?></td>
  <td><b><?= e($p['name']) ?></b></td>
  <td><?= (int) $p['days'] ?> hari</td>
  <td><?= rupiah($p['price']) ?></td>
  <td><?= e($p['label']) ?></td>
  <td><?= (int) $p['sold'] ?></td>
  <td><span class="pill <?= $p['active'] ? 'ok' : '' ?>"><?= $p['active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
  <td style="text-align:right;white-space:nowrap">
    <a class="btn ghost sm" href="plans.php?edit=<?= (int) $p['id'] ?>">Ubah</a>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn ghost sm"><?= $p['active'] ? 'Matikan' : 'Aktifkan' ?></button></form>
    <form method="post" class="inline" onsubmit="return confirm('Hapus paket ini?')"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn red sm">Hapus</button></form>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php admin_foot();
