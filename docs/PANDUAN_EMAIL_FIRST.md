# Panduan revisi: verifikasi email sebelum login

Paket unduhan disiapkan ulang pada 25 Oktober 2026. Aturan bisnis revisi 24 Oktober 2026 tetap sama: PRD 1.1, ARCHITECTURE 1.1, AGENTS 1.1, dan SYSTEM_DESIGN 1.2 tetap final. Palet Sunset tidak berubah.

Alur: **Registrasi → buka email → klik Verifikasi Email → login → beranda/kartu.** Email belum terverifikasi tidak dapat membuat sesi login. Verifikasi publik tidak membuat login otomatis. Membuka tautan melalui GET saja tidak mengaktifkan akun.

## 1. Terapkan paket

1. Hentikan frontend, API, serta worker lokal. Gunakan branch dev dan simpan perubahan lokal Anda terlebih dahulu.
2. Ekstrak isi ZIP ke root `C:\laragon\www\sorerunapp` mengikuti folder yang tersedia. Paket berisi file perubahan saja. Jangan menghapus folder proyek. Tidak membawa .env, node_modules, vendor, database, globals.css, atau seluruh folder profil tahap07.
3. Dari Git Bash jalankan:

```bash
cd /c/laragon/www/sorerunapp
node scripts/apply-email-first.cjs
```

Installer memindahkan route resend ke publik serta mengganti callback limiter tanpa mengganti seluruh routes/api.php atau AppServiceProvider.php. Route/policy profil tahap07 dipertahankan. Action/policy profil yang tersedia ditambah guard email verified; helper fixture positif yang dikenali diselaraskan. Semua anchor shared diperiksa sebelum penulisan. Bila bentuk source berbeda, installer berhenti dengan nama file terkait; jangan menimpa file itu dengan versi tahap lama. Backup source tersedia di `.email-first-backup/`, diabaikan Git bila root .gitignore tersedia.

Tidak perlu migration baru, menghapus data, menjalankan migrate:fresh, mengulang registrasi existing, atau memperbarui dependency. Akun verified existing tetap dapat login. Pending unverified menggunakan link email yang masih valid atau halaman kirim ulang. GET API signed URL lama kini readiness saja; konfirmasi dilakukan dengan POST.

## 2. Periksa backend

```bash
cd /c/laragon/www/sorerunapp/backend
php artisan optimize:clear
composer dump-autoload
bash scripts/verify-email-first.sh --fix
php artisan route:list --path=email-verification
```

Script menggunakan PHP 8.4/MySQL 8.4 dan **sorerunapp_test** terisolasi sesuai konfigurasi test existing, bukan database development. Siapkan migration/seed test sesuai panduan sebelumnya jika belum tersedia. Jangan mengarahkan database test ke database berisi data pengguna. Tidak ada migration baru pada revisi ini.

Route harus mencakup GET readiness dan POST confirm pada path signed yang sama, serta POST resend publik. Login unverified menghasilkan 403 EMAIL_NOT_VERIFIED. Sesi lama unverified dicabut pada pemeriksaan privat berikutnya; konfirmasi proof juga menghentikan sesi lama unverified sebelum memverifikasi agar tidak berubah menjadi login otomatis.

Log synthetic delivery failure pada pengujian rollback dapat muncul; hasil akhir suite tetap harus OK. Jika tahap07 terpasang, script juga menjalankan test profil. Salinan tahap07 lokal Anda belum tersedia di lingkungan penyusunan, sehingga integrasi profil dan helper yang dipatch harus diperiksa di Laragon.

## 3. Periksa frontend

```bash
cd /c/laragon/www/sorerunapp/frontend
npm run lint
npm run typecheck
npm run test
npm run build
npm run test:e2e
```

Chromium Playwright yang sudah terpasang dapat digunakan kembali. Test browser memakai API fixture sintetis; tetap lakukan uji Postman/browser nyata ke Laravel di bawah.

## 4. Jalankan aplikasi dan email lokal

Gunakan .env yang telah tersedia, API localhost:8000 dan frontend localhost:3000, serta Sanctum/CORS sesuai panduan sebelumnya. Jangan mencampur localhost dan 127.0.0.1. Gunakan Mailpit/inbox uji, bukan alamat anggota nyata.

Terminal API:

```bash
cd /c/laragon/www/sorerunapp/backend
php artisan serve --host=localhost --port=8000
```

Jalankan kembali worker queue dan scheduler/outbox sweep sesuai panduan tahap05/06 agar delivery pending diproses. Restart worker diperlukan agar kode email baru dimuat. Konfigurasi SMTP, queue, sweep, dan kebijakan tetap.

