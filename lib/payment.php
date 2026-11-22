<?php
/*
 * payment.php — paket VIP, pembuatan tagihan QRIS, pengecekan & aktivasi VIP.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/gobiz.php';

function plans_active()
{
    return q_all("SELECT id, name, days, price, label FROM plans WHERE active = 1 ORDER BY sort ASC, price ASC");
}

function payment_public(array $p)
{
    $static = setting('qris_static');
    return [
        'ref' => $p['ref'],
        'plan' => $p['plan_name'],
        'days' => (int) $p['days'],
        'price' => (int) $p['price'],
        'unique_code' => (int) $p['unique_code'],
        'amount' => (int) $p['amount'],
        'status' => $p['status'],
        'expires_at' => strtotime($p['expires_at']),
        'qris' => ($p['status'] === 'pending' && $static) ? qris_dynamic($p['amount'], $static) : '',
    ];
}

/** Buat tagihan baru (atau pakai tagihan pending yang sama bila masih berlaku). */
function payment_create(array $user, $planId)
{
    if (setting('qris_static') === '') return ['ok' => false, 'error' => 'Pembayaran belum disiapkan admin.'];
    $plan = q_row("SELECT * FROM plans WHERE id = ? AND active = 1", [(int) $planId]);
    if (!$plan) return ['ok' => false, 'error' => 'Paket tidak ditemukan.'];

    $exist = q_row("SELECT * FROM payments WHERE user_id = ? AND plan_id = ? AND status = 'pending' AND expires_at > NOW() ORDER BY id DESC LIMIT 1", [$user['id'], $plan['id']]);
    if ($exist) return ['ok' => true, 'payment' => $exist];

    // Batalkan tagihan pending lain milik user ini (pilih satu paket saja)
    q("UPDATE payments SET status = 'cancel' WHERE user_id = ? AND status = 'pending'", [$user['id']]);

    // Kode unik 1..299 yang belum dipakai tagihan pending lain dengan harga sama
    $code = 0;
    for ($i = 0; $i < 40; $i++) {
        $try = random_int(1, 299);
        // Hindari nominal yang sama dengan tagihan pending, atau yang baru lunas/kedaluwarsa
        // (supaya satu transfer tidak terbaca sebagai pembayaran dua tagihan).
        $clash = q_val(
            "SELECT 1 FROM payments WHERE amount = ? AND (status = 'pending' OR created_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)) LIMIT 1",
            [(int) $plan['price'] + $try]
        );
        if (!$clash) { $code = $try; break; }
    }
    if (!$code) return ['ok' => false, 'error' => 'Antrean pembayaran penuh, coba lagi sebentar.'];

    $timeout = max(5, (int) setting('pay_timeout', '15'));
    $ref = 'V' . date('ymd') . strtoupper(substr(random_token(4), 0, 6));
    q(
        "INSERT INTO payments (ref, user_id, plan_id, plan_name, days, price, unique_code, amount, status, created_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE))",
        [$ref, $user['id'], $plan['id'], $plan['name'], (int) $plan['days'], (int) $plan['price'], $code, (int) $plan['price'] + $code, $timeout]
    );
    return ['ok' => true, 'payment' => q_row("SELECT * FROM payments WHERE ref = ?", [$ref])];
}

/**
 * Cek satu tagihan ke GoBiz (dibatasi 1x / 8 detik per tagihan).
 * Mengembalikan baris payments terbaru.
 */
function payment_check(array $p, $force = false)
{
    if ($p['status'] !== 'pending') return $p;
    $now = time();
    $created = strtotime($p['created_at']);
    $expires = strtotime($p['expires_at']);

    if (!$force && $now - (int) $p['last_check'] < 8) return $p;
    q("UPDATE payments SET last_check = ? WHERE id = ?", [$now, $p['id']]);

    $gb = new Gobiz();
    // Beri kelonggaran 3 menit: pembayaran tepat sebelum batas waktu tetap diterima
    if ($gb->configured() && $gb->isPaid((int) $p['amount'], $created - 180, min($now, $expires + 180) + 60)) {
        payment_mark_paid($p['id'], 'GoBiz');
    } elseif ($now > $expires + 180) {
        q("UPDATE payments SET status = 'expired' WHERE id = ? AND status = 'pending'", [$p['id']]);
    }
    return q_row("SELECT * FROM payments WHERE id = ?", [$p['id']]);
}

/** Tandai lunas + tambah masa VIP (aman dipanggil berkali-kali). */
function payment_mark_paid($paymentId, $note = '')
{
    $st = q("UPDATE payments SET status = 'paid', paid_at = NOW(), note = ? WHERE id = ? AND status IN ('pending','expired')", [mb_substr($note, 0, 255), (int) $paymentId]);
    if ($st->rowCount() !== 1) return false;
    $p = q_row("SELECT * FROM payments WHERE id = ?", [(int) $paymentId]);
    $until = vip_extend($p['user_id'], (int) $p['days']);
    stat_inc('revenue', (int) $p['amount']);

    tg_send($p['user_id'], "✅ <b>Pembayaran diterima</b>\nPaket: " . e($p['plan_name']) . "\nVIP aktif sampai <b>" . date('d M Y H:i', strtotime($until)) . "</b>.\nSelamat menonton!", tg_app_button('🎬 Lanjut Nonton'));
    tg_notify_admins("💰 VIP terjual\n" . e($p['plan_name']) . ' · ' . rupiah($p['amount']) . "\nUser ID " . $p['user_id'] . ' · #' . $p['ref']);
    return true;
}

/** Tambah masa VIP $days hari dari sekarang atau dari sisa masa aktif. */
function vip_extend($userId, $days)
{
    q("UPDATE users SET vip_until = DATE_ADD(GREATEST(COALESCE(vip_until, NOW()), NOW()), INTERVAL ? DAY) WHERE id = ?", [(int) $days, (int) $userId]);
    return q_val("SELECT vip_until FROM users WHERE id = ?", [(int) $userId]);
}
