# Super Admin, provisioning dan undangan — tahap 11

Tanggal: 21 November 2026. Acuan PRD FR-22–24/AC-36–42, ARCHITECTURE §7.2/8.4/11.6, SYSTEM_DESIGN §11.6. Keputusan role, undangan 24 jam, resend 60 detik, akun operasional, izin terpusat dan bootstrap eksplisit disetujui pemilik produk.

**Status: aktivasi browser diselesaikan pemilik akun dan bootstrap development lokal berhasil. ID 21 / superadmin@sorerun.id enabled, terverifikasi, role super_admin, auth_version 3; sesi dicabut dan satu audit bootstrap tercatat. Menunggu pengujian manual login/provisioning Admin pada aplikasi. Riwayat pengujian/penerapan sebelumnya tetap tercatat di bawah.**

Riwayat sesi persiapan 21 November 2026: hanya inspeksi source/Git, edit lokal dan review diff statis, tanpa eksekusi. Sesi review berikutnya diotorisasi menjalankan pemeriksaan, migration dan bootstrap sintetis hanya pada database test khusus. Tidak mengubah .env aktual, role akun existing, database aplikasi, commit/push/deploy.

## Baseline dan batas

Branch dev, HEAD 35b8169 saat awal sesi. Seluruh perubahan lokal Stage09/10, worker/scheduler, dokumentasi dan dropdown jenis kelamin dipertahankan. Proses/data sesi manual Stage10 dipertahankan. Kondisi aktual diperiksa ulang sebelum pengujian; database khusus sorerunapp_stage11_test dipakai agar sorerunapp_test tidak perlu direset atau dimigrasi.

Fitur ini tidak membuat modul manajemen pengguna umum, event, attendance atau laporan yang belum tersedia. Super Admin memperluas seluruh izin administratif yang tersedia melalui AdministrativePermissions, bukan Gate::before global. MemberProfilePolicy tetap ownership sendiri; status akun, auth_version, CSRF dan constraint domain tetap berlaku. Multi-role dipertahankan.

## Implementasi

- RoleCode/parser sesi menerima super_admin; policy landing, shell, query editor/preview dan navigasi memakai helper administratif bersama.
- /admin/accounts/new memakai shell existing, nama/email, Role: Admin tetap, loading/inline error, hasil/status undangan, cooldown, resend dan pemeriksaan status.
- Hasil menyediakan tautan privat /admin/accounts/{userId}/invitation yang dapat dibuka ulang/dibookmark oleh Super Admin untuk status/resend; tidak menyediakan daftar/manajemen seluruh pengguna. Route tetap guarded/noindex dan data dimuat browser melalui API.
- Request whitelist menolak role/status/actor/verifikasi, query tambahan dan JSON invalid/terlalu besar. Actor dari sesi; action mengunci actor/target secara ID menaik dan mengulang policy serta versi.
- admin_provisionings mengikat request_key per pelaku dan hash nama/email ternormalisasi. Retry sama menghasilkan akun/status sama tanpa akun/outbox/audit ganda; beda payload 409. Email existing/unique race 409 tanpa promosi. Request tak pasti mempertahankan kunci/data hanya di memori; UI meminta retry eksplisit dengan data sama.
- User baru enabled/unverified, password hash acak yang dibuang, role Admin, provisioning/invitation/audit/outbox commit bersama. Tidak ada profil/membership/nomor/QR/sequence/policy acknowledgement fiktif.
- /accept-admin-invitation membaca proof fragment, menghapus address bar dan memakai memori tab dengan cleanup route/Activity/logout. GET tidak bermutasi. POST eksplisit stateful/CSRF memeriksa proof/email/version/status/expiry/role; sesi akun lain ditolak tanpa perubahan identitas.
- Aktivasi menetapkan password minimum 12 karakter, maksimum 72 byte tanpa null dan konfirmasi cocok; memverifikasi email berdasarkan undangan, mengisi activated_at/consumed_at, auth_version +1, mencabut sesi/broker proof/delivery dan audit dalam transaksi. Tanpa auto-login/returnTo.
- Login/middleware menolak provisioning pending, termasuk setelah reset password. Reset mencabut undangan lama tanpa memverifikasi email. Resend khusus Super Admin setelah 60 detik menerbitkan proof baru 24 jam, membatalkan proof/delivery lama.
- GET /me/account whitelist untuk identitas sesi sendiri; Beranda/Profil tanpa membership memakai konteks operasional tanpa GET profil anggota. Multi-role dengan membership tetap memakai fitur anggota. Perubahan email existing mendukung scope akun tanpa memecah backend transaksi Stage09.
- Outbox admin_invitation memakai encrypted proof sementara, job ID-only, after-commit/sweep/lease/backoff/fencing existing. Worker memeriksa proof terkini; SMTP di luar lock. LocalMailScope hanya penerima @example.test melalui Mailpit/mail-local. Tidak menjalankan pengiriman.
- Bootstrap CLI admin:bootstrap-super membutuhkan ID/email eksplisit, target enabled/verified/aktivasi selesai, target MySQL8.4 aktual dan konfirmasi interaktif. Singleton security_bootstraps menjadi pagar satu kali/paralel; role/membership lama tetap, auth_version naik, sesi dicabut dan audit actor NULL. Tidak menyediakan form/API Super Admin baru.

## Migration dan konfigurasi

Migration baru database/migrations/2026_11_21_120000_add_super_admin_and_admin_invitations.php memperluas CHECK roles, menyediakan metadata role Super Admin dan tabel admin_provisionings/admin_invitations/security_bootstraps dengan FK/unique/index. Tidak mengedit migration lama, tidak membuat/mempromosikan akun. Pada sesi review diterapkan hanya pada sorerunapp_stage11_test, batch 18. MySQL DDL tidak atomik penuh; review status/backup sebelum penerapan aplikasi. Rollback menolak data provisioning/bootstrap/assignment existing.

**Kode autentikasi baru membutuhkan schema ini sebelum digunakan.** Tidak ada fallback untuk mengabaikan tabel belum tersedia. Schema sudah diterapkan pada sorerunapp dengan otorisasi pemilik produk; bootstrap masih menunggu akun memenuhi syarat. Jangan menjalankan helper/migration pada database sesi manual tanpa koordinasi.

Tidak ada dependency baru. Expiry/cooldown ditetapkan sebagai konstanta domain, tidak diasumsikan sama dengan token lain. .env aktual tidak disentuh. PHPUnit memakai APP_KEY sintetis khusus test, MySQL loopback sorerunapp_stage11_test, mail array, cache array dan dispatch otomatis off. Integrasi nyata memakai database yang sama secara berurutan, session cookie/prefix cache/storage test tersendiri dan SMTP Mailpit loopback. Tidak memproses queue database aplikasi.

## Sumber pengujian

| Sumber | Cakupan |
| --- | --- |
| backend/phpunit.administration.xml dan tests/Support/AdministrationFixtures.php | Guard target MySQL8.4 sorerunapp_stage11_test aktual, fake mail/cache, schema wajib dan dispatch off |
| AdminProvisioningTest | Permission/CSRF/field whitelist, normalisasi/limit, idempotensi/conflict, aktivasi/invalid proof, resend, reset terpisah, ownership dan sesi terlambat, blocked/unverified, role/version recheck, rollback create/aktivasi, encrypted proof dan bootstrap |
| AdminInvitationDeliveryTest | Outbox committed tanpa outer transaction, sweep fake queue, provider failure/backoff/retry dan expiry cancellation; fixture sintetis committed dibersihkan secara scoped |
| AdminConcurrencyTest + administration-race-worker.php | Dua proses/koneksi MySQL nyata dengan barrier, RR eksplisit; email sama, idempotensi sama/beda payload, accept sama, resend versus accept, reset versus accept, bootstrap dua target, pencabutan role versus provisioning |
| frontend/tests/unit/administration.test.ts | Parser/helper multi-role, whitelist form, password policy, fragment dan lifecycle memori |
| frontend/tests/browser/super-admin.spec.ts | HTTP fixture saja: UI Super/Admin/member/staff, idempotensi setelah respons putus, Profil operasional, explicit accept/no auto-login, sesi akun lain, reflow 320px |

Hasil aktual tercatat di bawah. Browser fixture tetap tidak membuktikan integrasi Laravel/MySQL/email; konfigurasi administration-live menguji jalur nyata secara terpisah.

## Prosedur verifikasi ulang

