# Backend sorerunapp — operasi development lokal

Landing page/editor tahap 10 telah direview dan diuji pada lingkungan terisolasi; migration hanya dijalankan di sorerunapp_test, belum pada database aplikasi. Hasil dan batas penerapan: [LANDING_PAGE_STAGE10.md](../docs/LANDING_PAGE_STAGE10.md). Prosedur worker/scheduler di bawah tetap dipertahankan; publikasi konten tidak memerlukan worker baru.

Prosedur ini untuk aplikasi yang sudah disiapkan pada **Laragon/Windows**, PHP 8.4, MySQL 8.4, database **sorerunapp**. Database test terpisah: **sorerunapp_test**. Tidak memasang service Windows/startup OS/scheduled task permanen atau menjalankan migration/seeder otomatis.

## Prasyarat dan urutan menjalankan

1. Dependency sesuai lockfile, schema, role, kebijakan registrasi dan konfigurasi prefix nomor member sudah tersedia.
2. Aktifkan MySQL dan Mailpit Laragon tanpa relay/forward. SMTP 127.0.0.1:1025, inbox http://127.0.0.1:8025.
3. Konfigurasi efektif: APP_ENV local, APP_URL http://localhost:8000, FRONTEND_URL http://localhost:3000, MySQL loopback/database sorerunapp, queue/session/cache database, mailer smtp dan MAIL_URL tidak diisi atau bernilai null. Jangan mengisi MAIL_URL dengan string kosong. Pertahankan APP_KEY existing. .env.example menyediakan baseline MySQL/SMTP lokal; credential disesuaikan sendiri tanpa mencetak/commit .env.
4. Jalankan API dan frontend pada terminal masing-masing sesuai [README root](../README.md). Gunakan localhost secara konsisten untuk frontend/API browser dan Sanctum/CORS. Jika sudah berjalan, jangan membuka server/database/inbox kedua.
5. Pada terminal ketiga dari backend/, jalankan `composer dev`. Biarkan terminal terbuka selama development.

Launcher composer dev menggantikan launcher scaffold yang sebelumnya membuka server/Vite dan worker queue default. Tidak memerlukan concurrently/multiplex/dependency baru. Gunakan composer dev, bukan php artisan dev bawaan framework.

## Worker, scheduler dan batas penerima

Launcher memegang file lock per project agar tidak ada dua instance. Preflight menolak environment selain local, config cache aktif, database selain sorerunapp/MySQL 8.4 loopback, koneksi queue/cache berbeda, origin nonlokal dan SMTP selain Mailpit. Job pada mail-local diperiksa sebelum startup; job/penerima tak dikenal membuat startup ditolak tanpa mengubah pekerjaan itu.

Worker memakai **database / mail-local**. Supervisor menjalankan `queue:work database --queue=mail-local --once --sleep=1 --tries=1 --timeout=60` berulang. Setiap child menangani satu job/polling kosong lalu selesai, sehingga kode/config worker dimuat ulang setiap job. Pause/stop/restart menunggu child selesai sendiri; tidak memakai penghentian paksa pada alur normal Windows.

Supervisor menjalankan **scheduler Laravel sebenarnya**, `schedule:run`, pada startup dan sekali tiap pergantian menit UTC. Tugas due tetap ditentukan Schedule dan mutex withoutOverlapping. Restart dalam menit yang sama tidak menggandakan tick. Jangan menjalankan schedule:work/scheduler manual lain bersamaan.

Pada APP_ENV local hanya **outbox:dispatch --limit=100** dijadwalkan. Cleanup global auth:clear-resets users dan email-changes:prune tidak didaftarkan lokal agar token/request existing tidak terhapus otomatis. Expiry tetap diperiksa saat proof dipakai. Di luar local, jadwal cleanup existing tidak berubah. Pengujian cleanup/kegagalan/concurrency memakai sorerunapp_test; penerapan retensi pada data aplikasi merupakan tindakan terpisah yang disengaja.

