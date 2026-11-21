# Perubahan email terverifikasi — tahap 09

Tanggal: 11 November 2026. Acuan FR-04, BR-16/BR-17, AC-17; ARCHITECTURE §8.3/11.7/12.2; SYSTEM_DESIGN §11.4. Kebijakan konfirmasi mencabut seluruh sesi dan login ulang dengan email baru disetujui pemilik produk.

**Status: migration Stage 09 diterapkan dan smoke test lulus pada aplikasi development lokal.** Pengujian terisolasi sebelumnya tetap dicatat terpisah di bawah. Penerapan pada database aplikasi development mendapat persetujuan pemilik produk pada sesi lanjutan 11 November 2026. Operasi worker/scheduler lokal berkelanjutan ditambahkan dan diuji pada 14 November 2026, dengan scope akun sintetis; lihat bagian operasional terakhir dan backend/README.md. Tidak commit/push/deploy atau mengirim email kepada pengguna nyata. Hasil ini tidak menyatakan kesiapan produksi atau seluruh FR-04/MVP selesai; foto dan domain admin/event tetap di luar tahap.

## Implementasi

- POST permintaan dan konfirmasi memakai sesi pemilik/CSRF; current password, email ternormalisasi, cooldown/rate limit, hash proof 60 menit.
- Migration additive `2026_11_11_120000_create_email_change_requests_table.php`. Tidak mengubah migration lama.
- Outbox email_change terenkripsi, dispatch setelah commit dan pending sweep existing; alamat tujuan berasal dari pending request. Retry memakai proof awal.
- Konfirmasi atomik: email terverifikasi baru, konsumsi request, auth_version, seluruh sesi, broker proof lama, delivery dan audit. Request password reset mencabut pending email; perubahan versi lain membuatnya stale.
- Profil: email aktif/pending/expired, form konfirmasi password, cooldown server, pemeriksaan status. `/verify-email-change` memakai layout publik untuk petunjuk guest tetapi endpoint tetap privat. Tidak perlu refactor member-shell/guard.
- Sukses membersihkan cache privat, broadcast perubahan sesi lintas tab dan `/login` dengan pemberitahuan nonpersisten. Login tetap langsung `/beranda` tanpa banner sesi aktif/returnTo.
- Cleanup `email-changes:prune` dijadwalkan tiap jam, maksimal 1.000 request dengan expiry lebih dari 30 hari lalu; audit tidak dihapus. Tidak menambah env/config layanan baru.

Kontrak payload authoritative: [API_CONTRACT.md](../backend/docs/API_CONTRACT.md). AGENTS tetap karena aturan existing memadai. README/.env.example tidak diubah karena fitur memakai FRONTEND_URL, database queue/session/cache dan SMTP existing; `.env` aktual tidak disentuh.

## Prasyarat operasional aplikasi — catatan sebelum penerapan

Backend membaca tabel baru pada profil dan reset password; menyalakan versi kode ini tanpa migration dapat menghasilkan error tabel tidak tersedia. Migration pendukung reset 9 November juga diperlukan bila belum diterapkan. Pada sesi pengujian terisolasi, database aplikasi belum diperiksa atau dimigrasi. Pemeriksaan dan penerapan development lokal yang kemudian disetujui dicatat pada bagian terakhir dokumen ini.

Tahap pengujian memakai MySQL 8.4.3 `sorerunapp_test`, terpisah dari database aplikasi. Helper memeriksa nama database aktual melalui SELECT DATABASE(), versi server, environment, serta perbedaan nama dengan konfigurasi aplikasi sebelum migration. Migration additive Stage 09 berhasil diterapkan sebagai batch 3; migration reset 9 November sudah berstatus Ran. Tidak menggunakan migrate:fresh/db:wipe. phpunit.passwords.xml memaksa mysql/test database, DB_URL kosong, koneksi queue mysql, sesi database, cache array dan email array. Tidak menampilkan credential atau mengubah .env.

Pengiriman aplikasi memerlukan FRONTEND_URL origin frontend tepercaya, SMTP, queue database `mail`, serta scheduler existing. Mailer log ditolak worker. Setelah penerapan berizin, worker aplikasi perlu direstart agar mengenali jenis delivery baru. Worker dan server yang dijalankan pada review hanya untuk test terisolasi; scheduler aplikasi tidak dijalankan.

## Matriks acuan regresi

Suite EmailChangeTest, EmailChangeConcurrencyTest dan EmailChangeDeliveryTest terdaftar pada phpunit.passwords.xml. Unit test mencakup parser proof, pembersihan memori/Strict Mode dan perlindungan cache saat identitas berganti selama cancellation. Fixture/parser profil serta whitelist test existing mengikuti response tambahan. Hasil aktual dan batas cakupan dicatat setelah matriks berikut.

Skenario acuan untuk pengembangan regresi selanjutnya:

1. Guest/blocked/unverified/stale ditolak; owner hanya dari sesi; email trim/lowercase, format/panjang, alamat aktif/sudah digunakan, password salah/byte policy, JSON/query/field asing dan CSRF.
2. Receipt 202 tidak mengubah email aktif; pending/expired dapat dibaca setelah reload. Cooldown per akun dan bucket IP/akun/target mengikuti Retry-After, termasuk mengganti target/IP.
3. Tujuan baru mencabut proof/delivery lama. Token salah, tujuan berubah, revoked/consumed/expired/version stale tidak meninggalkan perubahan parsial.
4. Dua proses nyata pada MySQL 8.4 mengonfirmasi proof sama: maksimal satu berhasil. Dua pemilik dengan tujuan sama: maksimal satu pemilik email; unique conflict/rollback tidak menyisakan versi/sesi/audit parsial. Jangan mengganti concurrency dengan pemanggilan serial.
5. Rollback saat save user/consume/audit membatalkan seluruh perubahan. Sesi lain dan row sesi versi lama yang terlambat ditulis ditolak; login email lama gagal, email baru berhasil. Nomor/QR/membership/blokir/riwayat tidak berubah.
6. Reset password mencabut pending request/delivery, perubahan versi lain membuat stale. Konfirmasi email menghapus broker proof lama; tautan verifikasi awal lama tidak memverifikasi alamat lain.
7. Worker mengirim hanya ke pending email; SMTP fake/inbox lokal, payload terenkripsi/terminal cleanup, retry proof tetap, expired/stale/consumed/replaced dibatalkan, queue outage, commit-dispatch gap dan lease recovery. Pengiriman berlangsung di luar transaksi.
8. Browser guest login dengan alamat lama lalu membuka ulang email, akun lain tetap memakai identitasnya, tidak ada returnTo/storage proof, hash dihapus; GET tidak mengonfirmasi. Proof hilang setelah meninggalkan route/reload; Strict Mode/hashchange tidak menggandakan POST.
9. Loading/error jaringan/419/429/pending/expired/sukses; timeout setelah commit tidak diulang otomatis. Cache privat/broadcast dibersihkan; response lama tidak mengubah cache akun baru.
10. Regresi login langsung beranda, verifikasi/resend, lupa/reset, edit biodata, kartu, footer dan dialog logout. Keyboard, label/error/fokus, password manager/paste, kontrol 44 px, reflow 320 px dan zoom. Browser fixture dan browser langsung Laravel/inbox lokal dilaporkan terpisah.
11. Baca environment/lifecycle terlebih dahulu, lalu jalankan PHPUnit konfigurasi terisolasi, Pint --test, lint, typecheck, unit, Playwright dan build sesuai script existing. Tidak ada script format check frontend khusus; jangan mengarang command. Tinjau diff/rahasia sebelum menyatakan siap.

## Hasil aktual review — 11 November 2026

| Pemeriksaan | Hasil |
| --- | --- |
| `php vendor/bin/phpunit --configuration=phpunit.passwords.xml` | Lulus, 286 test dan 2.434 assertion, exit code 0; mencakup 31 test Stage 09 serta regresi tahap sebelumnya |
| Concurrency MySQL 8.4 | Empat test dua proses/koneksi berbeda dengan start barrier lulus: token sama, email sama untuk dua akun, konfirmasi vs request pengganti, konfirmasi vs reset password |
| `php vendor/bin/pint --test` | Lulus; format empat file test baru diperbaiki menggunakan Pint terlebih dahulu |
| `npm.cmd run lint` | Lulus setelah perbaikan callback ref dan pengecualian direktori artefak test saja |
| `npm.cmd run typecheck` | Lulus, tanpa pelonggaran strict/type assertions untuk menyembunyikan kontrak |
| `npm.cmd test` | Lulus, 19 unit test; dijalankan di luar sandbox setelah tsx terhalang uv_os_get_passwd/ENOMEM sebelum assertion |
| `npm.cmd run test:e2e` | Lulus, 68 test Chromium: 10 Stage 09 dan 58 regresi; API fixture sintetis, bukan bukti integrasi Laravel |
| `npm.cmd run test:e2e -- --config=playwright.live.config.ts` | Lulus, 1 alur browser nyata → Laravel → MySQL test → database queue → SMTP Mailpit → tautan dari email → konfirmasi → login memakai alamat baru |
| `SORERUNAPP_TEST_OUTPUT=build npm.cmd run build` | Lulus, Next.js 16.4.2; route `/verify-email-change` termasuk hasil build |
| `git diff --check` | Lulus |
| Migration test | Stage 09 Ran, batch 3 pada sorerunapp_test; database aplikasi tidak diterapkan migration |

