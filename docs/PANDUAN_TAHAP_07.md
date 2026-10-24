# Panduan Tahap 07 — Biodata Anggota

Ikuti langkah berurutan. Tahap ini menambahkan editor biodata dan API profil sendiri. **Alur login awal yang Anda pilih dipertahankan.** Tidak ada migration, dependency produksi baru, atau perubahan palet.

## 1. Terapkan paket ke project

Hentikan frontend dan API menggunakan **Ctrl+C** pada terminal masing-masing. Jika worker antrean sedang berjalan, hentikan juga agar nanti memuat kode terbaru.

Ekstrak ZIP ke `C:\laragon\www\sorerunapp`, lalu gabungkan folder `backend`, `frontend`, `docs`, dan `postman`. File yang namanya sama diperbarui. Jika ada perubahan pribadi pada file tersebut, bandingkan isinya sebelum mengganti. Daftar file paket tersedia di `docs/STAGE_07_FILES.txt`.

Paket tidak membawa `.env`, `.env.testing`, `.env.local`, APP_KEY, vendor, node_modules, .next, data anggota, atau folder .git. Jangan mengganti APP_KEY yang sudah dipakai; credential QR lama membutuhkan kunci yang sama. Dokumen PRD/ARCHITECTURE/SYSTEM_DESIGN/AGENTS serta CSS utama dan login tidak diganti.

## 2. Siapkan autoload backend

Dari Git Bash:

```bash
cd /c/laragon/www/sorerunapp/backend
composer dump-autoload
php artisan config:clear
php artisan route:list --path=api/v1/me/profile
```

Harus terlihat GET/HEAD `/api/v1/me/profile` dan PATCH `/api/v1/me/profile`. Tidak perlu menginstal ulang Laravel, menjalankan composer update, membuat APP_KEY baru, atau menjalankan migrasi baru.

## 3. Periksa backend dengan database test terisolasi

Gunakan `.env.testing` dan database **sorerunapp_test MySQL 8.4** yang sudah digunakan pada tahap sebelumnya. Driver session database dan koneksi test tetap mengikuti konfigurasi yang ada. Jangan menggunakan database development/produksi untuk pengujian.

```bash
cd /c/laragon/www/sorerunapp/backend
bash scripts/verify-profiles-stage07.sh --fix
```

Script memeriksa sintaks PHP, menjalankan Pint untuk file terkait, memeriksa format, lalu menjalankan `phpunit.profiles.xml`. Config mencakup regresi tahap 01–06, pengujian profil baru, dan dua skenario concurrency MySQL dengan dua proses nyata. Tabel test yang belum tersedia dipersiapkan oleh suite schema yang sudah ada. Script tidak menjalankan migrate:fresh atau menghapus data anggota development.

Hasil yang diharapkan: sintaks valid, Pint PASS, serta ringkasan PHPUnit **OK**. Jumlah test tidak dijadikan angka tetap karena suite lama dapat bertambah. Teks log kegagalan sintetis dari test rollback bukan otomatis test gagal; lihat ringkasan PHPUnit. Jika ada FAILURES/ERRORS, periksa kegagalannya sebelum melanjutkan.

Kasus yang diuji mencakup ownership, CSRF, versi sesi, akun blocked, field terlarang, data wajib, kalender, anak di bawah umur, normalisasi telepon, audit tanpa biodata, PATCH identik, rollback aktivasi, nomor/QR tetap, serta pelengkapan profil bersamaan dengan verifikasi email.

## 4. Periksa frontend

Tidak ada perubahan package.json atau lockfile pada tahap ini. Gunakan dependency tahap 06 yang sudah terpasang.

```bash
cd /c/laragon/www/sorerunapp/frontend
npm run lint
npm run typecheck
npm run test
npm run build
npm run test:e2e -- tests/browser/profile.spec.ts
```

Jalankan satu per satu sampai selesai. Hasil yang diharapkan: lint/typecheck/build berhasil, 13 unit test pada salinan tahap ini lulus, dan 9 browser test profil lulus. Chromium tetap menggunakan instalasi Playwright tahap 06; port 3100 harus tersedia untuk test. Anda juga dapat menjalankan `npm run test:e2e` untuk seluruh suite frontend yang tersedia pada project.

Browser test memakai API fixture sintetis. Itu membuktikan perilaku frontend, bukan integrasi Laravel/MySQL/SMTP nyata. Pengujian backend pada langkah 3 dan Postman/browser lokal pada langkah berikut tetap diperlukan.

## 5. Jalankan aplikasi lokal

Terminal backend:

```bash
cd /c/laragon/www/sorerunapp/backend
php artisan serve --host=localhost --port=8000
```

Terminal frontend:

```bash
cd /c/laragon/www/sorerunapp/frontend
npm run dev
```

Jika menggunakan queue database dan Mailpit untuk aktivasi, jalankan kembali worker di terminal terpisah seperti tahap 06:

```bash
cd /c/laragon/www/sorerunapp/backend
php artisan queue:work
```

Gunakan host **localhost** konsisten untuk frontend/API. Environment API origin, Sanctum, CORS, session, dan kebijakan tetap memakai konfigurasi tahap 06. Tidak perlu mengirim email sungguhan untuk pengujian ini; gunakan akun sintetis dan Mailpit lokal.

## 6. Uji API melalui Postman

Import:

- `postman/sorerunapp-stage07.postman_collection.json`
- `postman/sorerunapp-stage07.postman_environment.json`