Pengiriman otomatis lokal hanya untuk **akun sintetis @example.test**; alamat pending perubahan email juga harus @example.test. After-commit producer normal memilih mail-local untuk scope ini, dan sweep memulihkan scope yang sama. Worker memeriksa scope/SMTP lagi sebelum klaim delivery. Contoh: anggota-uji@example.test ke alamat-baru-uji@example.test; tautannya dibaca dari Mailpit.

Queue **mail** tetap menjadi queue normal di luar local dan tempat job local untuk penerima lain tetap berada. Launcher tidak mengambil/memindahkan/menghapus job mail/queue lain atau failed jobs. Alamat anggota nyata tidak diproses. Jangan menjalankan worker mail/default manual atau queue:retry all berdampingan dengan launcher. Pengiriman sintetis tidak memerlukan helper satu per request.

## Kontrol harian

Jalankan dari terminal lain di backend/:

| Command | Fungsi |
| --- | --- |
| `composer dev:status` | Lock/heartbeat, generation, PID child aktif, jumlah job per queue, failed jobs dan status delivery teknis |
| `composer dev:pause-worker` | Hentikan polling worker setelah child aktif selesai; scheduler tetap berjalan |
| `composer dev:resume-worker` | Mulai lagi worker dan proses job yang mengantre |
| `composer dev:restart` | Tunggu child selesai; generation naik setelah restart diakui |
| `composer dev:stop` atau Ctrl+C pada terminal launcher | Hentikan launcher setelah child aktif selesai |
| `composer dev` setelah stop | Jalankan kembali pada terminal yang tetap terbuka |

Kontrol mengirim permintaan; periksa status sampai acknowledged_control/generation/worker_enabled sesuai atau running false. PID worker berubah tiap polling/job; scheduler_pid null di antara tick adalah normal. Tidak menjalankan server/database kedua. State/control/lock berada di storage/framework/local-background/ yang diabaikan Git; source launcher ada di app/Console/Commands/RunLocalBackground.php. Jangan menghapus lock/state untuk menyalakan instance kedua. Jika OS mematikan proses secara paksa, cocokkan PID/command line child project sebelum menghentikannya, bukan kill berdasarkan nama php/node secara global.

## Log dan pemulihan email

```powershell
Get-Content storage/logs/local-background.log -Tail 30 -Wait
Get-Content storage/logs/local-scheduler.log -Tail 30 -Wait
Get-Content storage/logs/laravel.log -Tail 30 -Wait
```

local-background.log mencatat start/stop/restart, kontrol, tick scheduler dan exit code. local-scheduler.log memuat jumlah outbox yang dijadwalkan task sebenarnya. Laravel log mencatat sweep serta delivery ID/attempt/kode teknis pengiriman/retry. Tidak menyalin output exception/provider mentah. Jangan dump payload, sesi, token, signed URL atau credential ke laporan/log.

| Kondisi | Arti |
| --- | --- |
| pending (outbox) | Delivery sudah commit, belum diklaim; belum tentu mempunyai job |
| queued | Ada row jobs pada queue yang sesuai; bukan status notification_deliveries |
| processing | Worker memegang lease delivery 180 detik |
| sent | SMTP menerima pengiriman dan write sukses dicatat; bukan bukti email dibaca |
| failed (outbox) | Delivery gagal; attempt < 5 akan dijadwalkan setelah available_at |
| cancelled | Delivery tidak relevan, misalnya proof consumed/expired/stale; tidak dikirim ulang |
| failed_jobs | Job queue mengalami exception tak tertangani; berbeda dari kegagalan SMTP yang ditangani outbox |