Test concurrency memeriksa hasil akhir, versi, konsumsi request dan keunikan email; tidak menggantinya dengan dua pemanggilan serial. Feature test mencakup rollback saat audit gagal, sesi versi lama yang ditulis kembali beserta cookie lama, status suspended/ended, serta snapshot membership/credential. Outbox diuji dengan transport array dan fake, termasuk kegagalan queue/provider, retry proof tetap, dispatch/sweep setelah commit, dan pembatalan delivery expired/revoked/stale/consumed/blocked. Pesan synthetic failure/queue outage pada output suite adalah injeksi kegagalan yang disengaja, bukan assertion gagal.

Perbaikan hasil review:

- Callback submit form mengikuti pola existing agar React tidak menganggap ref dibaca selama render; tidak menonaktifkan aturan lint.
- Refresh profil yang gagal tetap menampilkan data terakhir dan mempertahankan form/input; error tetap terlihat dan tersedia pemeriksaan status ulang.
- Error validasi password dari server mengembalikan fokus ke kontrol terkait setelah fieldset aktif kembali.
- Konfigurasi test menetralkan DB_URL, memaksa koneksi queue/cache/session test, dan memvalidasi database aktual. Konfigurasi aplikasi tidak diubah.
- Next test memakai output `.next/stage09-browser`, `.next/stage09-live` dan `.next/stage09-build` agar tidak memakai lock/output server pengguna pada port 3000. Include TypeScript mengikuti output tersebut. ESLint hanya mengabaikan hasil Playwright yang dapat dihapus saat test berjalan; kode test tetap dilint.
- Locator browser diperbaiki untuk label email pending dan tombol password yang nama aksesibelnya berubah setelah toggle; assertion perilaku tetap dipertahankan.
- Harness SMTP lokal memakai MAIL_URL null, bukan string kosong yang menghapus transport pada parser konfigurasi Laravel. Kegagalan setup awal diketahui dari kode MAIL_SEND_FAILED tanpa membuka exception provider; sesudah diperbaiki pengiriman SMTP dan alur nyata lulus.

## Batas integrasi dan operasi test

Live test menggunakan frontend localhost:3119, Laravel localhost:8109, Mailpit HTTP 127.0.0.1:8029 dan SMTP 127.0.0.1:1029, dengan database/cache/queue/session MySQL test. APP_KEY sintetis khusus test; proof dibaca dari email Mailpit, bukan dari database. Fixture user dibersihkan berdasarkan run ID sintetis. Mailpit memakai file khusus di direktori .next yang diabaikan Git; tidak ada relay/pengiriman ke provider eksternal. Jangan membuka port test tersebut ke jaringan publik.

Mailpit executable dipilih melalui MAILPIT_EXECUTABLE_PATH hanya pada proses test. Helper PHP menolak nama database yang sama dengan aplikasi dan memaksa endpoint SMTP loopback. Middleware autentikasi, CSRF dan eligibility tetap aktif. Guard lingkungan live juga diperiksa kembali lewat helper CLI setelah koneksi queue/cache diperketat.

Sandbox Windows membatasi informasi pengguna/proses dan teardown child server Playwright. Unit test diulang melalui izin eksekusi di luar sandbox. Setelah assertion browser selesai, hanya PID server test yang sudah dicocokkan dengan port/log peluncuran yang dihentikan; server pengguna port 3000 tidak dihentikan. Seluruh run akhir selesai dengan exit code 0. Kegagalan run awal tidak dihitung sebagai hasil lulus.

Screenshot 320 px ditinjau: panel pending/expired, form, tombol/fokus dan teks tidak overflow; keyboard dan kontrol minimal 44 px diuji. Belum melakukan audit pembaca layar/perangkat fisik, browser selain Chromium, zoom native, SMTP/provider produksi, scheduler produksi atau rollout pada database aplikasi. Modul attendance/riwayat belum tersedia di repository, sehingga tidak mengklaim regresi riwayat nyata diuji. Tidak ada perubahan ke domain tersebut. Retensi cleanup belum diuji lewat scheduler berjalan; sweep outbox diuji melalui command pada database test.

Pada akhir sesi review terisolasi, langkah penerapan aplikasi masih menunggu persetujuan: verifikasi target/backup, migration, konfigurasi SMTP/FRONTEND_URL dan cookie, proses mail/scheduler, lalu smoke test memakai akun sintetis/inbox berizin. Sesi development lokal berikutnya dicatat di bawah; hasil terisolasi di atas tidak diklaim sebagai hasil database aplikasi.

## Penerapan dan smoke test development lokal — 11 November 2026

### Target dan perlindungan data

