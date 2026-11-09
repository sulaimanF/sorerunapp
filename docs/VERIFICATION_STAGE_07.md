# Verifikasi Tahap 07 — Biodata Anggota

Tanggal penyusunan: 24 Oktober 2026. Status: source, panduan, dan paket siap diterapkan untuk verifikasi lokal; bukan pernyataan siap go-live.

## Benar-benar dijalankan pada lingkungan penyusunan

| Pemeriksaan | Hasil |
| --- | --- |
| npm run lint | Lulus, tanpa menonaktifkan aturan lint |
| npm run typecheck | Lulus |
| npm run test | 13 unit test lulus: 10 regresi dan 3 schema/payload profil |
| npm run build | Lulus, Next.js 16.4.2; route /profil berhasil dibangun |
| npm run test:e2e -- tests/browser/profile.spec.ts | 9 skenario profil lulus |
| npm run test:e2e setelah penyempurnaan error summary | 16 test lulus: 7 regresi tahap 06 dan 9 profil, 49,6 detik pada run terakhir |
| Source PHP | 17 file baru/terkait berhasil diparse oleh php-parser 3.2.5 dalam mode bahasa PHP 8.3, subset sintaks yang dipakai oleh source PHP 8.4 ini |
| Script Git Bash | bash -n untuk verify-profiles-stage07.sh lulus |
| XML PHPUnit | Well-formed |
| Postman | Dua file JSON valid; 15 request terdefinisi; 26 snippet JavaScript berhasil dikompilasi dengan Node vm.Script |
| File yang dipertahankan | Hash login-form, globals.css, test browser lama, AGENTS.md, PRD.md, ARCHITECTURE.md, dan SYSTEM_DESIGN.md sama dengan sebelum tahap 07 |

Browser test memakai API fixture sintetis yang mengintersep seluruh trafik API. Tidak ada fallback fixture pada source aplikasi. Test meliputi normalisasi yang ditampilkan UI, nama pada sesi/kartu, validasi dan fokus error, pembacaan ulang hasil setelah koneksi terputus, session expired, pergantian akun saat mutation belum selesai, keyboard, serta reflow 320 px. Test browser tidak membuktikan transaksi database atau pengiriman email nyata.

## Belum dijalankan di lingkungan penyusunan

PHP CLI, Composer, dan MySQL tidak tersedia. Karena itu **php -l, Laravel Pint, PHPUnit, concurrency MySQL, request Postman nyata, serta integrasi Laravel–browser belum dinyatakan lulus dari lingkungan ini**. Parser source PHP dan kompilasi script Postman bukan pengganti runtime tersebut.

Paket menyediakan `backend/phpunit.profiles.xml`, feature test profil, dua skenario race dua proses, worker terisolasi, dan script `bash scripts/verify-profiles-stage07.sh --fix` untuk dijalankan pada Laragon PHP 8.4/MySQL 8.4 dengan database sorerunapp_test. Konfigurasi mencakup regresi schema, model, registrasi, sesi, verifikasi, outbox, serta concurrency dari tahap sebelumnya. Gunakan panduan untuk melakukan pemeriksaan nyata sebelum commit/merge.

Dua skenario race baru: pelengkapan profil bersamaan dengan verifikasi email; serta dua pelengkapan profil verified yang bersamaan. Keduanya memeriksa satu aktivasi, satu credential aktif, satu outbox aktivasi, dan alokasi nomor satu kali. Source test tersebut tersedia; hasil MySQL-nya tetap harus diperoleh di Laragon.

## Ruang lingkup dan file

Tidak ada migration, perubahan dependency produksi, penggantian palet, atau perubahan login. Empat dokumen final tetap utuh. Tidak ada commit, push, merge, atau deploy otomatis. Paket hanya berisi path yang dicantumkan pada STAGE_07_FILES.txt, tanpa environment rahasia, dependency terpasang, hasil build, dump database, atau folder Git.

## Kelanjutan integrasi auth/profil — 6 November 2026