Pilih environment **sorerunapp — Lokal Tahap 07**. Isi `test_email` dan `test_password` dengan **akun uji sintetis yang sudah didaftarkan pada tahap sebelumnya**. Email default hanyalah contoh dan tidak otomatis dibuat oleh koleksi. Akun boleh belum terverifikasi untuk mengedit profil. Cookie jar harus aktif; tambahkan localhost pada Cookies → Domains allowlist bila diminta Postman.

**Request 05 mengubah biodata akun yang login. Jangan gunakan akun anggota sungguhan.** Username/password di environment merupakan data lokal; jangan commit atau membagikan export yang sudah berisi rahasia.

Jalankan request berurutan:

| Nomor | Operasi | Hasil |
| --- | --- | --- |
| 01 | Ambil CSRF | 204, cookie XSRF/session tersedia |
| 02 | Login akun uji | 200 |
| 03 | Ambil snapshot nomor dari sesi | 200 |
| 04 | Baca profil sendiri | 200, hanya field profil yang diizinkan |
| 05 | Simpan biodata uji | 200, phone menjadi +6281234567890, birth_date 2012-02-29 |
| 06 | Baca sesi terbaru | 200, nama baru; nomor lama tetap jika sebelumnya sudah diterbitkan |
| 07 | Domisili kosong | 422 |
| 08 | Tanggal kalender salah | 422 |
| 09 | Coba mengirim user_id/email | 422; field asing ditolak |
| 10 | JSON rusak | 400 |
| 11 | Content-Type bukan JSON | 415 |
| 12 | PATCH tanpa header CSRF | 419 |
| 13 | Baca ulang profil | 200; data valid dari langkah 05 tetap utuh |
| 14 | Logout | 204 |
| 15 | Baca profil setelah logout | 401 |

Untuk PATCH parsial, contoh body `{"city":"Bandung Uji"}` hanya mengubah domisili. Field yang tidak dikirim dipertahankan. `{"gender":null}` menghapus nilai opsional. Endpoint ini tidak menerima perubahan email, password, role, membership, nomor member, atau path foto.

Semua response API membawa `meta.request_id` dan `X-Request-ID` yang konsisten, serta no-store. Koleksi sudah memuat assertion; lihat tab Test Results setelah mengirim setiap request. POST/PATCH yang membutuhkan CSRF membaca cookie terbaru secara otomatis.

## 7. Uji tampilan browser

Buka `http://localhost:3000`. Login mengikuti alur awal yang Anda pilih, lalu buka **Profil**.

1. Data wajib yang tersimpan harus muncul dengan benar. Tanggal tampil dd/mm/yyyy.
2. Ubah nama, telepon, domisili, dan jenis kelamin opsional. Tekan **Simpan Perubahan**. Tunggu respons sukses; nilai normalisasi server akan ditampilkan.
3. Nama pada menu akun dan kartu berubah setelah data dimuat kembali. Nomor member serta QR untuk keanggotaan yang sudah aktif tetap sama.
4. Coba nama satu karakter, domisili kosong, atau tanggal tidak valid. Error terlihat dan input tidak hilang. Tombol **Batalkan Perubahan** mengembalikan nilai terakhir yang diterima dari server.
5. Pada kegagalan koneksi/5xx, aplikasi tidak mengklaim sukses. **Periksa Data Tersimpan** melakukan GET dan mengganti input dengan data server setelah Anda memilihnya; tidak mengirim PATCH ulang otomatis.
6. Unverified tetap harus memverifikasi email. Jika email sudah verified dan membership masih pending karena profil belum lengkap, pelengkapan profil mengaktifkan membership satu kali.
7. Membership suspended/ended tidak aktif kembali hanya karena biodata disimpan. Reaktivasi tetap tindakan admin pada tahap terkait.
8. Logout membersihkan data privat. Uji dengan dua akun sintetis untuk memastikan data akun sebelumnya tidak muncul.

Foto, perubahan email/password, pemulihan password, event, dan scanner belum menjadi bagian tahap ini. Tidak ada perubahan alur login, pembayaran, approval admin, atau RSVP.

## 8. Tinjau dan push ke dev

Setelah semua pemeriksaan lokal berhasil:

```bash
cd /c/laragon/www/sorerunapp
git branch --show-current
git status --short
git diff --check
```

Pastikan branch `dev`. Daftar berikut men-stage file paket tahap 07 secara eksplisit:

```bash
git add --pathspec-from-file=docs/STAGE_07_FILES.txt
git diff --cached --check
git diff --cached --stat
git diff --cached
```

Periksa hasilnya, termasuk file yang mungkin sudah di-stage sebelumnya. Setelah sesuai:

```bash
git commit -m "feat(members): add owned profile editing and completion activation"
git push origin dev
```

Merge ke main mengikuti proses review yang sudah Anda gunakan. Paket tidak melakukan commit, push, merge, atau deploy otomatis.

## 9. Batas verifikasi penyusunan

Hasil yang benar-benar dijalankan dicatat pada [VERIFICATION_STAGE_07.md](VERIFICATION_STAGE_07.md). PHP/Composer/MySQL tidak tersedia pada lingkungan penyusunan; PHPUnit, Pint, concurrency MySQL, serta integrasi backend nyata harus Anda pastikan melalui Laragon. Pemeriksaan parser source PHP bukan pengganti pengujian Laravel. Tahap ini belum menyatakan aplikasi siap go-live.
