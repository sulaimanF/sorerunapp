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
