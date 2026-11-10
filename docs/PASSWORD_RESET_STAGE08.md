# Lupa dan reset password — tahap 08

Implementasi FR-03/BP-11/AC-28–30, ARCHITECTURE §8.3/11.7/12.2, SYSTEM_DESIGN §11.1. Halaman publik `/forgot-password` dan `/reset-password` menggunakan API Laravel langsung melalui Axios/CSRF existing. Tidak ada dependency baru, BFF, atau auto-login.

## Operasi

- Migrasi additive `2026_11_09_120000_support_password_reset_delivery.php` menambah binding user/version pada tabel token broker serta payload outbox terenkripsi. Deployment harus menerapkan migrasi sebelum kode baru menerima request atau worker dijalankan; tidak mengubah migrasi lama. Token legacy tanpa binding ditolak dan pengguna dapat meminta tautan baru.
- Gunakan database MySQL 8.4, queue/session/cache database sesuai konfigurasi aplikasi existing. `.env.example` bawaan masih mempunyai placeholder SQLite dan mailer log; jangan menganggapnya konfigurasi runtime final. Tidak ada variabel konfigurasi baru untuk fitur ini.
- Set `FRONTEND_URL` ke satu origin frontend tepercaya (scheme/host/port tanpa path/query/fragment). Cocokkan CORS/Sanctum/session-domain dengan topologi existing. Reset link memakai fragment yang tidak dikirim ke server Next.js.
- Gunakan `MAIL_MAILER=smtp` dan SMTP lokal seperti Mailpit untuk pengembangan. Mailer log ditolak agar proof tidak tercatat. Pengujian menggunakan `MAIL_MAILER=array`/Notification fake, tidak mengirim ke pengguna nyata.
- Jalankan worker existing `php artisan queue:work database --queue=mail --timeout=60 --tries=1` dan scheduler existing `php artisan schedule:work` untuk pengembangan, atau scheduler terkelola pada deployment. Sweep `outbox:dispatch` setiap menit memulihkan gap commit-dispatch/lease expired. Broker `auth:clear-resets users` dijadwalkan tiap jam; tidak perlu menjalankan purge manual untuk mencoba fitur.
- Broker `users` di `config/auth.php` sudah menetapkan expire=60 menit dan throttle=60 detik. Ini parameter reset terpisah dari verifikasi email. Retry memakai proof yang sama dan expiry awal, tidak memperpanjang masa berlaku.
- Pertahankan `APP_KEY` karena dipakai mengenkripsi payload outbox. Worker harus direstart saat rilis; pantau `last_error_code`/attempt_count tanpa menyalin proof/email/password ke log. Kegagalan SMTP terminal meminta permintaan reset baru; jangan menghidupkan ulang payload yang sudah dibersihkan.

## Verifikasi yang dapat diulang

Database harus **sorerunapp_test** terisolasi pada MySQL 8.4 dengan schema tahap sebelumnya. Helper berikut memvalidasi environment/nama database/versi, lalu menerapkan hanya migrasi additive tahap ini; tidak menjalankan migrate:fresh atau reset database:

```powershell
# backend/
php tests/Support/prepare-password-reset-database.php
php artisan test --configuration=phpunit.passwords.xml
php vendor/bin/phpunit --configuration=phpunit.passwords.xml
php vendor/bin/pint --test
# frontend/
npm.cmd run lint
npm.cmd run typecheck
npm.cmd test
npm.cmd run test:e2e
npm.cmd run build
```

Suite `phpunit.passwords.xml` mencakup regresi tahap 01–07 serta reset API/CSRF/limit/rollback/session, worker email/retry/gap dispatch, dan dua proses PHP dengan koneksi/transaksi MySQL berbeda yang memperebutkan token sama. Fixture sintetis dibersihkan berdasarkan ID milik test; tidak menyentuh anggota nyata. Playwright menggunakan fixture API yang ditandai khusus test, sedangkan test backend menjalankan broker, database, middleware, notification, serta transaksi sebenarnya dengan email fake. Browser fixture bukan bukti pengiriman SMTP ke provider produksi.

AGENTS.md tidak diubah: aturan auth, outbox, transaksi, privasi, dan test concurrency existing sudah memadai. PRD/ARCHITECTURE/SYSTEM_DESIGN dilengkapi pada bagian existing tanpa mengubah status Final atau menduplikasi FR-03. Payload berada di `backend/docs/API_CONTRACT.md`, dengan indeks root `docs/API_CONTRACT.md`.