1. Pastikan sesi manual test pengguna selesai/terkoordinasi. Periksa runtime/script, MySQL8.4 test, configuration cache dan storage/port terisolasi; jangan memakai database sorerunapp.
2. Helper tests/Support/prepare-administration-database.php default hanya preflight/inspect. --create --baseline menyiapkan database Stage11 khusus hanya bila schema kosong; --migrate menerapkan migration Stage11 setelah guard. Verifier migration membutuhkan baseline sebelum Stage11 tanpa akun/role. Helper tidak mereset DB atau mempromosikan akun; jangan mengulang verifier pada database yang sudah dipakai.
3. Jalankan PHPUnit konfigurasi administration dan regression secara berurutan; perbaiki hasil aktual. Jangan menjalankannya bersamaan dengan integrasi nyata pada database yang sama.
4. Jalankan unit/frontend lint/typecheck, Pint scoped, browser fixture baru dan regresi auth/landing. Build memakai API/output test terisolasi, tanpa fallback sukses palsu.
5. Integrasi nyata terpisah: browser Super Admin sintetis → Laravel → MySQL test → outbox → inbox lokal → aktivasi Admin → login → landing/editor/Profil operasional. Buktikan tidak ada membership/QR, draft tetap privat, permission negatif dan sesi lama ditolak.
6. Visual desktop/320px, keyboard/fokus/errors, zoom/reduced motion dan navigasi lima item bila multi-role. Periksa konten tidak tertutup bottom bar.
7. Catat hasil nyata dan batas. Jangan menjalankan bootstrap pada database aplikasi atau memilih akun sasaran sendiri. Setelah lolos baru minta instruksi penerapan/identitas akun/backup.

Catatan hasil historis Stage09/10 tidak diubah menjadi hasil tahap11. AGENTS hanya ditambah aturan provisioning/identity eksplisit; dokumen Final tetap, kontrak utama backend diperbarui dan docs/API_CONTRACT.md tetap indeks. Tidak ada kesiapan produksi yang diklaim.

## Hasil review dan pengujian — 21 November 2026

### Isolasi dan migration

Runtime aktual: PHP 8.4.12 (GD/WebP tersedia), Node 24.22.0, npm 11.19.1, MySQL 8.4.3. Database aplikasi sorerunapp dan database manual sorerunapp_test dibedakan dari **sorerunapp_stage11_test** yang baru disiapkan. Tidak ada reset pada database existing. APP_KEY test sintetis; konfigurasi efektif dan guard memeriksa environment, database aktual, versi MySQL, host loopback, mail/cache dan dispatch. Nilai `OUTBOX_DISPATCH_ON_COMMIT="(false)"` pada XML diperlukan agar PHPUnit/Laravel mempertahankan boolean false; guard tidak dilonggarkan.

Command yang dijalankan dari backend:

```powershell
php tests/Support/prepare-administration-database.php --create --baseline
php tests/Support/verify-administration-migration.php
php vendor/bin/phpunit --configuration phpunit.administration.xml
php vendor/bin/phpunit --configuration phpunit.administration-regression.xml
```

Baseline hanya diterapkan karena schema Stage11 baru kosong. Verifier kemudian menerapkan migration Stage11 **batch 18**, membuktikan role/pivot lama tetap, CHECK menolak kode asing (3819), role Super diterima tanpa akun default, rollback kosong mempertahankan baseline, dan rollback ketika Super sudah digunakan ditolak tanpa menghapus assignment. Fixture verifier dibersihkan secara scoped. Verifier bukan perintah yang boleh diulang pada schema berisi data.

Konfigurasi regression menambahkan scope tetap Stage11 pada guard existing; target default historis tetap sorerunapp_test. Tidak menerima nama database bebas dan tidak menghapus pemeriksaan MySQL/status sesi/cache. Storage integrasi berada di backend/storage/framework/testing/stage11-media; session cookie stage11_live_session dan cache prefix terpisah. API 8130, frontend 3130; fixture auth 3100 dan landing 3110/18100. Output `.next/stage11-browser`, `.next/stage11-live`, `.next/stage11-build` terisolasi; source aplikasi tetap diperiksa ESLint/TypeScript strict.

### Temuan dan perbaikan

- Callback submit React Hook Form dibungkus event handler agar ref pencegah submit ganda tidak dibaca saat render; hasil idempotensi/validasi dipertahankan.
- Narrowing sesi pada filter menu memakai nilai membership yang sudah dipastikan tersedia, menghilangkan error nullable tanpa cast paksa.
- Jalur replay provisioning mengunci target user sebelum provisioning, konsisten dengan resend/accept; actor lock tetap menserialisasi kunci permintaan dan permission diulang setelah lock.
- Route status undangan dinamis mengungkap `usePathname()` di ApplicationScope tanpa Suspense saat prerender. Ditambahkan batas Suspense dengan fallback loading, tanpa menonaktifkan Cache Components/Partial Prefetching atau mengubah guard/provider sesi.
- Konfigurasi Playwright tidak menggabungkan dua webServer pada port sama. Selector label/form/navigasi diperjelas sesuai accessible name, tanpa mengubah komponen untuk menyenangkan test.
- Fixture navigasi landing menyatakan membership sintetis secara eksplisit untuk regresi Admin+member; akun operasional tanpa membership diuji terpisah. Tidak menghapus ekspektasi Kartu bagi anggota atau melonggarkan permission API.
- Helper login browser menunggu Beranda tampil setelah URL berubah sebelum mengukur request transisi berikutnya. Assertion landing tetap menuntut tidak ada request /auth/me; path pemicu dicatat hanya dalam assertion bila gagal, tanpa cookie/token.
- Format PHP diperbaiki hanya pada file Stage11 dan integrasi yang tersentuh. Tidak ada upgrade dependency, perubahan .env aktual, atau pengaturan pemeriksaan yang dinonaktifkan.

### Hasil aktual

| Pemeriksaan | Hasil |
| --- | --- |
| Migration baseline → Stage11 dan rollback terkontrol | Lulus, batch 18 hanya database Stage11 khusus |
| PHPUnit administration (hasil terakhir) | **25 test, 246 assertion lulus**, termasuk Super Admin menyimpan/preview/publish landing dengan CSRF |
| Delapan race MySQL (bagian suite di atas) | Lulus; proses paralel, barrier, REPEATABLE READ, CONNECTION_ID berbeda |
| PHPUnit administration-regression | **186 test, 1.921 assertion lulus**: registrasi, login/sesi, verifikasi, reset, perubahan email, landing |
| Unit frontend `npm test` | **27 test lulus** |
| ESLint dan TypeScript strict | Lulus; pemeriksaan diulang setelah perbaikan |
| Pint scoped `--test` | Lulus; bukan klaim seluruh PHP repository diformat |
| Browser auth/Stage11, konfigurasi administration | **78 test lulus**, terdiri dari 71 regresi existing dan 7 test Stage11 |
| Pengulangan setelah perbaikan Suspense | **21 test routing/auth + Stage11 lulus**, termasuk halaman status undangan dinamis |
| Browser landing/editor/SSR, konfigurasi landing | **14 test lulus**, diulang setelah perbaikan provider; HTTP fixture terisolasi |
| Integrasi nyata administration-live, Next dev | **1 alur lulus** melalui Laravel/MySQL/queue/Mailpit, bukan route fixture |
| Integrasi nyata administration-live, hasil build setelah perbaikan | **1 alur lulus**, termasuk route status undangan dinamis; bukan fixture HTTP |
| Build Next terisolasi | Lulus setelah perbaikan Suspense; semua 22 halaman diproses, root/status undangan memakai partial prerender |

Race mencakup dua pelaku dengan email sama (satu akun), kunci/payload sama (satu akun/outbox), kunci sama/payload berbeda (409), token sama (satu aktivasi/audit), resend vs accept (salah satu urutan legal), reset vs accept (proof undangan lama invalid), pencabutan role yang commit dahulu vs provisioning (403 tanpa akun), bootstrap dua target (maksimal satu Super). Assertion memeriksa hasil akhir, bukan sekadar menerima exception.

Bootstrap sintetis diuji lewat Action dan CLI interaktif test: ID/email cocok, blocked/unverified ditolak, role/membership lama dipertahankan, auth_version naik, sesi dihapus, audit tercatat. Fence tetap menolak bootstrap kedua walaupun role kemudian dilepas. Tidak ada bootstrap atau penetapan role akun existing pada database aplikasi.

Provider failure/retry memakai fake terisolasi: proof hash tetap, retry menghasilkan sent, expiry membatalkan delivery, payload terenkripsi dibersihkan. Sweep memulihkan outbox committed saat dispatch off. Assertion provider memastikan transaksi sudah dilepas. Queue nyata diuji terpisah oleh integrasi; job hanya membawa ID delivery, satu job mail pada database Stage11. Worker dibatasi max-jobs=1 dan max-time=90; tidak menjalankan worker/scheduler database aplikasi.

