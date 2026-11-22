<?php
require __DIR__ . '/_inc.php';
admin_require();

$totalUsers = (int) q_val("SELECT COUNT(*) FROM users");
$newToday = (int) q_val("SELECT COUNT(*) FROM users WHERE created_at >= CURDATE()");
$vipActive = (int) q_val("SELECT COUNT(*) FROM users WHERE vip_until > NOW()");
$vipEnding = (int) q_val("SELECT COUNT(*) FROM users WHERE vip_until > NOW() AND vip_until < DATE_ADD(NOW(), INTERVAL 7 DAY)");
$revMonth = (int) q_val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'paid' AND paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
$soldMonth = (int) q_val("SELECT COUNT(*) FROM payments WHERE status = 'paid' AND paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
$playsToday = (int) q_val("SELECT n FROM stats_daily WHERE d = CURDATE() AND k = 'plays'");
$apiToday = (int) q_val("SELECT n FROM stats_daily WHERE d = CURDATE() AND k = 'api_calls'");
$hitToday = (int) q_val("SELECT n FROM stats_daily WHERE d = CURDATE() AND k = 'cache_hits'");

$days = [];
for ($i = 6; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i day"))] = 0;
foreach (q_all("SELECT DATE(paid_at) d, SUM(amount) s FROM payments WHERE status = 'paid' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(paid_at)") as $r) {
    if (isset($days[$r['d']])) $days[$r['d']] = (int) $r['s'];
}
$max = max(1, max($days));
$top = q_all("SELECT title, SUM(n) n FROM views WHERE d >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY provider, subject_id, title ORDER BY n DESC LIMIT 8");
$hari = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

// Peringatan pengaturan yang belum lengkap
$warn = [];
if (!setting('bot_token')) $warn[] = 'Token bot belum diisi.';
if (!setting('indocast_key')) $warn[] = 'API key Indocast belum diisi.';
if (!setting('qris_static')) $warn[] = 'QRIS statis belum diisi (pembayaran belum bisa).';
if (!q_val("SELECT 1 FROM plans WHERE active = 1")) $warn[] = 'Belum ada paket VIP aktif.';
$lastCron = (int) setting('cron_last', '0');
if (time() - $lastCron > 600) $warn[] = 'Cron belum berjalan dalam 10 menit terakhir (lihat Pengaturan → Cron).';

admin_head('Dasbor', 'index');
if ($warn) echo '<div class="flash err">' . implode('<br>', array_map('e', $warn)) . ' <a href="settings.php">Buka Pengaturan</a></div>';
?>
<div class="grid">
  <div class="box stat"><div class="l">Total pengguna</div><div class="v"><?= number_format($totalUsers, 0, ',', '.') ?></div><div class="n">+<?= $newToday ?> hari ini</div></div>
  <div class="box stat"><div class="l">VIP aktif</div><div class="v"><?= number_format($vipActive, 0, ',', '.') ?></div><div class="n"><?= $vipEnding ?> habis dalam 7 hari</div></div>
  <div class="box stat"><div class="l">Pendapatan bulan ini</div><div class="v"><?= rupiah($revMonth) ?></div><div class="n"><?= $soldMonth ?> paket terjual</div></div>
  <div class="box stat"><div class="l">Diputar hari ini</div><div class="v"><?= number_format($playsToday, 0, ',', '.') ?></div><div class="n">episode / film</div></div>
</div>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr))">
  <div class="box"><h2>Pendapatan 7 hari</h2>
    <div class="bars"><?php foreach ($days as $d => $v): ?>
      <div title="<?= e(rupiah($v)) ?>"><i style="height:<?= max(1, round($v / $max * 100)) ?>%"></i><span><?= $hari[(int) date('w', strtotime($d))] ?></span></div>
    <?php endforeach; ?></div>
  </div>
  <div class="box"><h2>Paling banyak ditonton (7 hari)</h2>
    <?php if (!$top): ?><p class="muted">Belum ada data.</p><?php endif; ?>
    <?php foreach ($top as $t): ?><div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #F0F1F4"><span><?= e($t['title']) ?></span><span class="muted"><?= (int) $t['n'] ?>×</span></div><?php endforeach; ?>
  </div>
</div>
<div class="box"><h2>Pemakaian API Indocast hari ini</h2>
  <div class="meter"><i style="width:<?= min(100, round($apiToday / 10000, 2)) ?>%"></i></div>
  <p class="muted" style="margin:8px 0 0"><?= number_format($apiToday, 0, ',', '.') ?> dari 1.000.000 request / 24 jam · cache menghemat <?= number_format($hitToday, 0, ',', '.') ?> request</p>
</div>
<?php admin_foot();
