<?php
require __DIR__ . '/_inc.php';
require_once dirname(__DIR__) . '/lib/payment.php';
admin_require();

$act = post('act');
if ($act && csrf_check()) {
    $uid = (int) post('id');
    $u = q_row("SELECT * FROM users WHERE id = ?", [$uid]);
    if (!$u) flash('Pengguna tidak ditemukan.', 'err');
    elseif ($act === 'give_vip') {
        $days = (int) post('days');
        if ($days < 1 || $days > 3650) flash('Isi jumlah hari 1–3650.', 'err');
        else {
            $until = vip_extend($uid, $days);
            if (post('notify') === '1') tg_send($uid, "🎁 Kamu mendapat VIP <b>$days hari</b> dari admin.\nAktif sampai <b>" . date('d M Y H:i', strtotime($until)) . "</b>.", tg_app_button());
            flash('VIP ditambah ' . $days . ' hari (sampai ' . date('d M Y H:i', strtotime($until)) . ').');
        }
    } elseif ($act === 'revoke_vip') {
        q("UPDATE users SET vip_until = NULL WHERE id = ?", [$uid]);
        flash('VIP dicabut.');
    } elseif ($act === 'ban') {
        q("UPDATE users SET banned = 1 - banned WHERE id = ?", [$uid]);
        flash($u['banned'] ? 'Blokir dibuka.' : 'Pengguna diblokir.');
    }
    back();
}

$qs = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$f = isset($_GET['f']) ? (string) $_GET['f'] : '';
$where = [];
$params = [];
if ($qs !== '') {
    $where[] = '(id = ? OR username LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], ltrim($qs, '@')) . '%';
    array_push($params, (int) $qs, $like, $like, $like);
}
if ($f === 'vip') $where[] = 'vip_until > NOW()';
if ($f === 'free') $where[] = '(vip_until IS NULL OR vip_until <= NOW())';
if ($f === 'blocked') $where[] = 'blocked = 1';
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
$per = 50;
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = (int) q_val("SELECT COUNT(*) FROM users" . $sqlWhere, $params);
$rows = q_all("SELECT * FROM users" . $sqlWhere . " ORDER BY created_at DESC LIMIT " . (($page - 1) * $per) . ", $per", $params);

admin_head('Pengguna', 'users');
?>
<form class="box" method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
  <label style="flex:1 1 220px">Cari nama / @username / ID<input name="q" value="<?= e($qs) ?>"></label>
  <label style="flex:0 1 180px">Saring<select name="f">
    <option value="">Semua</option><option value="vip" <?= $f === 'vip' ? 'selected' : '' ?>>VIP aktif</option>
    <option value="free" <?= $f === 'free' ? 'selected' : '' ?>>Gratis</option><option value="blocked" <?= $f === 'blocked' ? 'selected' : '' ?>>Memblokir bot</option>
  </select></label>
  <button class="btn">Cari</button>
</form>
<p class="muted"><?= number_format($total, 0, ',', '.') ?> pengguna</p>
<div class="box tbl"><table>
<thead><tr><th>Pengguna</th><th>Bergabung</th><th>Terakhir aktif</th><th>Status</th><th>Beri VIP</th><th></th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="6" class="muted">Tidak ada data.</td></tr><?php endif; ?>
<?php foreach ($rows as $u): $vip = user_is_vip($u); ?>
<tr>
  <td><b><?= e(trim($u['first_name'] . ' ' . $u['last_name']) ?: '-') ?></b><br><span class="muted" style="font-size:12px"><?= $u['username'] ? '@' . e($u['username']) . ' · ' : '' ?><?= (int) $u['id'] ?></span></td>
  <td class="muted"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
  <td class="muted"><?= date('d M H:i', strtotime($u['last_seen'])) ?></td>
  <td>
    <?= $vip ? '<span class="pill vip">VIP · ' . date('d M Y', strtotime($u['vip_until'])) . '</span>' : '<span class="pill">Gratis</span>' ?>
    <?= $u['banned'] ? ' <span class="pill warn">Diblokir</span>' : '' ?><?= $u['blocked'] ? ' <span class="pill">Blokir bot</span>' : '' ?>
  </td>
  <td>
    <form method="post" style="display:flex;gap:6px;align-items:center"><?= csrf_field() ?><input type="hidden" name="act" value="give_vip"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
      <input type="number" name="days" min="1" placeholder="hari" style="width:80px;padding:6px 8px"><input type="hidden" name="notify" value="1"><button class="btn sm">+ VIP</button></form>
  </td>
  <td style="text-align:right;white-space:nowrap">
    <?php if ($vip): ?><form method="post" class="inline" onsubmit="return confirm('Cabut VIP pengguna ini?')"><?= csrf_field() ?><input type="hidden" name="act" value="revoke_vip"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="btn ghost sm">Cabut VIP</button></form><?php endif; ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="ban"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="btn <?= $u['banned'] ? 'ghost' : 'red' ?> sm"><?= $u['banned'] ? 'Buka blokir' : 'Blokir' ?></button></form>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($total > $per): ?><div class="actions">
  <?php if ($page > 1): ?><a class="btn ghost sm" href="?<?= e(http_build_query(['q' => $qs, 'f' => $f, 'page' => $page - 1])) ?>">‹ Sebelumnya</a><?php endif; ?>
  <span class="muted">Hal <?= $page ?> / <?= ceil($total / $per) ?></span>
  <?php if ($page * $per < $total): ?><a class="btn ghost sm" href="?<?= e(http_build_query(['q' => $qs, 'f' => $f, 'page' => $page + 1])) ?>">Berikutnya ›</a><?php endif; ?>
</div><?php endif; ?>
<?php admin_foot();