- Konfigurasi efektif: `APP_ENV=local`, koneksi mysql, host `127.0.0.1:3306`, database aktual `sorerunapp`, server MySQL 8.4.3. Database ini berbeda dari `sorerunapp_test`; tidak memakai helper live test yang memaksa database test.
- URL aplikasi/API `http://localhost:8000`, frontend/FRONTEND_URL `http://localhost:3000`. Origin CORS eksplisit, credentials aktif; Sanctum memuat localhost:3000/8000. Session/cache/queue database, cookie host-only, SameSite lax, secure false untuk HTTP lokal. Tidak ada perubahan `.env`, APP_KEY, origin, atau cookie.
- SMTP efektif adalah `127.0.0.1:1025`, mailer smtp, MAIL_URL null. Inbox Mailpit existing `http://127.0.0.1:8025`; proses teridentifikasi sebagai Mailpit Laragon, tanpa flag relay/forward. Semua penerima smoke memakai domain sintetis `example.test`.
- Backup seluruh database dibuat menggunakan mysqldump single transaction, kemudian dienkripsi dengan Laravel Crypt sebelum ditulis. Tidak menyimpan dump SQL plaintext di workspace. Lokasi lokal yang diabaikan Git: `backend/storage/app/private/stage09-local/sorerunapp_before_stage09_20261111_142653_ed9c41c9.sql.enc`.
- Backup tersebut didekripsi dalam memori dan benar-benar dipulihkan ke database terpisah `sorerunapp_stage09_restore_20261111_142653_ed9c41c9`. Jumlah dan digest isi **19 tabel** cocok dengan sumber; sumber tetap sama selama pembuktian. Database pemulihan dipertahankan lokal, tidak dipakai aplikasi, worker, atau smoke test. Manifest dan hasil pemeriksaan ada di direktori private yang sama, tanpa credential/token mentah.
- APP_KEY existing tetap diperlukan untuk membuka backup terenkripsi. Simpan kunci secara aman dan terpisah sesuai ARCHITECTURE §15; jangan memasukkannya ke Git. Bukti ini memverifikasi pemulihan database lokal sebelum migration, bukan RPO/RTO atau disaster recovery produksi. File privat tidak diubah oleh tahap ini.

### Migration dan konfigurasi pengujian

`2026_11_09_120000_support_password_reset_delivery` sudah Ran pada **batch 4**; tidak dijalankan ulang. Satu-satunya migration pending adalah `2026_11_11_120000_create_email_change_requests_table`. Migration ini diterapkan dengan path eksplisit pada database `sorerunapp` sebagai **batch 5**. Tidak menjalankan migrate:fresh, db:wipe, truncate, seeder, atau migration lain.

Sesudah migration, tabel email_change_requests kosong, primary key serta indeks `(user_id, created_at)`/`expires_at` tersedia, FK user_id mengacu ke users. Kolom pendukung reset dan users.email unique constraint existing tetap tersedia. Seluruh tabel existing selain catatan migration mempunyai jumlah dan digest isi yang sama dengan backup sebelum migration.

Review diff ESLint/TypeScript/Next/Playwright tidak menemukan pelemahan pemeriksaan: source aplikasi/test tetap tercakup, strict tetap aktif, pengecualian ESLint hanya artefak, dan distDir default development tetap `.next`. Output browser/live/build terisolasi hanya dipilih lewat env test. Tidak mengubah konfigurasi tersebut atau menjalankan ulang lint/typecheck/build/suite penuh karena tidak ada perubahan source/config baru pada sesi ini. `git diff --check` dijalankan dan lulus.

### Proses dan isolasi pengiriman

Frontend development localhost:3000, API existing localhost:8000 (listener IPv6 loopback), dan Mailpit existing dipertahankan. Server IPv4 127.0.0.1:8000 sementara yang sempat dimulai dihentikan setelah ditemukan server API existing; health localhost:8000 tetap 200 setelah penghentian. Tidak me-restart atau menghentikan proses pengguna.

Untuk setiap pengiriman smoke, helper lokal memeriksa environment/database/SMTP/frontend, mencari job DeliverOutboxNotification yang delivery_id-nya hanya milik akun sintetis dan jenis yang diminta, lalu memindahkannya ke queue `stage09-smoke-<run>`. Worker database dijalankan hanya pada queue itu dengan stop-when-empty dan max-jobs sesuai jumlah job terpilih. Payload antrean tetap berisi ID delivery, bukan proof mentah. Token diambil oleh browser dari email Mailpit, bukan dari database atau fixture. Payload proof terenkripsi pada outbox dibersihkan setelah pengiriman.

`schedule:list` memastikan registrasi sweep outbox setiap menit serta clear-resets/prune setiap jam. Scheduler dan worker queue mail umum **tidak dijalankan terus-menerus**, agar tidak memproses delivery/job akun lain. Seluruh worker sintetis sudah selesai; jobs tersisa **0**. Sebanyak 19 delivery existing tetap berstatus sent dan tidak diproses ulang. Pengiriman otomatis untuk permintaan development berikutnya masih memerlukan pengoperasian worker/scheduler dengan cakupan yang dikendalikan; jangan menganggap keduanya sudah aktif.