Backoff outbox 60/300/900/3600 detik, maksimum lima percobaan. Sweep tiap menit memulihkan pending tanpa job, failed yang sudah due dan lease processing kedaluwarsa. Queue retry_after 90, job timeout 60, SMTP timeout 15 dan lease 180 detik. PHP Windows tidak menyediakan pcntl: timeout CLI bukan jaminan hard kill. Proses satu job dan timeout SMTP membatasi operasi development normal.

Bila email tidak masuk: periksa launcher/heartbeat, mail-local dan domain aktif/pending @example.test, Mailpit/SMTP, last_error_code/available_at/attempt serta log tick. Normal after-commit diproses beberapa detik; recovery/retry menunggu tick menit berikutnya dan backoff. Perbaiki penyebab lalu resume/restart. Jangan mempercepat timestamp data aplikasi, mengosongkan queue, menghapus failed jobs, atau menerbitkan token baru pada retry. Proof revoked/consumed/expired/stale tetap ditolak. Penghentian paksa dapat memerlukan lease recovery dan provider tidak menjanjikan exactly-once.

## Pengujian

Feature test/injeksi kegagalan menggunakan MySQL 8.4 **sorerunapp_test**, mail fake, konfigurasi terisolasi:

```powershell
php tests/Support/prepare-email-change-database.php
php vendor/bin/phpunit --configuration=phpunit.passwords.xml --filter="LocalBackgroundTest|NotificationOutboxTest|PasswordResetDeliveryTest|EmailChangeDeliveryTest"
```

Smoke berikut secara eksplisit memakai **sorerunapp development**, browser nyata dan Mailpit, dan mempertahankan akun sintetis yang dibuat. Jalankan hanya setelah API/frontend/inbox dan composer dev aktif, bukan pada environment bersama:

```powershell
cd ..\frontend
node tests/operations/local-background-smoke.mts
```

Helper menolak database test/remote dan SMTP eksternal. Cakupan: request browser normal, pause/resume worker, gap commit-dispatch pada data sintetis baru, tick scheduler, tautan verifikasi/login, restart dan digest akun existing. Provider failure/rollback/lease/stale retry diuji pada database test; layanan email pengguna tidak dimatikan. Hasil dan ID akun sintetis disimpan di backend/storage/app/private/local-background-smoke/. Tidak memakai fixture API pada smoke dan tidak melakukan cleanup massal.

## Super Admin dan provisioning Admin — tahap 11

Implementasi disiapkan dan diuji terisolasi 21 November 2026; hasil serta batas ada pada catatan Stage11. Migration database/migrations/2026_11_21_120000_add_super_admin_and_admin_invitations.php telah diterapkan pada **sorerunapp_stage11_test (batch 18)** dan, setelah backup/restore terverifikasi serta persetujuan, **development lokal sorerunapp (batch 7)**. Database sesi manual sorerunapp_test tidak dimigrasi atau direset. Kode auth/permission membutuhkan schema tersebut; tidak ada fallback yang mengabaikan tabel belum tersedia. .env/dependency tidak diubah.

Bootstrap aplikasi development lokal **berhasil 21 November 2026** setelah pemilik menyelesaikan aktivasi sendiri: **ID 21 / superadmin@sorerun.id**, enabled/verified, role **super_admin**, auth_version **3**, sesi dicabut dan audit bootstrap satu kali. Backup/restore terverifikasi serta checklist manual ada pada catatan Stage11. Login http://localhost:3000/login memakai password yang ditetapkan sendiri; form http://localhost:3000/admin/accounts/new. Jangan mengulang bootstrap, membuat membership fiktif, mengisi verified_at manual atau memakai reset untuk verifikasi.

Panduan dan daftar pengujian: [SUPER_ADMIN_STAGE11.md](../docs/SUPER_ADMIN_STAGE11.md). Akun Admin dibuat dari /admin/accounts/new hanya oleh Super Admin; undangan 24 jam, resend cooldown 60 detik, penerima membuat password sendiri dan login manual. Tidak membuat keanggotaan/QR. Outbox/worker/scheduler existing menangani tipe admin_invitation; development otomatis tetap dibatasi @example.test dan Mailpit, bukan penerima nyata.