Terminal frontend:

```bash
cd /c/laragon/www/sorerunapp/frontend
npm run dev
```

## 5. Uji lewat Postman

Import `postman/sorerunapp-email-first.postman_collection.json` dan `postman/sorerunapp-email-first.postman_environment.json`. Pilih environment tersebut. Sebelum langkah01, buka Cookies di Postman dan hapus cookie localhost dari uji sebelumnya agar mulai sebagai tamu. Cookie jar harus aktif; sinkronisasi cookie browser tidak diperlukan. Email fixture baru dibuat pada langkah01. Password bawaan khusus pengujian; jangan memakai akun nyata.

Jalankan berurutan dengan jeda membaca email sebelum langkah05:

| Langkah | Hasil yang diharapkan |
| --- | --- |
| 01 CSRF | 204; email fixture dibuat pada environment |
| 02 Register | 201 registration_received |
| 03 Login sebelum verifikasi | 403 EMAIL_NOT_VERIFIED |
| 04 Me | 401, belum login |
| 05 GET readiness | 200 verification_link_ready, belum mengaktifkan akun |
| 06 Login setelah GET | Tetap 403 EMAIL_NOT_VERIFIED |
| 07 POST konfirmasi | 200 email_verified; data hanya berisi result |
| 08 Me setelah verifikasi | 401 karena tidak auto-login |
| 09 Login setelah verifikasi | 200, email_verified true |
| 10 Kartu | 200, QR privat tersedia |
| 11 Konfirmasi ulang | 200 already_verified; nomor dan QR tidak diterbitkan ulang |
| 12 Logout | 204 |
| 13 CSRF tamu baru | 204 |
| 14 Resend alamat tidak dikenal | 202 generik, Retry-After 60 |
| 15 Resend email sudah verified | 202 dengan data dan Retry-After sama; tanpa email verifikasi baru |

Sebelum langkah05, buka Mailpit untuk email fixture yang tercantum pada environment `email`. Salin URL dari tombol email ke environment `email_link`. Jika URL berbentuk `http://localhost:3000/verify-email#verification=…`, boleh paste URL lengkap; script langkah05 akan decode satu kali menjadi `verification_url`. URL API langsung juga diterima. Jangan paste signed URL ke percakapan, situs publik, atau commit environment yang sudah diisi.

Cooldown penerima tetap menghasilkan 202 generik. Batas request menghasilkan 429 + Retry-After. Input invalid menghasilkan 422. Pada salinan request, hilangkan script/header X-XSRF-TOKEN untuk menguji 419. Ubah satu karakter signature untuk menguji 403 tanpa mutasi akun. Expiry/stale email/auth_version dan rollback diuji feature test yang disertakan.

Email, signed URL, password, dan cookie adalah nilai lokal pengujian. File environment bawaan mengosongkan email_link/verification_url. Jangan export current values untuk commit. Collection menggunakan password sintetis Test-only-password-123, bukan password produksi.

## 6. Uji browser nyata

Daftar email baru → periksa email → coba login (ditolak) → buka tautan dan klik Verifikasi Email → hasil menawarkan Masuk ke Akun → login → beranda/kartu. Uji kirim ulang tanpa login dan tautan yang sama dua kali. Akun dengan profil incomplete dapat memverifikasi email lalu login untuk melengkapi profil; QR menunggu membership aktif.

Banner sesi aktif/manual lanjut pada login tetap sesuai versi awal yang Anda pilih. Countdown hanya untuk rate limit, tidak menambahkan jeda sukses baru. Warna global tidak diganti. Sesi lama unverified ditolak/dihapus pada request privat berikutnya dan cache privat dibersihkan. Membership suspended/ended tidak diaktifkan ulang oleh verifikasi otomatis.

## 7. Simpan histori di dev

Periksa file sebelum commit. Pastikan `.email-first-backup/` diabaikan Git. Jangan stage backup, .env, cookie jar, signed URL, atau environment Postman berisi nilai sensitif.

```bash
cd /c/laragon/www/sorerunapp
git status --short
git add AGENTS.md PRD.md ARCHITECTURE.md SYSTEM_DESIGN.md backend frontend docs postman scripts
git diff --cached --check
git diff --cached --stat
git commit -m "Require email verification before member login"
git push origin dev
```

Lakukan commit/push setelah pemeriksaan lokal lulus. Paket tidak menjalankan Git push, migrasi, atau pengiriman email nyata secara otomatis.