Helper operasional sementara berada di `backend/storage/app/private/stage09-local/operations.php`, skrip browser di `frontend/.next/stage09-development-smoke.cjs`. Keduanya artefak lokal di luar Git, bukan endpoint aplikasi atau pengganti suite test terisolasi. Password sintetis hanya dipakai dalam memori; salinan terenkripsi sementara untuk melanjutkan run sudah dihapus. Tidak menyimpan password/proof mentah pada laporan, log, payload queue, atau browser storage.

### Hasil smoke pada aplikasi nyata

Browser Chromium memakai frontend normal localhost:3000 → Laravel localhost:8000 → MySQL **aplikasi development** → database queue terisolasi → SMTP/inbox Mailpit. Tidak memasang route API fixture atau menonaktifkan CSRF, middleware sesi, atau otorisasi.

| Skenario | Hasil aktual |
| --- | --- |
| Login dan buka Profil | Lulus; login langsung ke /beranda, tanpa banner sesi aktif/returnTo; Profil dan endpoint kartu tersedia |
| Ajukan perubahan dengan password akun | Lulus; POST 202, receipt generik pemrosesan, pending dapat dibaca dari API/Profil |
| Email lama selama pending | Lulus; email aktif tetap lama dan browser context kedua dapat login memakai alamat lama; minimal dua sesi tercatat |
| Email dan konfirmasi | Lulus; email diterima pada alamat pending di Mailpit; GET tidak mengganti email, POST eksplisit 200 menggantinya dan menandai verified |
| Seluruh sesi dicabut | Lulus; auth_version 1 → 2, sesi akun menjadi 0 tepat setelah konfirmasi; sesi context kedua menerima 401 dan guard menuju /login |
| Login memakai alamat baru | Lulus dengan password semula; alamat lama menerima INVALID_CREDENTIALS 401. Cache privat/storage tidak mempertahankan proof; login normal langsung /beranda |
| Nomor/QR/membership | Lulus; nomor member dan digest QR API identik; record membership/credential dan status akun tidak berubah |
| Tautan sudah digunakan | Lulus; konfirmasi ulang menerima EMAIL_CHANGE_INVALID 422, versi/email tetap, form konfirmasi tidak dapat dipakai lagi |
| Profil dan lupa/reset password | Lulus; Profil memakai email baru. Forgot 202 → email Mailpit → reset POST 200 → versi 2 → 3 dan sesi 0; password lama ditolak, password baru dapat login dan membuka Profil |
| Mobile | Lebar 320 px tidak overflow horizontal; screenshot panel pending/form diperiksa. Tidak mengulang audit aksesibilitas/perangkat fisik penuh |
| Data anggota existing | Sembilan users/profil/membership/role, 18 policy acknowledgements dan tujuh credential existing cocok dengan digest backup sebelum migration setelah tiga akun sintetis dikecualikan |

Run awal terhenti karena filter subjek skrip berbeda dari subjek notification. Run kedua terhenti karena skrip mengharapkan 422 untuk login salah, padahal kontrak existing menggunakan 401. Pada run utama, selector exact label reset tidak cocok dengan teks label bertanda wajib; regresi reset dilanjutkan menggunakan akun yang sama setelah memakai locator prefix sesuai test existing. Ekspektasi tautan bekas juga diselaraskan dengan kontrak EMAIL_CHANGE_INVALID 422. Perbaikan hanya pada skrip smoke lokal; tidak mengubah perilaku aplikasi atau melonggarkan assertion keamanan.

Run utama `26341db31cacc99c5bc0377485fe87ae` menyelesaikan enam kelompok pemeriksaan perubahan email; run lanjutan pada akun yang sama menyelesaikan regresi reset dan berakhir exit code 0. Tujuh kelompok assertion tersebut mencakup sembilan skenario smoke yang diminta. Hasil gabungan `success: true` dan screenshot tersimpan di `frontend/.next/stage09-development-26341db31cacc99c5bc0377485fe87ae.json` serta `...-pending-320.png`. Kegagalan skrip awal tidak dicatat sebagai run lulus.

### Data sintetis yang dipertahankan

Nama akun: **Anggota Sintetis Stage 09**. Pola email lama `stage09-dev-<run>@example.test`; alamat baru `stage09-dev-new-<run>@example.test`.

| User ID | Run | Nomor member | Kondisi akhir |
| --- | --- | --- | --- |
| 10 | f0b6a64520a3b7a0b0b265c7c02d308d | SRC-000008 | Alamat lama masih aktif; permintaan email pending belum dikonfirmasi dan tunduk expiry 60 menit |
| 11 | c0ad2a137fc3e18af788623a16b27367 | SRC-000009 | Alamat baru terverifikasi, auth_version 2, seluruh sesi dicabut saat konfirmasi |
| 12 | 26341db31cacc99c5bc0377485fe87ae | SRC-000010 | Alamat baru terverifikasi, auth_version 3 setelah reset; satu sesi baru tercatat dari login akhir; membership active/akun enabled |