Integrasi nyata membuat actor sintetis dan melakukan bootstrap CLI, login Super, provisioning, membaca queue/outbox pending, menjalankan worker database, mengambil undangan penerima acak @example.test dari Mailpit, membuka GET read-only, aktivasi POST, login manual, membuka editor landing, menolak provisioning Admin biasa dan membuka Profil Akun. Memeriksa auth_version=2, status delivery sent, tanpa profil/membership/credential dan tanpa perubahan sequence. Sesi akun lain tidak diganti; storage browser tidak berisi proof. Akun/provisioning/outbox/fence milik run dibersihkan secara scoped; pesan sintetis dapat tetap ada di Mailpit. Inbox atau fixture lain tidak dibersihkan massal.

Integrasi juga diulang terhadap `next start` memakai hasil build terisolasi setelah perbaikan provider. Build memakai API origin localhost:8130 dan output administration-build; tidak memakai API/database aplikasi. Dari frontend, setelah build yang sesuai tersedia: `$env:SORERUNAPP_STAGE11_BROWSER_BUILD='1'` lalu `npx.cmd --no-install playwright test --config=playwright.administration-live.config.ts`. Tanpa flag ini konfigurasi memakai next dev. Playwright menolak reuse server existing dan menghentikan server miliknya setelah test. Jalankan berurutan dengan suite database lainnya; worker hanya mengambil satu job mail dari database test.

### Visual dan batas verifikasi

Screenshot desktop 1440 px dan mobile 320 px ditinjau langsung untuk form Super multi-role; form operasional desktop 1280 px, mobile 320 px serta Profil operasional 320 px juga ditinjau pada integrasi nyata hasil build. Sunset/Plus Jakarta Sans, label, fokus keyboard, lima tab, hit area >=44 px dan tidak ada overflow horizontal diperiksa. Reduced motion diaktifkan pada test multi-role. Screenshot dev memuat indikator Next.js; hasil build tidak memuat indikator tersebut. Belum ada audit screen reader/perangkat sentuh fisik atau pengujian zoom browser native 200% khusus Stage11.

Next.js 16.4.2 menampilkan advisory keamanan dan beberapa command berhenti untuk rekomendasi upgrade. Retry resmi berikutnya berjalan; pemeriksaan tidak dinonaktifkan dan dependency tidak diubah. Penilaian serta upgrade patch keamanan perlu ditangani sebelum penggunaan produksi; hasil test fitur tidak meniadakan advisory ini. Unit test sempat terhalang sandbox (`uv_os_get_passwd/ENOMEM`), lalu lulus ketika diulang dengan izin eksekusi yang sesuai.

Pemeriksaan akhir database Stage11: 0 users, 0 undangan, 0 jobs/failed_jobs dan fence bootstrap belum selesai setelah cleanup fixture milik run; metadata role dan schema tetap tersedia. Pemeriksaan information_schema menunjukkan sorerunapp dan sorerunapp_test masing-masing tetap 24 tabel tanpa tiga tabel Stage11; database khusus memiliki 27 tabel. Tidak ada migration atau bootstrap pada kedua database existing tersebut. .env aktual serta manifest/lock dependency tidak diubah pada sesi review. `git diff --check` lulus (hanya pemberitahuan normalisasi line ending Git).

Seluruh server/worker test milik sesi telah dihentikan. Pemeriksaan listener akhir: port 3100/3110/3130/8130/18100 tidak aktif; proses development pengguna pada 3000 dan 8000 tetap berjalan. Mailpit existing dipertahankan. Tidak memasang service OS atau mengaktifkan scheduler database aplikasi.

PRD/ARCHITECTURE/SYSTEM_DESIGN/AGENTS tidak direvisi lagi pada sesi review karena keputusan produk/izin tidak berubah. Catatan revisi sesi persiapan tetap historis. README backend dan status kontrak API diperbarui agar tidak lagi menyebut seluruh implementasi belum pernah dijalankan. docs/API_CONTRACT.md tetap indeks.

### Riwayat rencana sebelum penerapan development

Tunggu instruksi penerapan, periksa target/backup yang dapat dipulihkan, lalu terapkan migration Stage11 beserta dependensi yang telah ditinjau hanya pada database yang disetujui. Jangan menjalankan fresh/wipe atau rollback buta. Identitas ID/email Super Admin pertama harus diberikan pemilik produk; jangan memilih akun sendiri. Bootstrap aplikasi merupakan langkah terpisah dan belum diotorisasi/dijalankan. Setelah penerapan, restart proses project yang diperlukan dan lakukan smoke test development dengan Mailpit/penerima sintetis. Hasil ini tidak membuktikan provider produksi, readiness produksi atau modul berikutnya.

## Penerapan development lokal — 21 November 2026

Pemilik produk mengizinkan migration dan bootstrap hanya pada development lokal yang diverifikasi. Target awal masih berupa placeholder; kemudian diberikan ID 21/email superadmin@sorerun.id. Inspeksi sebelum perubahan menemukan keduanya belum ada. Setelah dilaporkan, pemilik produk menginstruksikan **membuat akun baru**. Tidak memilih atau mempromosikan akun existing lain.

### Target, backup dan pemulihan

- Konfigurasi efektif uncached: APP_ENV local, MySQL 8.4.3, host 127.0.0.1:3306, database aktual sorerunapp; berbeda dari sorerunapp_test/sorerunapp_stage11_test. API localhost:8000, frontend localhost:3000, Sanctum/CORS eksplisit, session/cache/queue database. SMTP Mailpit 127.0.0.1:1025; inbox localhost:8025. .env aktual tidak diubah.
- Seluruh prerequisite sudah Ran; hanya migration 2026_11_21_120000_add_super_admin_and_admin_invitations yang pending. Tidak menjalankan migration tahap lain atau seeder.
- Maintenance singkat memakai artisan down --retry=60, lalu selalu artisan up pada finally. Tidak menghentikan server pengguna atau menjalankan worker/scheduler. Tidak ada job pending ketika preflight.
- Backup SQL seluruh database memakai mysqldump --single-transaction, dienkripsi Laravel Crypt sebelum ditulis; tidak menyimpan SQL plaintext. Database tidak memiliki routines/events/triggers yang perlu peninjauan tambahan sebelum restore. Credential tidak dimasukkan ke argumen command atau laporan.
- Backup lokal yang diabaikan Git: `backend/storage/app/private/stage11-local/sorerunapp_before_stage11_20261121_044845_b9a24660.sql.enc`.
- Backup benar-benar didekripsi dalam memori dan dipulihkan ke **sorerunapp_stage11_restore_20261121_044845_b9a24660**. Seluruh **24 tabel** mempunyai jumlah/digest isi yang sama dengan sumber; sumber tetap sama sebelum migration. Tidak memakai database aplikasi untuk uji restore.
- Manifest: `backend/storage/app/private/stage11-local/manifest_20261121_044845_b9a24660.json`; hasil schema: `migration_20261121_044845_b9a24660.json` di direktori sama. Ledger sequence disertakan tanpa biodata. Tidak ada file foto/media yang direferensikan saat backup; tidak mengubah storage/media.
- Backup membutuhkan APP_KEY existing; kunci harus disimpan aman secara terpisah, tidak dimasukkan ke Git/laporan. Pembuktian ini bukan klaim disaster recovery atau RPO/RTO produksi.

Helper operasional privat mengikuti pola backup Stage09 di `backend/storage/app/private/stage11-local/operations.php`; bukan endpoint atau seeder. Pemeriksaan syntax lulus. Dari backend, command berikut telah dijalankan dan lulus:

```powershell
php storage/app/private/stage11-local/operations.php verify-backup 20261121_044845_b9a24660
```

Untuk mengulang restore pada schema baru yang unik (tidak menimpa schema existing), helper menyediakan:

```powershell
php storage/app/private/stage11-local/operations.php restore-backup 20261121_044845_b9a24660
```

Operasi restore-backup baru di atas **belum dijalankan**; jalur import/decrypt/digest yang sama sudah dibuktikan saat backup awal. verify-backup memverifikasi ulang file terenkripsi dan database hasil restore yang dipertahankan. Guard hanya menerima local/MySQL8.4/sorerunapp sebagai sumber serta nama restore berprefix tetap dan acak; tidak menyediakan target aplikasi untuk import. Saat pemulihan aplikasi diperlukan, minta persetujuan terpisah, pertahankan data baru/erasure marker/high-water mark, lakukan rekonsiliasi sesuai ARCHITECTURE §15.3, dan jangan arahkan aplikasi/worker ke clone ini secara otomatis. Jangan menurunkan sequence atau memulihkan backup lama di atas database aktif.