## Hasil verifikasi aktual — 10 November 2026

Pemeriksaan ulang dimulai dari branch `dev`, commit terakhir `14fadad`. Perubahan fitur dari sesi sebelumnya dipertahankan. Empat dokumen root, aturan subfolder, manifest/lockfile, dan implementasi ditinjau ulang; implementasi dilanjutkan tanpa scaffold atau dependency baru.

Bagian yang dituntaskan pada kelanjutan ini:

- State Membaca tautan reset… mencegah pesan invalid sebelum browser membaca proof. Tautan baru pada halaman reset yang sudah terbuka tetap diproses; hash dibersihkan dari URL. Cleanup mempertahankan proof saat replay effect Strict Mode, tetapi menghapusnya ketika halaman ditinggalkan/disembunyikan oleh navigasi Next.js.
- Error validasi server mempertahankan password dan memfokuskan field pertama setelah fieldset aktif kembali. Token invalid menampilkan status pemulihan yang sesuai, tanpa menyamakan warning dengan alert error pada test.
- Test rollback kini benar-benar membuat sesi sebelum kegagalan transaksi dan memastikan sesi tersebut pulih. Test tambahan memeriksa reset dari sesi sendiri, membership suspended/ended, serta pembersihan proof pada kegagalan email terminal.
- Integrasi kanal email Laravel dirender melalui transport array lokal; proof diekstrak dari email lalu dipakai pada endpoint reset dan login baru. Ini melengkapi assertion Notification fake untuk retry, expiry, pending sweep, dan gap commit-dispatch.

| Pemeriksaan | Hasil |
| --- | --- |
| `php vendor/bin/phpunit --configuration=phpunit.passwords.xml` | Lulus: 255 test, 2.178 assertion, exit code 0; MySQL 8.4 terisolasi `sorerunapp_test`, termasuk dua proses reset paralel |
| `php vendor/bin/pint --test` | Lulus |
| `php -l` pada file PHP baru/berubah | 21 file lulus |
| `php artisan route:list --path=api/v1/auth` | Dua endpoint publik reset terdaftar bersama route auth existing |
| `npm.cmd run lint` | Lulus |
| `npm.cmd run typecheck` | Lulus |
| `npm.cmd test` | 16 unit test lulus |
| `npm.cmd run test:e2e` | 56 test Chromium lulus, termasuk 14 skenario fitur reset dan 42 regresi existing; run akhir 1,9 menit |
| `npm.cmd run build` | Lulus; build produksi Next.js 16.4.2 mencakup `/forgot-password` dan `/reset-password` |
| Tampilan/aksesibilitas | Screenshot 320 px ditinjau; kontrol minimum 44 px, keyboard/fokus, error terhubung, dan reflow dengan pembesaran CSS 200% diuji di browser |
| `git diff --check` | Lulus |

Environment aktual: Windows/Laragon, Node 24.22.0, PHP 8.4.12, Next.js 16.4.2, dan dependency existing. Pengujian yang tertahan oleh sandbox Windows dijalankan di luar sandbox dengan izin eksekusi yang tersedia. Tidak melonggarkan assertion, CSRF, atau validasi agar test lulus.

Catatan tooling yang sudah ada: wrapper `php artisan test` pada sesi sebelumnya melaporkan test lulus tetapi exit code 1. Verifikasi akhir menggunakan runner PHPUnit langsung dan selesai dengan exit code 0. Pesan synthetic failure/queue outage pada suite adalah skenario rollback/retry yang disengaja, bukan kegagalan assertion.

Tidak mengirim email ke pengguna nyata atau provider SMTP eksternal. Browser memakai fixture API, sedangkan backend menjalankan API/broker/MySQL/kanal email secara terpisah; browser langsung ke Laravel, SMTP eksternal, pembaca layar/perangkat fisik, dan zoom browser native belum diklaim teruji. Pembesaran CSS bukan pengganti audit aksesibilitas lengkap.

Migrasi fitur hanya diterapkan ke database test. Untuk lingkungan aplikasi, penerapan migrasi additive, pengaturan SMTP/FRONTEND_URL, serta worker mail/scheduler tetap mengikuti prosedur operasional di atas. Tidak ada commit, push, deploy, perubahan data anggota nyata, atau operasi database destruktif.