Alamat aktif akun smoke utama: `stage09-dev-new-26341db31cacc99c5bc0377485fe87ae@example.test`. Password tidak dicatat; gunakan reset ke inbox lokal bila perlu mengakses ulang. Browser otomasi sudah ditutup. Tidak membersihkan akun sintetis secara massal atau mengembalikan sequence. Nomor 8–10 telah diterbitkan; next_value **11**. Ledger tanpa biodata disimpan terpisah dari dump di `backend/storage/app/private/stage09-local/member-number-high-water.json`.

### Batas dan pemulihan

Stage 09 sudah diterapkan dan alur pengguna dapat dipakai untuk pengujian development lokal dengan pengiriman terkontrol seperti di atas. Tidak ada blocker schema atau kegagalan aplikasi yang ditemukan pada smoke akhir. Pengoperasian worker/scheduler kontinu tetap pekerjaan operasional tersisa; tidak ada klaim kesiapan staging/produksi.

Concurrency, rollback/injeksi kegagalan dan suite regresi lengkap memakai hasil MySQL test terisolasi sebelumnya; tidak dijalankan ulang pada database aplikasi. Smoke lokal tidak menggantikannya. Tidak melakukan uji beban, provider email eksternal, scheduler retensi berjalan, browser selain Chromium, atau restore terhadap database aplikasi yang aktif.

Jika perlu pemulihan setelah sesi ini, jangan langsung menimpa database aplikasi dengan backup lama: hentikan penulisan/masuk maintenance, pertahankan data baru dan ledger nomor, amankan kunci enkripsi serta file privat, dan ikuti ARCHITECTURE §15 untuk marker, sesi, constraint, serta high-water mark. Database bukti restore di atas hanya untuk inspeksi pemulihan; jangan arahkan aplikasi/worker kepadanya. Tidak ada rollback atau penurunan sequence yang dijalankan pada sesi ini.

## Pembaruan tampilan Profil — 14 November 2026

Sesuai permintaan pemilik produk, form perubahan email awalnya tertutup. Tombol **Ubah Email** membukanya dan memindahkan fokus ke Email baru. Tombol **Batal Ubah Email** menutup form serta membersihkan input/error; pembatalan tidak mencabut request pending atau mereset cooldown. Email aktif, panel pending/expired, receipt penerimaan, dan tombol Periksa Status Email tetap dapat dilihat saat form tertutup. Tombol pembatalan dinonaktifkan selama pengiriman agar hasil mutation tidak disembunyikan.

Perubahan terbatas pada komponen email-change-form, skenario browser terkait, penyesuaian langkah membuka form pada test integrasi existing, serta SYSTEM_DESIGN §11.4. PRD, arsitektur, kontrak API, backend, schema dan konfigurasi lokal tidak membutuhkan perubahan. Perubahan pengguna sebelumnya tetap dipertahankan.

Verifikasi pembaruan ini: `npm.cmd run lint` dan `npm.cmd run typecheck` lulus; `npm.cmd run test:e2e -- tests/browser/email-change.spec.ts` lulus **11 test**, exit code 0, pada Chromium dengan API fixture. Cakupan meliputi pembukaan keyboard, pembatalan/input kosong, pending/cooldown saat ditutup/dibuka kembali, loading/validasi/error, konfirmasi dan tampilan 320 px. Screenshot diperiksa dan git diff --check lulus. Teardown Windows memerlukan penghentian pohon proses Next test port 3100 yang sudah dicocokkan dengan PID/command line setelah seluruh assertion selesai; proses development pengguna tidak dihentikan. Test integrasi Laravel/MySQL/email tidak dijalankan ulang untuk perubahan tampilan ini; hasil aplikasi development pada 11 November di atas merupakan hasil historis sebelum pembaruan UI ini. Tidak mengakses atau mengubah database aplikasi pada sesi pembaruan tampilan.

### Loading dan popup pemeriksaan status — pembaruan lanjutan 14 November 2026

Tombol Periksa Status Email sekarang menampilkan spinner/“Memeriksa status email…” dan mencegah klik ganda selama GET profil berlangsung. Setelah respons terbaru tervalidasi, dialog Status Email menampilkan email aktif, email pending/kedaluwarsa atau tidak ada permintaan, serta cooldown dari server yang dihitung mundur. Tutup/Escape mengembalikan fokus ke tombol pemeriksaan. Hasil tetap melalui query profil existing, memperbarui cache untuk pemilik yang sama dan mempertahankan input yang belum disimpan; respons terlambat setelah unmount/pergantian akun tidak membuka popup. Kegagalan atau data status tidak tersedia menampilkan error tanpa membuka dialog dari cache lama.