### Migration dan integritas

Migration Stage11 diterapkan dengan **path eksplisit**, sebagai **batch 7**. Sesudah penerapan:

- CHECK chk_roles_code enforced dan memuat super_admin; metadata role Super Admin tepat satu.
- Tiga tabel baru tersedia: admin_provisionings, admin_invitations, security_bootstraps. Primary/unique/index terkait tersedia, termasuk unique user_id dan (created_by, request_key); lima FK mengacu ke users.
- Tabel/data existing selain penambahan metadata role dan catatan migration mempunyai digest yang sama dengan backup. Role lama tetap sama. Tidak ada akun default yang dibuat oleh migration.
- Bootstrap fence first_super_admin masih kosong/belum completed. Tidak ada migration pending sesudahnya.

### Akun baru dan blocker saat penerapan awal

Bagian ini adalah riwayat sebelum persetujuan aktivasi awal. Blocker jalur aktivasi diselesaikan pada sesi lanjutan di bawah; status belum terverifikasi tetap berlaku sampai pemilik akun menyelesaikan form.

Atas instruksi lanjutan pemilik produk, dibuat akun operasional **ID 21 — superadmin@sorerun.id**, nama tampilan **Super Admin**. ID dialokasikan database, bukan memaksa primary key. Nama tampilan tidak memberikan permission.

Status aktual: enabled, **email belum terverifikasi**, auth_version 1, **tanpa role administratif maupun role lain**, tanpa profil/membership/nomor/QR/sesi. Password awal berupa hash nilai acak yang tidak disimpan/diberikan; tidak memakai password bawaan bersama. Pembuatan akun dan audit `security.bootstrap_candidate_created` commit bersama, actor NULL/source local_cli. Data akun existing, role/pivot existing dan sequence anggota tetap sama setelah akun baru dikecualikan.

**Command bootstrap tidak dijalankan.** Tidak mengubah email_verified_at untuk meloloskan bootstrap, tidak membuka blokir, tidak menghapus fence/audit, tidak memilih akun pengganti. Tidak ada audit security.super_admin_bootstrapped dan belum ada pengguna ber-role Super Admin pada aplikasi.

Blocker konkret: verifikasi publik existing (`VerifyEmailAndActivateMembership` dan resend) memerlukan membership pending; undangan Admin memerlukan penerbit Super Admin yang sudah sah. Akun operasional pertama yang baru dibuat belum mempunyai jalur verifikasi/pengaturan password tanpa membership. Reset password tidak dapat memverifikasi email. Selain itu pengiriman otomatis development tetap membatasi penerima @example.test, sedangkan alamat kandidat ini bukan alamat sintetis. Tidak melewati pembatas tersebut atau mengirim email nyata.

Untuk melanjutkan perlu keputusan terpisah: memakai pasangan akun existing yang sudah terverifikasi dan ditetapkan eksplisit, atau menyetujui alur aktivasi khusus akun operasional pertama melalui proof email dan pengaturan password sebelum menjalankan CLI bootstrap existing. Penambahan alur tersebut belum diimplementasikan pada sesi penerapan ini; jangan menandai akun verified secara manual sebagai penggantinya.

### Smoke aktual dan akses browser

Chromium nyata pada localhost:3000 → Laravel localhost:8000 → database sorerunapp, tanpa fixture HTTP:

| Pemeriksaan | Hasil |
| --- | --- |
| Landing tamu | HTTP 200, tidak redirect, 0 request /auth/me, CTA ke /login |
| Halaman login | Field email dan tombol Masuk tersedia |
| Akses provisioning oleh tamu | Halaman mengarah /login; API privat 401 |
| Health API dan API publik landing | Keduanya HTTP 200 |
| Login Super, provisioning/resend/aktivasi Admin sintetis dan role negatif Admin | **Belum dijalankan pada aplikasi**, menunggu bootstrap sah |
| Beranda/Profil operasional dan akun multi-role terautentikasi | **Belum dijalankan pada aplikasi**; hasil test terisolasi sebelumnya tidak diklaim sebagai smoke development |

URL aktual:

- Login: http://localhost:3000/login
- Form khusus Super Admin: http://localhost:3000/admin/accounts/new
- Editor landing: http://localhost:3000/admin/landing-page
- Inbox lokal: http://localhost:8025

**Akun ID 21 belum dapat digunakan login dan belum mempunyai akses form Super Admin.** Tidak ada credential yang dapat dibagikan sampai alur aktivasi sah diselesaikan. Tidak membuat Admin sintetis atau mengubah konten landing yang terpublikasi pada sesi ini. Tidak mengirim email, memproses queue lain, atau menjalankan suite ulang yang tidak diperlukan.

Server pengguna 3000/8000 dan Mailpit dipertahankan; browser smoke sudah ditutup. Maintenance tidak aktif. Worker umum/scheduler tidak ditemukan berjalan saat preflight dan tidak diaktifkan sesi ini. Tidak memasang service OS, mengubah .env, dependency/source aplikasi, commit/push/deploy. Advisory keamanan Next.js tetap tindak lanjut terpisah sebelum produksi.

## Aktivasi operasional pertama — 21 November 2026

Persetujuan lanjutan menetapkan **ID 21 / superadmin@sorerun.id / sorerunapp development lokal**. Tidak memilih pengganti, membuat akun lain, memberi membership atau mengisi verifikasi secara manual. Aktivasi melalui proof dan penetapan Super Admin dipisahkan; bootstrap tetap mensyaratkan verified/enabled dan hanya satu kali.

### Implementasi dan keputusan

- Migration additive `2026_11_21_180000_support_first_account_activation.php` menambah purpose pada admin_invitations, created_by nullable hanya untuk purpose awal, CHECK purpose/issuer dan indeks purpose/user. Purpose Admin existing default admin_invitation; issuer Admin tetap wajib. Rollback menolak jika riwayat aktivasi awal ada.
- `admin:activate-first-account --user-id=… --email=…` merupakan satu-satunya penerbit awal/resend, interaktif, local/sorerunapp atau test Stage11 MySQL 8.4 saja. Target enabled/unverified tanpa role/profil/membership/provisioning. Tidak ada endpoint penerbitan/pemilihan kandidat, password bawaan atau pemberian role.
- Lock user → security_bootstraps → invitation; user_id pada fence mengikat kandidat sejak penerbitan. Target lain tidak dapat menggantikannya. Recheck bootstrap belum completed/tidak ada Super, email/version/status/purpose/hash/expiry dan konsumsi di dalam transaksi. Cooldown 60 detik, lima penerbitan/jam/akun; proof lama dicabut saat resend. Binding fence tidak direset untuk memilih akun lain.
- Purpose first_account_activation tidak dapat dipakai endpoint undangan Admin/reset. Hash token tersimpan; proof retry sementara terenkripsi dalam notification_deliveries. Komponen penerbitan/notifikasi/finalisasi password existing digunakan ulang. GET /activate-first-account hanya form; POST /api/v1/auth/first-account-activation/accept stateful/CSRF dan rate limit terpisah. Browser sudah login ditolak tanpa perubahan identitas.
- Finalisasi password/verifikasi/konsumsi/auth_version/sessions/broker proof/audit atomik. **Tidak memberikan role apa pun.** Bootstrap CLI existing baru dijalankan setelah penerima menyelesaikan aktivasi dan status diverifikasi. Reset tetap tidak memverifikasi email dan membatalkan kedua purpose.
- Delivery awal memakai queue khusus **mail-bootstrap**. `admin:deliver-first-activation` memeriksa target, Mailpit dan job queue sebelum worker terbatas. Tidak mengambil mail/mail-local, tidak mengirim SMTP eksternal. Pengecualian alamat non-@example.test hanya untuk proof awal issuer NULL/kandidat fence; tipe lain tetap sintetis. Retry/sweep/fencing existing; SMTP di luar lock.
- UI memakai komponen aktivasi existing dengan copy operasional, proof store memori terpisah per purpose, cleanup route/logout, noindex, password manager/paste, fokus/error/loading dan tanpa auto-login/returnTo.
- Integrasi mengungkap peringatan instant-shell Next pada halaman status undangan dinamis. Pembacaan params dipindahkan ke anak dalam Suspense dengan LoadingPanel; guard dan Cache Components/Partial Prefetching tidak dilonggarkan.

### Pemeriksaan aktual

