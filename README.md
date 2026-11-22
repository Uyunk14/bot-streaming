# Bot Streaming + Mini App Telegram

Bot Telegram untuk menonton film/drama/anime lewat Mini App. Sumber film dari API Indocast (provider: Filmbox, iQIYI).
PHP murni + MySQL, jalan di cPanel (PHP 7.4 – 8.4). Semua pengaturan (token bot, API key, warna, paket VIP, QRIS)
diatur dari **web admin**, bukan di file.

## Struktur
| Folder / file | Isi |
|---|---|
| `install.php` | Pemasangan pertama (koneksi database + password admin). Terkunci otomatis setelah terpasang. |
| `app/` | Mini App (HTML/CSS/JS tanpa framework). Rute pakai hash `#/…`. |
| `api/index.php` | API untuk Mini App. Login otomatis lewat `initData` Telegram (divalidasi HMAC). |
| `bot/webhook.php` | Webhook bot (`/start`, `/vip`, `/help`, teks biasa = cari). Diamankan `secret_token`. |
| `admin/` | Web admin: Dasbor, Pengaturan, Paket VIP, Transaksi QRIS, Pengguna, Broadcast. |
| `cron/run.php` | Cron tiap menit: cek pembayaran QRIS, kirim broadcast, kabar episode baru, bersihkan cache. |
| `lib/core.php` | Database (PDO), migrasi otomatis, pengaturan, cache, helper. |
| `lib/indocast.php` + `lib/providers/*.php` | Klien Indocast + provider. |
| `lib/gobiz.php`, `lib/payment.php` | QRIS dinamis + cek mutasi GoBiz (anti-badai login), tagihan & aktivasi VIP. |
| `lib/broadcast.php` | Broadcast bertahap & notifikasi episode baru. |
| `storage/` | Log (`storage/logs/*.log`) & lock cron. Tertutup dari web. |

## Aturan akses
- **Series**: N episode pertama season pertama gratis (default 1), sisanya VIP — dicek di server.
- **Film**: gratis ditonton N menit pertama (default 2), lalu muncul ajakan VIP. Batas ini dijaga di aplikasi
  (link video tetap dari CDN penyedia, jadi pengguna yang paham teknis masih bisa mengambil link-nya).
- VIP = beli paket (durasi hari) lewat QRIS GoBiz. Nominal = harga + kode unik 1–299.
- Wajib gabung channel & grup bisa dinyalakan/dimatikan di admin (bot harus admin di sana).

## Pemasangan di cPanel
1. Buat subdomain (mis. `nonton.domain.com`) dengan SSL aktif (Mini App wajib HTTPS).
2. Unggah semua isi folder ini ke folder subdomain tersebut.
3. Buat database MySQL kosong + user (cPanel → MySQL Databases).
4. Buka `https://subdomain/install.php`, isi data database & password admin. **Lakukan segera setelah unggah.**
5. Masuk `https://subdomain/admin/` → **Pengaturan**: isi token bot, API key Indocast, QRIS statis, akun GoBiz,
   lalu **Simpan** → tekan **Pasang webhook & tombol Mini App** → **Tes Indocast** → **Login GoBiz**.
6. **Paket VIP**: tambahkan paket & harga.
7. cPanel → **Cron Jobs**, tiap menit (`* * * * *`), perintah yang tampil di Pengaturan → Cron.
8. Buka bot di Telegram → `/start` → tombol **Buka Aplikasi**.

## Menambah provider baru
1. Buat `lib/providers/<id>.php` berisi `class Provider_<id> extends BaseProvider` dengan method
   `home()`, `search()`, `detail()`, `play()` yang mengembalikan format sama seperti `filmbox.php`.
2. Daftarkan di `provider_registry()` (`lib/indocast.php`).
3. Centang provider di Admin → Pengaturan.
4. Video HLS (m3u8): kembalikan sumber `hls => true` dan sediakan method `playlist()` (lihat `iqiyi.php`);
   playlist disajikan `api/?a=m3u8` (tautan bertanda tangan) dan diputar dengan hls.js (dimuat saat perlu).

## Pengembangan
- Setiap mengubah app.js/app.css, naikkan angka `?v=` di app/index.html (file JS/CSS disimpan lama di HP).
- Lint wajib dua versi: `/c/php74/php.exe -l file.php` dan `/c/php84/php.exe -l file.php`.
- Jangan tulis token/API key/password di file mana pun — semua di tabel `settings` (lewat admin).
- Perubahan tabel: tambah di `db_migrate()` (`lib/core.php`) dan naikkan `SCHEMA_VER`.