Implementasi UI dipisahkan ke email-change-status-dialog.tsx, memakai Radix Dialog, spinner dan styling existing. Endpoint, backend/database dan aturan perubahan email tetap sama. SYSTEM_DESIGN §11.4 dilengkapi sesuai permintaan pemilik produk. Tiga skenario browser tambahan disiapkan untuk popup tanpa pending/pending/kedaluwarsa, loading, data terbaru, cooldown, keyboard/fokus, 320 px dan input tetap tersimpan; skenario error existing ditambah assertion bahwa popup tidak dibuka.

**Pembaruan loading/popup ini belum diverifikasi melalui eksekusi.** Mengikuti instruksi tidak menjalankan test dari percakapan sebelumnya, sesi ini hanya meninjau kode/diff secara statis; tidak menjalankan test, lint, typecheck, build, aplikasi, worker, atau operasi database. Hasil 11 test pada paragraf sebelumnya berlaku untuk versi sebelum penambahan popup ini.

## Operasi worker, scheduler dan email lokal — 14 November 2026

Panduan harian authoritative: **[backend/README.md](../backend/README.md)**, dengan urutan terminal pada [README root](../README.md). Launcher `composer dev` sekarang mengelola worker database/mail-local dan tick scheduler Laravel tiap menit; source berada pada app/Console/Commands/RunLocalBackground.php, bukan cache/storage sementara. Tidak membuka server/database/inbox kedua, memasang service OS, mengubah .env aktual atau menjalankan migration/seeder.

Temuan awal: Git sudah clean pada dev commit 35b8169; implementasi Stage 09 dan perubahan pengguna sudah committed. Runtime efektif APP_ENV local, MySQL 8.4.3/database sorerunapp pada loopback, SMTP Mailpit 127.0.0.1:1025, frontend localhost:3000/API localhost:8000. Tidak ada worker/scheduler project yang berjalan; jobs dan failed_jobs 0, seluruh delivery existing sent/cancelled. Composer dev scaffold mendengarkan default queue, bukan queue mail yang dipilih producer, dan belum menyertakan scheduler; README masih template Laravel.

Perbaikan: producer after-commit memilih mail-local untuk akun @example.test (pending email juga wajib @example.test); sweep/worker mengikuti scope yang sama. Pekerjaan queue mail/queue lain dan data penerima existing tidak dipindahkan/dihapus/diproses. Guard launcher memeriksa environment, database/koneksi aktual, queue/cache, SMTP, origin serta jenis job/penerima. Mutex mencegah instance ganda. Kontrol status/stop/restart/pause/resume worker tersedia melalui Composer. Worker satu job per child memuat ulang kode tiap job; scheduler menjalankan schedule:run sekali per menit dan mencatat task nyata. Cleanup global tidak didaftarkan pada local agar token/request existing tidak terhapus; expiry tetap berlaku dan jadwal nonlocal tetap sama. ARCHITECTURE §14.1 mencatat detail lokal ini. PRD/SYSTEM_DESIGN/AGENTS/API tidak membutuhkan perubahan.

Review delivery mempertahankan lease 180 detik, backoff 60/300/900/3600, maksimum lima attempt, enqueue setelah commit dan SMTP di luar row lock. Job hanya membawa delivery ID; proof tetap terenkripsi di outbox dan tidak diterbitkan ulang saat retry. Log baru hanya ID/attempt/kode teknis serta jumlah sweep. Provider failure yang ditangani menghasilkan failed pada outbox, bukan otomatis failed_jobs; pending sweep menangani pemulihannya.

### Hasil aktual

| Pemeriksaan | Hasil |
| --- | --- |
| Preflight aplikasi dan test | sorerunapp dan sorerunapp_test terpisah pada MySQL 8.4.3; schema sudah Ran, tidak ada migration dijalankan |
| Suite outbox/delivery/local terpilih | 44 test, 220 assertion lulus, exit 0; termasuk kegagalan provider/queue, retry, lease, rollback, proof stale/expired/revoked/consumed dan guard lokal |
| Pemeriksaan lokal terakhir | LocalBackgroundTest lulus 10 test, 29 assertion, exit 0; mencakup antrean mail-local, SQL scope pending, pengiriman fake menuju alamat pending sintetis dan exception job pada koneksi database queue nyata |
| Pint --test pada delapan file PHP terkait | Lulus; format diperbaiki terlebih dahulu pada file baru/terkait |
| Composer validate --no-check-publish | Lulus; tidak mengubah dependency/lockfile |
| Lint frontend, typecheck dan lint script smoke terakhir | Lulus; perubahan frontend sesi ini hanya script operasional pengujian |
| Browser nyata pada database aplikasi | Run 8f418500a30a07231aa314fafefa14e3 lulus, exit 0, tanpa fixture API; registrasi browser → after-commit → worker berjalan → Mailpit tanpa command pengiriman per request |
| Tautan autentikasi | Tautan dari inbox lokal dikonfirmasi melalui browser, akun terverifikasi/aktif dan login langsung beranda |
| Worker berhenti/dijalankan kembali | Pause menghentikan child worker; request browser baru pending dan mempunyai job tetapi belum masuk inbox; resume mengirim otomatis |
| Scheduler terpisah dari enqueue normal | Helper hanya untuk akun sintetis baru melewatkan callback dispatch pada prosesnya sendiri; pending tanpa job teramati. Tick scheduler berikutnya menjalankan task, menjadwalkan delivery dan worker mengirim email. Data existing/timestamp tidak dimanipulasi |
| Restart/stop/start | Restart generation berubah dan worker aktif; stop seluruh launcher selesai exit 0, running false/jobs 0, lalu composer dev dijalankan kembali. Instance kedua ditolak tanpa membuat child tambahan |
| Data existing | Digest users/profil/membership/role/persetujuan/outbox/token/request/credential existing cocok dengan baseline sebelum smoke; pemeriksaan baseline 12 user awal juga tetap cocok |
| git diff --check | Lulus |