Semua database test tetap **sorerunapp_stage11_test, MySQL 8.4.3**, bukan sorerunapp/sorerunapp_test. Migration aktivasi test diterapkan sebagai **batch 19**; tidak ada reset. API 8130/frontend 3130, queue/session/cache test dan storage Stage11 terpisah. Test concurrency/database/live dijalankan berurutan.

| Pemeriksaan | Hasil aktual |
| --- | --- |
| Suite administration akhir | **54 test, 457 assertion lulus** |
| Concurrency MySQL nyata dalam suite | **14 race lulus**, termasuk enam baru: penerbitan kandidat berbeda/sama, dua accept, resend vs accept, reset vs accept, bootstrap setelah aktivasi |
| Regresi backend auth/reset/email change/landing | **186 test, 1.921 assertion lulus** |
| Verifier migration tambahan | Lulus: rollback/upgrade tanpa riwayat awal mempertahankan invitation/pivot Admin sintetis; CHECK purpose/issuer menolak tiga kombinasi invalid; rollback berisi riwayat awal ditolak pada feature test |
| ESLint | Lulus; diulang setelah perbaikan Suspense |
| TypeScript strict | Lulus; build juga memeriksa tipe setelah perbaikan |
| Unit frontend | **28 test lulus**, termasuk pemisahan proof memory per purpose |
| Pint scoped dan syntax CLI/script | Lulus |
| Browser fixture administration/regresi | **78 test lulus**; bukan bukti database/email nyata |
| Integrasi nyata, Next dev | **2 alur lulus**: provisioning Admin existing dan aktivasi awal → bootstrap terpisah → login Super |
| Build Next terisolasi | Lulus, 23 halaman, output .next/stage11-build, API 8130; tidak memakai database aplikasi |
| Integrasi nyata terhadap hasil build | **2 alur lulus**, status undangan dinamis dan aktivasi awal tersedia |
| Visual | Form aktivasi desktop 1280 px/320 px diperiksa langsung dari screenshot; fokus password terlihat, tidak overflow, kontrol/label mengikuti auth existing |
| git diff --check | Lulus; peringatan line ending tidak menyembunyikan error |

Feature test meliputi sukses tanpa role, target/fence mismatch, Bootstrap unverified ditolak, proof hilang/salah/expired/revoked/consumed/version/email stale/blocked/role berubah/bootstrap selesai, CSRF, password/konfirmasi, field role/purpose/user_id terlarang, session conflict, reset, cooldown/hourly limit, rollback audit penerbitan dan aktivasi. Provider fake membuktikan retry memakai proof sama, gap sweep, transaksi provider level 0, serta delivery expired dibatalkan. Tidak mengirim ke penerima nyata. Integrasi baru memakai candidate sintetis unverified/tanpa role, CLI issue, database queue, Mailpit, GET read-only, POST password, pemeriksaan **roles kosong dan fence belum completed**, bootstrap CLI terpisah, login dan akses provisioning/editor. Fixture milik run dibersihkan secara scoped; inbox tidak dikosongkan massal.

Next.js 16.4.2 menampilkan advisory keamanan dan sempat menghentikan startup browser. Perintah sama diulang sesuai mekanisme retry runtime; akhirnya lulus. Tidak menonaktifkan pemeriksaan atau mengubah dependency. Advisory tetap tindak lanjut terpisah sebelum produksi, bukan klaim keamanan produksi.

### Penerapan development dan backup

Konfigurasi efektif kembali diverifikasi: local/uncached, MySQL 8.4.3 sorerunapp pada 127.0.0.1:3306, API localhost:8000, frontend localhost:3000, queue/session/cache database dan Mailpit 127.0.0.1:1025 tanpa MAIL_URL/relay/forward. Akun 21 cocok, enabled/unverified, auth_version 1, role/profil/membership kosong. Tidak ada worker/scheduler aktif atau job pending pada preflight. Hanya migration aktivasi yang pending.

Script reusable source: **backend/scripts/local-first-activation.php**, bukan hanya helper storage. Maintenance singkat dan artisan up pada finally. Backup terenkripsi:

- `backend/storage/app/private/stage11-activation/sorerunapp_20261121_110227_a48fbd39.sql.enc`
- Manifest privat `manifest_20261121_110227_a48fbd39.json` pada direktori sama.
- Restore nyata ke **sorerunapp_activation_restore_20261121_110227_a48fbd39**; **27 tabel** mempunyai jumlah/digest sama, 0 media referenced; decrypt/digest ulang juga lulus. APP_KEY existing diperlukan, tidak diekspor/dicetak. Semua artefak privat diabaikan Git.

Hanya migration `2026_11_21_180000_support_first_account_activation` diterapkan pada **sorerunapp batch 8**. Kedua CHECK enforced; data existing sama setelah schema berubah. Tidak menjalankan seeder, fresh/wipe, migration unrelated atau bootstrap. Setelah penerbitan proof, digest users/roles/pivot/profil/membership/QR/sequence dan konten landing/revisi/media tetap sama. Perbandingan digest memakai session timezone UTC; false alarm awal pada timestamp landing hilang setelah kedua koneksi disamakan ke UTC, tidak ada perubahan editorial.

Urutan yang telah dijalankan dari backend:

```powershell
php scripts/local-first-activation.php inspect
# backup/apply dijalankan selama maintenance, up selalu di finally
php scripts/local-first-activation.php backup
php scripts/local-first-activation.php apply-activation 20261121_110227_a48fbd39
php scripts/local-first-activation.php verify 20261121_110227_a48fbd39
php artisan admin:activate-first-account --user-id=21 --email=superadmin@sorerun.id
php artisan admin:deliver-first-activation --user-id=21 --email=superadmin@sorerun.id
```

### Status handoff aktivasi manual

- Undangan lokal **delivery ID 43**, status **sent**, attempt 1, payload_encrypted sudah dibersihkan. Tepat satu pesan untuk target ditemukan pada Mailpit dengan subjek **Aktivasi Akun Operasional Pertama sorerunapp**. Worker khusus selesai/queue kosong; dua job ID-only (dispatch dan recovery) aman dipagari lease, satu pengiriman provider.
- Expiry undangan: **22 November 2026 pukul 18.04.34 WIB**. Tidak mencatat proof/tautan lengkap/password.
- Target **ID 21 / superadmin@sorerun.id** masih **unverified, tanpa role, auth_version 1**, token belum consumed; fence mengikat 21 tetapi belum completed. Tidak ada password yang dibuat/diberikan oleh operator. Tidak ada bootstrap aplikasi.
- Browser nyata pada aplikasi development, tanpa fixture: /activate-first-account dan /login HTTP 200, missing-proof meminta operator, noindex dan 320 px lulus, **0 POST aktivasi**; API /up dan UI Mailpit HTTP 200. Tidak membuka proof atau menetapkan password target.
- Proses existing dipertahankan: Next localhost:3000 (PID 13232), Laravel localhost:8000 (PID 12944), Mailpit UI localhost:8025/SMTP127.0.0.1:1025 (PID 11596), MySQL existing. Semua server browser test milik suite berhenti; worker aktivasi sudah berhenti, tidak menjalankan worker umum/scheduler. Maintenance false. Browser smoke ditutup; layanan development tetap tersedia.

Pemilik akun membuka **http://localhost:8025**, memilih email target/subjek di atas, membuka tombol **Aktifkan Akun Operasional** pada browser tamu/incognito, lalu membuat password sendiri minimal 12 karakter dan konfirmasinya. Klik aktivasi eksplisit; sukses belum memberikan role. Beri tahu operator setelah selesai. Jangan memakai reset untuk verifikasi atau membagikan tautan/password. Inbox lokal bukan pembuktian mailbox produksi.

**Langkah selanjutnya hanya setelah pemilik menyatakan aktivasi selesai:** periksa kembali pasangan ID/email/status verified, lalu jalankan admin:bootstrap-super existing sesuai otorisasi pada target yang sama, verifikasi role/auth_version/sessions/audit. Tidak meminta persetujuan ulang atau membuat Super kedua. Login setelah bootstrap: http://localhost:3000/login; form khusus Super: http://localhost:3000/admin/accounts/new; editor: http://localhost:3000/admin/landing-page. Belum mengklaim smoke Super pada database aplikasi atau kesiapan produksi.

PRD/ARCHITECTURE/SYSTEM_DESIGN/kontrak utama/backend README diselaraskan hanya untuk alur ini. ID FR/AC dan status Final dipertahankan. AGENTS tetap karena aturan existing memadai; docs/API_CONTRACT.md tetap indeks, .env/dependency tidak diubah. Tidak commit/push/deploy atau memproses queue/email akun lain.