Daftar akun ber-role Admin tersedia bagi Super Admin pada **http://localhost:3000/admin/accounts**, melalui tombol **Daftar Akun Admin** di Beranda operasional atau halaman pembuatan Admin. Daftar berhalaman menampilkan status akun/verifikasi dan tombol status undangan bagi akun hasil provisioning. Admin biasa tidak dapat membaca API atau halaman daftar. **Hapus Akun** di samping status undangan membuka konfirmasi penghapusan logis: hanya Admin operasional tanpa profil/membership/role lain, bukan self/Super Admin. Akun diblokir, role/sesi/undangan dicabut, email dan audit tetap disimpan; email tidak dapat digunakan ulang. Tidak ada pemulihan/purge melalui UI. Tidak memerlukan migration tambahan atau mengubah publikasi/media existing.

Pengujian Stage11 menggunakan `php vendor/bin/phpunit --configuration phpunit.administration.xml`; regresi terisolasi menggunakan `phpunit.administration-regression.xml`. Keduanya mengunci target sorerunapp_stage11_test/MySQL8.4. Persiapan schema test dijelaskan di catatan Stage11; tidak ada reset otomatis. Browser fixture dan integrasi nyata memakai konfigurasi Playwright administration dan administration-live pada frontend. Jalankan berurutan; integrasi memakai port 3130/8130, queue database test dan Mailpit lokal 1025/8025. Jangan menjalankannya bersama suite yang mengubah database test yang sama.

Bootstrap pertama merupakan langkah penerapan terpisah. Setelah migration, target/backup dan identitas akun disetujui, command yang disiapkan:
```powershell
# Contoh sintaks saja; ganti ID/email dengan akun yang disetujui secara eksplisit.
php artisan admin:bootstrap-super --user-id=ID_YANG_DISETUJUI --email=EMAIL_YANG_DISETUJUI
```

Command membaca environment/host/database aktual MySQL 8.4, menampilkan target dan meminta konfirmasi interaktif (default batal); tidak menerima persetujuan noninteraktif. Target harus existing, enabled/verified/aktivasi selesai. Transaksi mempertahankan role/membership, menambah super_admin, menaikkan auth_version, mencabut sesi dan mencatat audit. Pagar security_bootstraps mempertahankan status selesai meskipun role kemudian dicabut; tidak reset pagar atau menghapus audit untuk mengulang bootstrap. Pemulihan kehilangan Super Admin membutuhkan prosedur terpisah yang disetujui, bukan form publik/seeder.

Migration MySQL DDL tidak atomik penuh. Jika gagal, inspeksi status/constraint/tabel sebelum mencoba pemulihan; jangan memakai fresh/wipe atau rollback buta. down menolak bila provisioning/bootstrap/assignment Super Admin sudah ada. Backup dan rencana penerapan diperlukan sebelum menyentuh database aplikasi.

### Perubahan password administratif

Member/Admin/Super Admin yang akunnya enabled dan emailnya terverifikasi dapat mengubah password sendiri melalui **Profil → Keamanan Akun → Ubah Password**, dengan password akun saat ini. Pada Member, kontrol berada di Profil Anggota. Setelah berhasil, login ulang menggunakan password baru; membership, nomor member dan QR tetap. Super Admin dapat mengubah password Admin aktif melalui **Daftar Akun Admin → Ubah Password**, tanpa email konfirmasi, memakai password Super Admin sebagai konfirmasi. Sesi Admin sasaran dicabut; sesi Super tetap aktif. Tidak mengaktifkan undangan pending, memverifikasi email, membuka blokir atau memberikan Member hak mengganti password akun lain. Password minimal 12 karakter dan konfirmasi sama; jangan menyimpan credential pada catatan/log. Tidak membutuhkan migration/konfigurasi baru.

