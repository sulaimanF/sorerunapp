# sorerunapp

Aplikasi membership Sore Running Club: Next.js 16/Node.js 24 pada frontend/, Laravel 13/PHP 8.4 pada backend/, MySQL 8.4 pada Laragon/Windows.

Untuk development yang sudah disiapkan, aktifkan MySQL dan Mailpit melalui Laragon, lalu gunakan terminal terpisah:

```powershell
cd C:\laragon\www\sorerunapp\backend
php artisan serve --host=localhost --port=8000
```

```powershell
cd C:\laragon\www\sorerunapp\frontend
npm.cmd run dev -- --hostname localhost --port 3000
```

```powershell
cd C:\laragon\www\sorerunapp\backend
composer dev
```

Jangan menjalankan server lagi bila terminalnya sudah aktif. composer dev mengelola worker dan scheduler saja; terminal tersebut perlu tetap terbuka. Aplikasi: http://localhost:3000. Inbox Mailpit: http://127.0.0.1:8025.

Operasi harian, status/log, restart/stop, batas penerima sintetis dan pemulihan pengiriman: **[panduan backend](backend/README.md)**. Proses lokal tidak dipasang sebagai service/startup OS. Acuan keputusan: PRD.md, ARCHITECTURE.md, SYSTEM_DESIGN.md, AGENTS.md.

## Landing page dan editor admin — teruji pada lingkungan test terisolasi

Root `/` menampilkan landing publik; `/login` tetap halaman masuk dan login berhasil tetap `/beranda`. Editor berada di `/admin/landing-page`, preview privat di `/admin/landing-page/preview`. Akun harus enabled, email terverifikasi dan mempunyai role admin; tidak dibuat akun/password default atau promosi role otomatis. Admin membuka editor melalui tautan Kelola Landing Page setelah login.

Tahap 10 membutuhkan migration baru `2026_11_14_120000_create_landing_page_content_tables.php`, GD dengan WebP dan disk media privat persisten. Review/pengujian 15 November 2026 telah memakai MySQL 8.4 `sorerunapp_test`, termasuk concurrency dan integrasi browser nyata. Migration hanya diterapkan pada database test (batch 4), **belum pada database aplikasi**. Penerapan development lokal tetap menunggu persetujuan serta pemeriksaan backup/target. Ini bukan bukti kesiapan produksi.

Contoh konfigurasi frontend tersedia di `.env.example`: `SITE_URL` adalah origin frontend untuk canonical; `LANDING_INDEXABLE=false` untuk lokal/test/staging. Produksi publik perlu opt-in `true` dengan Node production dan origin yang benar. `.env` aktual tidak diubah. Laravel `APP_URL` harus origin API tepercaya, tanpa path; Next.js server harus dapat mengakses `NEXT_PUBLIC_API_BASE_URL`. Tidak memerlukan layanan atau dependency baru.

Admin menyimpan draft → membuka preview tersimpan → publish manual. Sebelum publish pertama halaman menampilkan informasi netral; jika API/schema belum tersedia, tampil state gangguan yang berbeda. Detail rollout, konfigurasi, pengujian yang disiapkan dan pemulihan media: [LANDING_PAGE_STAGE10.md](docs/LANDING_PAGE_STAGE10.md). Panduan/script navigasi lama yang memasang redirect `/ → /login` sudah superseded; jangan digunakan untuk setup baru.

Untuk percobaan manual Stage 10 sebelum migration aplikasi, gunakan `node scripts/landing-manual.cjs status` dan `node scripts/landing-manual.cjs start` dari root. Launcher memakai frontend 127.0.0.1:3120, API 127.0.0.1:8120 dan sorerunapp_test dengan akun/konten sintetis yang telah disiapkan. Stop tidak menghapus data. Prasyarat, akses, stop/restart, log dan batas penggunaan database bersama tersedia pada bagian sesi manual di catatan Stage 10; jangan menjalankan suite database otomatis selama percobaan manual.

## Super Admin — persiapan tahap 11

Role Super Admin, form /admin/accounts/new dan aktivasi undangan khusus disiapkan tanpa menjalankan test/migration/bootstrap. Tidak ada akun Super Admin default atau promosi otomatis. Schema baru wajib sebelum memakai kode auth/permission ini; penerapan database aplikasi dan identitas bootstrap menunggu instruksi terpisah. Lihat [catatan tahap 11](docs/SUPER_ADMIN_STAGE11.md) dan [prosedur backend](backend/README.md).