## Bootstrap setelah aktivasi pemilik akun — 21 November 2026

Pemilik akun menyatakan aktivasi melalui browser berhasil dan meminta bootstrap dilanjutkan sesuai otorisasi. Pemeriksaan aktual sebelum eksekusi: database **sorerunapp**, APP_ENV local/uncached, MySQL **8.4.3** loopback 127.0.0.1:3306. Target ID **21** cocok dengan **superadmin@sorerun.id**, enabled/verified, proof first_account_activation consumed, auth_version **2**, tanpa role dan sesi. Fence terikat 21 tetapi belum completed; belum ada assignment Super atau audit bootstrap. Tidak memilih akun lain atau mengisi verifikasi/password secara manual.

Backup baru dibuat selama maintenance melalui script source existing, lalu diuji restore nyata dan diverifikasi ulang:

- Backup terenkripsi: `backend/storage/app/private/stage11-activation/sorerunapp_20261121_121802_99de24fa.sql.enc`.
- Manifest privat: `manifest_20261121_121802_99de24fa.json` pada direktori sama; diabaikan Git bersama backup.
- Database restore terpisah: **sorerunapp_activation_restore_20261121_121802_99de24fa**. **27 tabel** cocok jumlah/digest, 0 media referenced; tidak memakai aplikasi sebagai target restore. APP_KEY existing tidak ditampilkan/diekspor.

Command existing yang dijalankan **satu kali** dengan konfirmasi interaktif yang sudah diotorisasi:

```powershell
php artisan admin:bootstrap-super --user-id=21 --email=superadmin@sorerun.id
```

Berhasil pada **21 November 2026 pukul 19.18.09 WIB**. Hasil pemeriksaan setelah commit:

| Pemeriksaan | Hasil |
| --- | --- |
| Akun target | ID/email cocok, enabled, email tetap terverifikasi |
| Role akhir | **super_admin**; role sebelum bootstrap kosong |
| auth_version | **2 → 3**, sesi akun tersisa **0** |
| Password | Hash tetap sama dengan backup setelah aktivasi; tidak diketahui/diganti operator |
| Fence | first_super_admin completed, user_id 21; tidak direset atau dipaksa ulang |
| Audit | Tepat **1** security.super_admin_bootstrapped untuk target 21, audit ID **64**, actor NULL |
| Izin aktual target | AdministrativePermissions mengizinkan provisioning dan pengelolaan landing |
| Akun/pivot lain | Sama dengan backup; tidak ada akun lain yang diubah |
| Profil/membership/credential/sequence | Sama dengan backup; tidak dibuat data anggota untuk akun operasional |
| Provisioning/invitation/outbox/jobs/failed jobs/landing | Sama dengan backup; tidak mengirim email atau mengubah publikasi saat bootstrap |
| Maintenance | Dinonaktifkan melalui finally; aplikasi kembali aktif |

Tidak ada Admin biasa pada database aplikasi saat pemeriksaan. Karena itu penolakan provisioning Admin biasa **tidak diklaim sebagai login browser akun aplikasi**. Uji backend terarah dijalankan ulang hanya pada MySQL sorerunapp_stage11_test (konfigurasi XML dan koneksi aktual diperiksa):

```powershell
php tests/Support/prepare-administration-database.php --inspect
php vendor/bin/phpunit --configuration phpunit.administration.xml --filter test_only_super_admin_can_provision_and_extra_fields_and_csrf_are_rejected
```

Hasil: **1 test, 38 assertion lulus**. Guest/member/staff/Admin ditolak endpoint provisioning; hanya Super diterima, CSRF serta field role/status/actor terlarang tetap diperiksa. Fixture sintetis berada dalam transaksi test yang di-rollback; tidak mengubah akun aplikasi. Source runtime tidak berubah, sehingga tidak mengulang lint/build/seluruh suite yang sudah lulus.

Login localhost:3000/login, API localhost:8000/up dan Mailpit localhost:8025 diperiksa HTTP **200**. Proses existing Next PID 13232, API PID 12944, Mailpit PID 11596 tetap tersedia; worker/scheduler umum tidak ditemukan berjalan dan tidak dijalankan sesi ini. Tidak menjalankan migration/seeder, membuat Admin aplikasi, mengirim email, mengubah .env, commit/push/deploy. Belum melakukan login dengan password pemilik akun atau smoke provisioning pada database aplikasi.

### Checklist manual setelah bootstrap

1. Pertahankan MySQL, Mailpit, API 8000 dan frontend 3000 yang sudah aktif. Dari terminal backend jalankan **composer dev** bila worker/scheduler belum aktif; biarkan terminal terbuka. Gunakan composer dev:status bila ragu, jangan menggandakan worker/scheduler manual. Launcher hanya memproses scope lokal sintetis @example.test pada mail-local.
2. Buka **http://localhost:3000/login**, masuk dengan superadmin@sorerun.id dan password yang ditetapkan sendiri. Login menuju /beranda; menu Buat Admin dan Kelola Landing Page tersedia.
3. Buka **http://localhost:3000/admin/accounts/new**. Isi nama sintetis dan email **baru @example.test**, misalnya admin-uji-stage11-20261121@example.test. Role tetap Admin, klik Buat Akun Admin. Pending berarti undangan diterima untuk diproses, bukan email sudah terkirim.
4. Pada **http://localhost:8025**, pilih pesan penerima tersebut dengan subjek Undangan Admin sorerunapp. Buka tautannya pada browser/incognito terpisah tanpa sesi Super, buat password sendiri minimal 12 karakter lalu aktifkan. Jangan memakai command aktivasi awal atau reset sebagai pengganti undangan Admin.
5. Login sebagai Admin baru melalui /login. Pastikan /beranda, Profil Akun dan editor **http://localhost:3000/admin/landing-page** dapat diakses tanpa membership/QR.
6. Pastikan menu Buat Admin tidak muncul. Buka langsung **http://localhost:3000/admin/accounts/new** sebagai Admin: harus tampil Akses terbatas. Backend endpoint provisioning/resend juga menolak role Admin (403); jangan menaikkan role untuk meloloskan pengujian.

Status kesiapan: Super Admin pertama siap dicoba secara manual pada development lokal; pengujian manual provisioning Admin aplikasi masih menunggu pemilik. Hasil lokal bukan klaim kesiapan produksi; advisory Next.js existing tetap tindak lanjut terpisah.

## Pelengkap daftar akun Admin dan tombol undangan — 21 November 2026

Atas permintaan pemilik, menambahkan **/admin/accounts** khusus Super Admin beserta GET **/api/v1/admin/accounts**. Cakupan hanya akun yang saat ini ber-role Admin, termasuk akun lama dan multi-role; bukan direktori anggota atau modul perubahan role/status/penghapusan. Super Admin tanpa role Admin tidak termasuk daftar ini. Tidak membuat provisioning/undangan/membership untuk akun lama. Tombol **Daftar Akun Admin** tersedia pada Beranda operasional, form pembuatan, dan halaman status undangan; navigasi mobile existing tidak ditambah tab keenam.

Daftar urut ID menurun, 20 akun/halaman, menampilkan nama/email/status akun/verifikasi serta akses status undangan bagi akun hasil provisioning. Loading, gagal baca, kosong dan pagination dibedakan. API memeriksa sesi current/enabled/verified serta izin Super Admin, dengan pemeriksaan ulang actor dalam transaksi, rate limit baca 60/menit/actor, Resource whitelist, no-store/noindex. Query hanya page integer 1–1000000. Cache privat berscope actor/halaman dan memakai pembersihan logout existing.

Pada halaman **Status Undangan Admin**, **Buat Akun Admin** menjadi tombol outline dalam kelompok yang sama dengan **Periksa Status Undangan** dan **Kirim Ulang Undangan**, flex wrap dengan gap horizontal/vertikal **12 px**. Tombol kirim ulang tetap mengikuti cooldown dan disembunyikan setelah aktivasi. Tidak mengubah perilaku undangan atau permission.

Hasil pemeriksaan sesi ini:

| Pemeriksaan | Hasil aktual |
| --- | --- |
| Target backend | MySQL **8.4.3**, **sorerunapp_stage11_test**, environment testing; mail array dan after-commit dispatch false pada suite feature. Database aplikasi tidak disentuh |
| Feature provisioning termasuk daftar | **18 test / 333 assertion lulus**; sesudah memperketat penolakan body GET, tiga test daftar diulang: **3 test / 145 assertion lulus** |
| Cakupan test daftar | Guest/member/staff/Admin ditolak; blocked/unverified/stale/role dicabut ditolak; recheck izin dalam transaksi; legacy/multi-role/pending/blocked muncul sesuai scope; pagination, input ekstra/body GET, whitelist respons dan rate limit |
| Frontend | npm run lint dan npm run typecheck -- --incremental false lulus |
| Format PHP | Pint --test pada seluruh file PHP terdampak lulus; hanya import controller diurutkan |
| Browser fixture | **9 test lulus** pada playwright.administration.config.ts/super-admin.spec.ts, tanpa Laravel/database: list/pagination, error/kosong, logout, akses negatif, undangan dan navigasi multi-role |
| Visual | Screenshot desktop 1280 px dan 320 px ditinjau; tidak overflow horizontal, kontrol minimal 44 px. Gap kelompok tombol status diperiksa computed style **12 px** |
| Build terisolasi | next build lulus, **24 halaman**, output .next/stage11-build, API localhost:8130/test; Cache Components tetap aktif |
| Integrasi nyata | **1 test lulus** pada administration-live/administration.spec.ts menggunakan hasil build, browser 3130 → Laravel 8130 → MySQL test → database queue → Mailpit → aktivasi → login → editor. Admin sintetis terlihat pending lalu terverifikasi di daftar; Admin biasa ditolak halaman dan GET daftar **403** |
| Perbaikan test integrasi | Assertion awal status terverifikasi ambigu karena Super fixture juga mempertahankan role Admin. Diperbaiki dengan memilih kartu berdasarkan email target; pengulangan lulus |
| Batas operasional | Teardown browser fixture sempat tertahan oleh pembatas proses Windows; hanya server test buatan sesi ini dihentikan. Integrasi diulang dengan izin proses Windows dan selesai normal. Server aplikasi/worker/scheduler pengguna tidak dihentikan |

Fixture backend di-rollback; integrasi memakai akun/email @example.test serta cleanup terikat run miliknya. Tidak memakai ID 21/password pengguna atau memublikasikan landing. Tidak ada migration baru/diterapkan, perubahan .env, dependency, role/data akun aplikasi, commit/push/deploy. PRD §7/AC-37, ARCHITECTURE §8.4, SYSTEM_DESIGN §11.6, kontrak API utama dan backend README dilengkapi sesuai cakupan; AGENTS dan indeks kontrak root tetap memadai. Pengujian ini tidak mengulang seluruh suite concurrency/auth yang tidak berubah dan bukan klaim kesiapan produksi.

## Penghapusan logis Admin operasional — 21 November 2026

Pemilik menyetujui penghapusan logis: hilang dari daftar/tidak bisa login, sesi/undangan dicabut, audit dan email tetap disimpan. Cakupan hanya akun yang memiliki role Admin saja, tanpa profil anggota/membership. Self, Super Admin, multi-role dan akun anggota ditolak. Ini melengkapi batas daftar sebelumnya; bukan purge privasi/anonimisasi dan tidak menyediakan pemulihan melalui UI.

**Hapus Akun** memakai Button destructive di samping **Status Undangan**, gap 12 px/flex wrap. Dialog menyebut nama/email target, dampak, serta email yang tetap dicadangkan. Fokus awal Batal, Escape/batal tanpa mutation; konfirmasi eksplisit, loading/pencegahan ganda, error tetap terlihat. Timeout meminta perbarui daftar sebelum mengulang. Sukses menginvalidasi daftar privat dan menghapus cache status undangan target.

DELETE /api/v1/admin/accounts/{id}, JSON {}, Sanctum/CSRF/current/enabled/verified/Super dan limit 10/menit/actor. Action memakai lock user actor/target menurut ID dan recheck izin/eligibility. Transaksi memblokir akun, menaikkan auth_version, mencabut role Admin/sesi/undangan belum dikonsumsi/pending email-change/reset token/delivery tertunda, menghapus payload retry terenkripsi pada delivery yang dibatalkan, dan mencatat audit admin.account_deleted. Tidak memanggil provider/storage. User/email/provisioning/idempotency key, publikasi/media dan sejarah audit tetap. Email tidak dapat digunakan ulang; retry provisioning tidak menciptakan atau mengaktifkan akun kembali. Tidak memerlukan schema/migration baru: pemblokiran dan pencabutan satu-satunya role sudah menggunakan domain existing. Idempotensi DELETE tidak menambah audit/versi selama audit penghapusan masih tersedia; setelah audit habis retensi, retry dapat 409 tanpa write. Pengiriman provider yang sudah berlangsung tidak dapat ditarik kembali, tetapi akun blocked/roleless serta proof revoked/consumed tetap tidak memberi akses.

| Verifikasi | Hasil aktual |
| --- | --- |
| Database | MySQL **8.4.3 / sorerunapp_stage11_test**, target efektif diperiksa; tidak memakai sorerunapp atau sorerunapp_test manual |
| Backend | **28 test / 508 assertion lulus**: provisioning/list existing, tujuh test penghapusan dan tiga test concurrency baru. Tambahan assertion flag eligibility kemudian diuji ulang: **7 test / 154 assertion lulus** |
| Keamanan/domain | Guest/Admin/member/staff, blocked/unverified/stale/izin pelaku dicabut ditolak; CSRF/field tambahan/JSON/limit; self/Super/multi-role/profil/membership protected; rollback audit, email tetap unique, replay provisioning ditolak, proof lama dan sesi terlambat tersimpan ditolak |
| Concurrency | Proses/koneksi MySQL paralel nyata: dua DELETE hanya satu audit/kenaikan versi; DELETE mengalahkan accept dan resend; accept mengalahkan DELETE tetap berakhir blocked/roleless. Barrier mengunci actor/target berurutan untuk membuktikan urutan pemenang |
| Frontend | ESLint, typecheck --incremental false, Pint file PHP terdampak dan git diff --check lulus |
| Browser fixture | **10 test lulus**. Termasuk cancel/fokus awal, error tanpa sukses palsu, sukses/refetch daftar, gap 12 px, layout 320 px dan regresi provisioning/undangan/akses/navigasi/logout |
| Visual | Screenshot dialog error 320 px ditinjau; teks wrap, tombol 44 px, tanpa overflow horizontal |
| Build | Terisolasi .next/stage11-build, API 8130, **24 halaman lulus**; konfigurasi/dependency normal tetap |
| Integrasi nyata | **1 test lulus**, hasil build pada 3130 → Laravel 8130 → MySQL test → queue database → Mailpit → aktivasi/login → editor → hapus melalui browser Super. Akun sintetis hilang dari daftar dan sesi browser Admin lama ditolak 401/403 |

Kegagalan awal test diperbaiki pada fixture: profil sintetis perlu field wajib lengkap; snapshot perbandingan perlu fresh dari database; barrier delete-vs-accept perlu menahan actor **dan** target, bukan actor saja. Tidak melonggarkan kode aplikasi untuk membuat test lulus. Next.js 16.4.2 sempat menghentikan server test dengan advisory keamanan existing; perintah yang sama diulang sesuai mekanisme runtime lalu lulus, tanpa upgrade/menonaktifkan pemeriksaan. Advisory tetap tindak lanjut terpisah sebelum produksi.

PRD §7, ARCHITECTURE §8.4, SYSTEM_DESIGN §11.6, kontrak utama dan backend README diselaraskan. AGENTS dan indeks root tetap memadai. Tidak membuat/menjalankan migration, mengubah .env/dependency, menghapus akun aplikasi atau bootstrap ulang ID 21. Semua DELETE nyata sesi ini hanya akun sintetis test; fixture di-rollback atau dibersihkan sesuai run. Server test dihentikan oleh Playwright; server development dan worker/scheduler pengguna tidak dihentikan. Tidak commit/push/deploy dan tidak mengklaim seluruh suite produksi sudah diuji ulang.

## Perubahan password Admin dan Super Admin — 21 November 2026

Permintaan pemilik menambahkan dua alur yang sebelumnya belum tersedia:

1. **Profil → Keamanan Akun → Ubah Password** untuk password sendiri bagi Admin/Super Admin, termasuk multi-role dengan membership. Password saat ini, password baru/konfirmasi; sesudah commit seluruh sesi dicabut, cache privat dibersihkan dan /login menampilkan notice password berubah.
2. **Daftar Akun Admin → Ubah Password** bagi Super Admin, untuk Admin enabled yang sudah teraktivasi/verified dan tidak memiliki role Super Admin. Password konfirmasi milik **Super Admin pelaku**, bukan password lama Admin sasaran. Tanpa konfirmasi email; hanya sesi sasaran dicabut, sesi pelaku tetap. Tidak mengaktifkan undangan pending, memverifikasi email, membuka blokir, mengganti role atau mengubah membership/nomor/QR/riwayat.