Verifikasi scheduler memakai log task outbox yang benar-benar dieksekusi, jumlah queued, dan penerimaan email untuk delivery tanpa producer job; tidak disimpulkan hanya dari PID scheduler hidup. Injeksi provider failure/retry/lease dilakukan di sorerunapp_test dengan fake email; tidak mematikan Mailpit atau mengubah layanan pengguna untuk memaksa kegagalan. Test failed_jobs memakai queue unik pada database test nyata dan exception teknis sintetis sebelum klaim delivery: job masuk failed_jobs, outbox tetap pending/attempt 0 untuk pemulihan. Hanya row queue/failed_jobs milik test tersebut dibersihkan, bukan antrean aplikasi. Output SYNTHETIC_WORKER_FAILURE adalah injeksi yang diharapkan; assertion lulus.

Run awal skrip berhenti karena ekspektasi status registrasi 202, sementara kontrak aktual adalah 201; ekspektasi skrip diperbaiki. Run berikutnya membuktikan pengiriman/recovery tetapi menemukan restart child scheduler Windows yang macet. Implementasi akhir menghindari scheduler daemon yang perlu dihentikan paksa: child worker/tick selesai sendiri; output pipe sementara Windows tidak digunakan. Run terakhir dan stop/start penuh berhasil. Saat memperbaiki run awal, hanya pohon PID launcher uji yang sudah dicocokkan dihentikan; Mailpit/API/frontend tidak ikut dihentikan.

### Data sintetis dan proses akhir

Semua akun berikut bernama Anggota Uji Background dan dipertahankan tanpa cleanup massal. Pola email `background-<run>-<suffix>@example.test`; password acak tidak dicatat.

| Run | User ID dan suffix | Nomor member |
| --- | --- | --- |
| 972fe0f0f51e71bd218eaa560fab7c14 | 13 normal; run awal skrip | Belum aktif |
| 5a876821062b2955e3d2ce20fd19993e | 14 normal, 15 paused, 16 gap; run diagnosis restart | ID 14 SRC-000011; lainnya belum aktif |
| 8f418500a30a07231aa314fafefa14e3 | 17 normal, 18 paused, 19 gap; run akhir lulus | ID 17 SRC-000012; lainnya belum aktif |

Hasil akhir/manifest baseline: backend/storage/app/private/local-background-smoke/8f418500a30a07231aa314fafefa14e3-result.json. Empat delivery run akhir (37–40) sent dengan satu attempt, jobs 0, failed_jobs 0; next_value nomor member 13. Tidak mengembalikan sequence atau memakai ulang nomor yang diterbitkan.

Pada pemeriksaan akhir launcher baru berjalan (supervisor PID 7216; worker PID berubah tiap job/polling, scheduler_pid null di antara tick), frontend localhost:3000 PID 14028, API localhost:8000 PID 7492, Mailpit existing PID 4028. Child launcher uji lama telah dihentikan. Status dan PID ini hanya snapshot sesi, bukan startup otomatis OS. Jika terminal/proses tool ditutup, jalankan kembali composer dev pada PowerShell sendiri; prosedur launcher telah diuji. Tidak ada jaminan proses hidup setelah komputer/terminal/sesi tool berakhir.

Pengoperasian development untuk akun sintetis siap dipakai. Penerima selain @example.test tetap tidak diproses launcher, cleanup global lokal tidak aktif, dan tidak ada klaim kesiapan produksi. Tidak melakukan deploy/commit/push atau memulai tahap fitur berikutnya. Suite penuh/concurrency tidak diulang karena transaksi domain tidak berubah. Hasil browser ini menguji registrasi/verifikasi/queue, bukan audit ulang UI popup pemeriksaan email; catatan UI sebelumnya tetap berlaku.