### Aktivasi akun operasional pertama development lokal

Alur ini hanya menutup bootstrap awal tanpa Super Admin aktif. Perintah tidak membuat akun atau memberikan role; target ID/email harus sudah ditetapkan eksplisit, enabled/unverified dan tanpa role/profil/membership. Memerlukan migration additive `2026_11_21_180000_support_first_account_activation.php`, schema Stage11, MySQL 8.4 lokal, origin loopback, queue database dan Mailpit SMTP 127.0.0.1:1025 tanpa MAIL_URL. Environment produksi/remote ditolak. Jangan mengubah .env atau mematikan recipient guard agar lolos.

Urutan dari backend, setelah backup/target/migration disetujui dan diverifikasi:

```powershell
php artisan admin:activate-first-account --user-id=ID_YANG_DISETUJUI --email=EMAIL_YANG_DISETUJUI
php artisan admin:deliver-first-activation --user-id=ID_YANG_DISETUJUI --email=EMAIL_YANG_DISETUJUI
```

Penerbitan meminta konfirmasi interaktif, masa berlaku 24 jam, cooldown 60 detik dan lima penerbitan/jam/akun. Mengulang command penerbitan setelah cooldown mencabut tautan lama. Purpose/proof terpisah dari undangan Admin dan reset. Pengiriman kedua memeriksa seluruh job queue `mail-bootstrap` hanya milik kandidat terikat sebelum menjalankan worker terbatas stop-when-empty/max-time=60; mail/mail-local tidak diproses. Retry provider memakai outbox/backoff/proof semula, bukan command penerbitan ulang. Jika pengiriman gagal, periksa status/error code delivery dan Mailpit, lalu ulangi command pengiriman setelah available_at; jangan menghapus jobs/failed jobs atau memproses antrean umum. Queue scheduler dapat memulihkan pending, tetapi worker mail-local normal tidak mengambil queue aktivasi khusus.

Penerima membuka **http://localhost:8025**, mencari subjek **Aktivasi Akun Operasional Pertama sorerunapp**, lalu membuka tombol **Aktifkan Akun Operasional** pada browser tamu/incognito dan menetapkan password sendiri (minimal 12 karakter). GET hanya form; klik POST diperlukan. Jangan membagikan tautan atau password, dan jangan menyimpan proof pada localStorage/log. Setelah berhasil, akun terverifikasi **tanpa role**. Penerima memberi tahu operator, operator memeriksa status lalu menjalankan bootstrap existing dengan ID/email sama. Akun perlu login setelah bootstrap; `/login` → `/beranda`, form Super `/admin/accounts/new`. Tidak auto-login atau returnTo. Email ke Mailpit bukan bukti mailbox produksi.

Script reusable `scripts/local-first-activation.php` berada pada source, menyediakan `inspect`, `backup`, `verify RUN`, dan `apply-activation RUN`. Backup/apply mensyaratkan maintenance; backup dienkripsi dengan APP_KEY existing, restore nyata pada schema baru `sorerunapp_activation_restore_*` dan digest/media diverifikasi. Output privat pada storage/app/private/stage11-activation diabaikan Git. APP_KEY harus dipertahankan aman secara terpisah. apply hanya menerima satu migration aktivasi pending dan backup sumber yang belum berubah; tidak menjalankan migration unrelated. Gunakan artisan up dalam finally, jangan memulihkan dump di atas aplikasi aktif atau mengarahkannya ke clone. Pemulihan aplikasi tetap langkah terpisah dengan persetujuan dan rekonsiliasi erasure/sequence menurut arsitektur.

Pengujian aktivasi menggunakan suite administration existing dan browser `tests/administration-live/first-activation.spec.ts`, hanya DB sorerunapp_stage11_test/port 3130 dan 8130/storage test. Tidak memakai akun ID 21 atau mengatur password akun aplikasi saat automated test.