Form mengikuti komponen/password policy existing (minimal 12 karakter, maksimal 72 byte/no null, konfirmasi cocok), RHF/Zod, error inline/fokus, loading, cancel, Retry-After dan pengamanan hasil timeout tanpa pengulangan mutation otomatis. Dialog mobile scrollable dan kontrol 44 px. Nilai password hanya dalam memori form/request; tidak ada storage browser, payload antrean atau log/audit password.

Backend: POST /api/v1/me/password serta POST /api/v1/admin/accounts/{id}/password. FormRequest strict, stateful CSRF, rate limit bersama 5/menit dan 20/jam/actor; Policy terpusat. Action memeriksa password pelaku/hashing di luar lock, kemudian lock user menurut ID dan recheck hash/versi/izin serta eligibility sasaran. Transaksi mengganti hash, auth_version/remember token, mencabut sesi/proof reset/pending email/undangan dan delivery terkait, serta audit auth.password_changed atau admin.password_changed tanpa secret. Tidak mengirim email baru, membuat schema atau merombak broker reset publik. Flag can_change_password pada Resource hanya membantu UX.

Review juga memperbaiki pembersihan cache: sebelumnya semua kunci berawalan auth dipertahankan, termasuk auth/account privat. Sekarang hanya kunci auth/session yang dipertahankan lalu diisi null; informasi akun turut dibersihkan pada password change/logout/pergantian akun. Unit test menambahkan data akun privat untuk menjaga perilaku ini.

| Pemeriksaan | Hasil aktual |
| --- | --- |
| Isolasi | MySQL **8.4.3**, **sorerunapp_stage11_test**, konfigurasi efektif diperiksa; API/front test 8130/3130 dan fixture front 3100/output .next terpisah. Tidak memakai akun ID 21 atau database aplikasi |
| Backend terkait | **33 test / 639 assertion lulus**: provisioning/list/deletion/password dan dua race password. Tambahan kasus multi-role/membership serta status/versi pelaku kemudian diuji ulang: **8 test password / 166 assertion lulus** |
| Concurrency | Dua perubahan password sendiri pada koneksi/proses MySQL paralel: tepat satu commit, request stale ditolak, satu audit dan auth_version +1. DELETE yang menang lock menolak setter password tanpa reaktivasi akun |
| Perilaku penting | Admin/Super dapat ganti sendiri; password lama ditolak/baru diterima; sesi lama termasuk late-save ditolak; sesi Super tetap saat mengganti Admin; CSRF/role/field manipulasi/password invalid/limit; pending/blocked/Super/self target ditolak; rollback audit utuh; reset/pending-email proof dibatalkan; email/verifikasi/role/membership/nomor/QR tidak diubah |
| Unit frontend | **29 test lulus**, termasuk validasi password/konfirmasi/reauth dan pembersihan auth/account privat. Percobaan pertama tsx terhalang uv_os_get_passwd/ENOMEM sandbox Windows; pengulangan dengan izin OS lulus |
| Browser fixture | **13 test Super/Admin lulus**, termasuk own password dua role, setter Super, error/fokus dan 320 px, serta regresi daftar/undangan/delete/navigasi. **52 test regresi lulus**: login/transisi/guard, logout/dialog/recovery/cross-tab, perubahan email, reset password; memakai HTTP fixture, bukan bukti database |
| Static/build | ESLint, typecheck --incremental false, Pint file PHP terdampak dan build terisolasi **24 halaman lulus**. Setelah perbaikan cache, lint/tipe/unit/build diulang dan lulus |
| Visual | Screenshot dialog password pada 320 px ditinjau; teks/field tidak overflow horizontal, konten panjang dapat discroll. Keyboard memfokuskan current password, error/form tetap dapat digunakan |
| Integrasi nyata | **1 test lulus**, termasuk pengulangan pada build final setelah perbaikan cache: browser hasil build → Laravel → MySQL test → queue/Mailpit → aktivasi/login/editor, Super mengganti password Admin tanpa email baru, password lama ditolak, Admin login dan ganti sendiri, Super ganti sendiri/login ulang, lalu penghapusan sintetis dan penolakan sesi lama |

Next.js 16.4.2 sempat menghentikan server regresi untuk advisory existing. Run tersebut dihentikan setelah server tidak tersedia, lalu command sama diulang sesuai mekanisme runtime; 52 test lulus. Tidak upgrade dependency atau mematikan pemeriksaan; advisory tetap tindak lanjut sebelum produksi.

PRD §7, ARCHITECTURE §8.4, SYSTEM_DESIGN §11.6, kontrak API utama dan backend README dilengkapi khusus perilaku ini; AGENTS serta indeks docs/API_CONTRACT.md tetap memadai. Tidak memerlukan/menjalankan migration atau konfigurasi baru. Semua password yang ditetapkan otomatis hanya fixture sintetis test; password akun aplikasi/role/database aplikasi/.env tidak diubah. Tidak commit/push/deploy. Suite di luar cakupan yang tidak dijalankan tidak diklaim lulus.

Status akhir sesi: implementasi dapat dicoba melalui Profil atau daftar Admin pada development existing, tanpa migration baru. Server test 3100/3130/8130 selesai dan ditutup Playwright; fixture database di-rollback/cleanup sesuai run, tidak mengubah akun aplikasi atau menghentikan proses development/worker/scheduler pengguna. Hasil lokal bukan klaim kesiapan produksi.

## Perluasan ubah password sendiri untuk Member — 21 November 2026

Atas permintaan pemilik, **Profil Anggota → Keamanan Akun → Ubah Password** kini tersedia untuk role Member. Menggunakan form/action/endpoint POST /api/v1/me/password yang sama, tanpa duplikasi broker atau migration. Helper changeOwnPassword frontend/backend mengizinkan Member/Admin/Super Admin yang memenuhi syarat akun; izin manage/provision dan pengubahan password akun lain tidak diperluas. Staff tanpa salah satu role tersebut tetap ditolak.

Form awal tertutup, fokus ke password saat ini ketika dibuka; batal membersihkan input. Password baru minimal 12 karakter dengan konfirmasi, policy byte/null, CSRF, rate limit, audit, pencabutan sesi/proof dan cleanup cache tetap. Setelah sukses kembali /login untuk masuk dengan password baru. Kartu keamanan pada Profil Anggota berada sebelum tautan privasi/ketentuan. Membership aktif tidak menjadi syarat untuk merawat password sendiri, tetapi akun tetap harus enabled/verified. Tidak mengubah profil, membership/status, nomor member, QR atau sequence.

Verifikasi aktual sesi ini:

- Koneksi efektif **testing / MySQL 8.4.3 / sorerunapp_stage11_test** diperiksa; tidak memakai database aplikasi atau sorerunapp_test manual.
- **9 test backend / 213 assertion lulus**: termasuk Member/Admin/Super self-change, sesi lama/late-save ditolak, password lama ditolak/baru diterima, validasi/CSRF, dan izin negatif target. Kasus Member baru memakai aktivasi membership/nomor/credential QR nyata pada transaksi test; snapshot profil/membership, digest credential biner dan sequence tetap sesudah perubahan password.
- **24 test browser fixture lulus**, termasuk 11 profil anggota dan 13 Super/Admin. Kasus Member membuktikan buka/batal, input dibersihkan, validasi, error server/fokus, sukses ke login/notice, tanpa mutation biodata atau browser storage. Browser memakai HTTP fixture; bukan klaim alur browser Member → Laravel nyata.
- **29 unit test**, ESLint, typecheck --incremental false, Pint file PHP terkait dan **build terisolasi 24 halaman** lulus. Screenshot form/error Member pada 320 px ditinjau tanpa overflow horizontal. git diff --check lulus.
- Fixture awal diperbaiki dengan prefix nomor test yang eksplisit dan digest serialize untuk hash QR biner; tidak mengubah konfigurasi aplikasi atau melonggarkan implementasi supaya test lulus.

PRD §7, ARCHITECTURE §8.4, SYSTEM_DESIGN §11.6, kontrak API utama dan README backend diselaraskan; status Final, ID existing dan riwayat pengujian sebelumnya dipertahankan. AGENTS serta indeks kontrak root tetap. Tidak menjalankan migration, mengubah .env/dependency/password/role/data akun aplikasi, commit/push/deploy. Test backend di-rollback dan server fixture test ditutup Playwright; proses development pengguna tetap. Integrasi browser Member ke Laravel belum dijalankan pada sesi perluasan ini; backend memakai MySQL nyata dan UI memakai fixture seperti dibedakan di atas.