Scope dipilih dari branch `dev`, working tree awal bersih, commit terakhir `66146d0`, serta revisi konfirmasi logout tahap 7. Acuan: PRD FR-03/FR-04, AC-23/AC-26/AC-27; ARCHITECTURE Bagian 7 dan kontrak logout; SYSTEM_DESIGN Bagian 14 dan revisi konfirmasi logout. Tidak melanjutkan fitur event, upload, atau reset password yang masih berada di tahap berikutnya.

Ketidaksesuaian implementasi yang diperbaiki:

- Client sebelumnya menerima sembarang respons HTTP sukses sebagai logout berhasil. Sekarang hanya `204` sesuai kontrak yang menyelesaikan logout; respons lain masuk pemulihan hasil yang belum pasti.
- Kegagalan `Periksa Sesi` sebelumnya melepas state pemulihan. Sekarang panel pemulihan tetap tampil dengan error eksplisit; profil/QR tetap disembunyikan. Selama GET berlangsung, tampil `Memeriksa sesi…` dan tindakan pemulihan tidak tersedia untuk submit ulang. Tidak ada pengulangan POST logout otomatis.
- GET sesi yang memastikan pengguna sudah keluar juga memberi tahu tab lain untuk membersihkan data privat. Sesi yang masih valid dapat dibuka kembali setelah pemeriksaan berhasil.
- Penolakan `EMAIL_NOT_VERIFIED` saat PATCH profil sebelumnya hanya menjadi error form. Sekarang biodata/cache dibersihkan dan navigasi menuju `/login`, sama seperti `401` dan `ACCOUNT_BLOCKED`. Klasifikasi penolakan sesi dipakai bersama oleh query privat, profil, dan pemulihan logout; penolakan eligibility kartu biasa tetap bukan logout.

Perubahan berada di session provider, service auth, session safety boundary, helper error/query, dan penanganan error editor profil. API, schema, aturan bisnis, dependency, serta dokumen final tidak diubah. Test fixture hanya dipakai dalam Playwright.

| Pemeriksaan aktual | Hasil |
| --- | --- |
| `npm.cmd run lint` | Lulus |
| `npm.cmd run typecheck` | Lulus |
| `npm.cmd test` | 12 unit test lulus |
| `npm.cmd run test:e2e` | 42/42 browser test Chromium lulus: auth, kartu, logout, dan profil termasuk regresi baru |
| `npm.cmd run test:e2e -- tests/browser/logout-recovery.spec.ts` | 6/6 lulus setelah penambahan notifikasi lintas tab pada GET sesi yang mengonfirmasi logout |
| `npm.cmd run build` | Build produksi Next.js 16.4.2 lulus |
| `php vendor/bin/phpunit --configuration=phpunit.profiles.xml --filter='BrowserSessionTest\|MemberProfileTest' --display-warnings` | 82 test, 689 assertion lulus; exit code 0 pada MySQL 8.4 `sorerunapp_test` |
| `git diff --check` | Lulus |

Environment: Windows/Laragon, Node 24.22.0, PHP 8.4.12, dependency existing. `npm.cmd` digunakan karena execution policy PowerShell menolak `npm.ps1`. Unit test perlu dijalankan di luar sandbox setelah `tsx` gagal pada `uv_os_get_passwd`; browser test di luar sandbox menyelesaikan teardown server dengan normal. Run awal sandbox menyelesaikan 35 assertion browser existing tetapi proses teardown perlu dihentikan, sehingga bukan hasil run final.

Wrapper `php artisan test` melaporkan 82 test lulus tetapi exit code 1; pengulangan langsung melalui PHPUnit di atas selesai dengan exit code 0. Penyebab exit wrapper belum dipastikan dan tidak diatasi dengan melonggarkan assertion/config. Pesan `Synthetic profile activation failure` berasal dari test rollback yang disengaja, bukan kegagalan assertion. Konfigurasi khusus memaksa database test, session database, cache array, serta email/queue fake; kedua kelas test memakai transaksi rollback. Tidak menjalankan migration, reset database, atau email nyata.

Browser test menggunakan API sintetis, sedangkan feature test menguji API Laravel/MySQL secara terpisah. Integrasi browser langsung ke Laravel, SMTP nyata, Safari/perangkat fisik, serta suite concurrency tidak dijalankan pada kelanjutan ini. Ini menyelesaikan perbaikan integrasi auth/profil dalam scope tersebut, bukan seluruh MVP.
