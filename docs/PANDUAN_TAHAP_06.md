# Panduan Tahap 06 — Frontend Anggota

Status: implementasi tahap 06 untuk diterapkan dan diuji pada Laragon.

Jalankan panduan ini **berurutan**. PRD.md, ARCHITECTURE.md, SYSTEM_DESIGN.md, dan AGENTS.md final tetap menjadi acuan dan tidak diubah.

## 1. Hasil tahap ini

- Halaman registrasi dengan validasi, tanggal lahir, dan dua persetujuan terpisah.
- Login/logout menggunakan sesi Laravel Sanctum dan CSRF.
- Beranda sesuai status keanggotaan, kirim ulang email dengan cooldown.
- Halaman verifikasi dari email, pemeriksaan pemilik akun tetap di Laravel.
- Kartu anggota dari API, QR asli yang dapat dipindai, dialog pembesaran, dan cetak.
- Halaman profil **baca saja** untuk identitas/status yang tersedia dari API sesi.
- Warna SRC dan font lokal Plus Jakarta Sans, tampilan mobile termasuk 320 px.

Tahap ini belum mencakup edit profil/foto, perubahan email/password, lupa password, event, scanner petugas, admin, dan laporan. Menu Event ditambahkan saat modulnya tersedia. QR sekarang merupakan identitas anggota; QR belum mencatat kehadiran karena API event/check-in belum dibuat.

## 2. Siapkan branch dev dan salin paket

Paket dibuat berdasarkan branch dev pada commit `0aeb7e04ac36c67cfe1acfa6fe68681d015622c8`. Paket berisi file baru/perubahan saja, bukan seluruh repository.

Di Git Bash:

```bash
cd /c/laragon/www/sorerunapp
git switch dev
git status --short
```

Jika ada perubahan lokal sebelumnya, simpan melalui commit yang sesuai sebelum menyalin paket. Jangan menimpa perubahan lokal yang belum diperiksa.

Setelah working tree bersih:

```bash
git pull --ff-only origin dev
```

Ekstrak ZIP ke folder sementara. Salin **isi** folder paket (`backend`, `frontend`, `postman`, dan `docs`) ke root `C:\laragon\www\sorerunapp`, lalu gabungkan foldernya. Jangan memasukkan satu folder sorerunapp tambahan di dalam project.

File yang namanya sama memang diperbarui oleh tahap ini. Jika source Anda berbeda dari commit dasar, bandingkan sebelum mengganti file. Paket tidak membawa `.env`, `.env.local`, `vendor`, `node_modules`, `.next`, ataupun folder `.git`.

## 3. Konfigurasi backend yang sudah berjalan

Edit `.env` backend yang **sudah Anda gunakan**. Jangan menggantinya dengan `.env.example` dan jangan membuat ulang APP_KEY. Database development tetap MySQL yang sudah dipakai tahap sebelumnya.

Pastikan nilai lokal berikut sesuai; setiap nama variabel hanya muncul satu kali:

```dotenv
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:3000
SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost:8000
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false
SESSION_DRIVER=database
QUEUE_CONNECTION=database
REGISTRATION_PRIVACY_VERSION=dev-v1
REGISTRATION_TERMS_VERSION=dev-v1
```

`SESSION_SECURE_COOKIE=false` khusus HTTP lokal. Tetap pertahankan `MEMBER_NUMBER_PREFIX` dan konfigurasi MySQL dari tahap 05; jangan mengubah prefix/nomor yang sudah diterbitkan. Gunakan host **localhost** konsisten pada browser, API origin, dan APP_URL. Jangan mencampurnya dengan `127.0.0.1` untuk URL aplikasi.

Untuk email lokal gunakan SMTP Mailpit yang sudah Anda siapkan:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS=noreply@sorerunapp.test
MAIL_FROM_NAME="Sore Running Club"
```

Contoh tersebut mengasumsikan SMTP Mailpit pada port 1025 dan inbox pada `http://127.0.0.1:8025`. Sesuaikan port dengan instance lokal Anda. Tahap ini tidak memerlukan email ke alamat sungguhan. Mailer `log` tidak didukung alur verifikasi karena tautan rahasia tidak boleh masuk log.

```bash
cd /c/laragon/www/sorerunapp/backend
php artisan config:clear
```

Jika worker tahap sebelumnya masih berjalan, hentikan dengan Ctrl+C dan jalankan kembali pada langkah 7 agar memuat kode/konfigurasi baru. Tidak ada migration baru atau dependency Composer baru pada tahap ini.

## 4. Konfigurasi dan dependency frontend

```bash
cd /c/laragon/www/sorerunapp/frontend
```

Jika belum memiliki `.env.local`, buat dari contoh:

```bash
cp .env.example .env.local
```

Jika file sudah ada, edit/selaraskan isinya tanpa menghapus konfigurasi Anda yang masih diperlukan:

```dotenv
NEXT_PUBLIC_API_BASE_URL=http://localhost:8000
NEXT_PUBLIC_PRIVACY_NOTICE_VERSION=dev-v1
NEXT_PUBLIC_TERMS_VERSION=dev-v1
```

API_BASE_URL adalah **origin**, tidak memakai `/api/v1` atau trailing path. Dua versi kebijakan harus sama dengan `REGISTRATION_*_VERSION` pada backend. Variabel NEXT_PUBLIC dapat dibaca browser; jangan isi password database, APP_KEY, atau credential SMTP.

Pasang dependency sesuai lockfile yang disertakan:

```bash
npm ci
```

`npm ci` mengganti isi node_modules berdasarkan package-lock.json. Tidak perlu memasang ulang Next.js atau membuat project baru. Versi major Next 16, React 19, Axios 1, dan TanStack Query 5 dipertahankan.

## 5. Jalankan pemeriksaan frontend

```bash
npm run lint
npm run typecheck
npm run test
npm run build
npm audit --omit=dev
```

Hasil yang diharapkan: lint/typecheck tanpa error, **10 test unit lulus**, build selesai, dan audit dependency produksi diperiksa. Advisory development pada rantai ESLint perlu dipantau tersendiri. Jangan menggunakan `npm audit fix --force` karena dapat mengganti major dependency yang sudah dipilih.

Pengujian browser otomatis memerlukan Chromium untuk Playwright, sekali pasang:

```bash
npx --no-install playwright install chromium
npm run test:e2e
```

Hasil yang diharapkan: **7 test browser lulus**. Test membuka server frontend sendiri pada `http://localhost:3100`; port itu harus kosong. Anda tidak perlu menjalankan `npm run dev` pada 3100 secara manual.

Test browser memakai fixture API sintetis yang hanya aktif di file pengujian. Test ini memeriksa UI, CSRF header, alur navigasi, pembersihan cache, QR, dan kondisi gagal. Test ini **tidak menggantikan** pengujian Laravel/MySQL/SMTP nyata pada langkah 6–8. Source aplikasi normal tetap memanggil backend sesungguhnya.

Jika build sedang berjalan, tunggu selesai sebelum menjalankan test yang menggunakan Next dev. Hentikan server lama sebelum mengganti dependency. Error cache `.next` dapat diperiksa terpisah; folder build bukan source yang perlu masuk Git.

## 6. Jalankan pemeriksaan backend pada database test

Gunakan `.env.testing` serta database **sorerunapp_test** terisolasi yang sudah dipakai tahap 05. Jangan menunjuk database test ke data development/produksi. Suite menjalankan migrasi dan pengujian transaksi/concurrency pada MySQL.

```bash
cd /c/laragon/www/sorerunapp/backend
php artisan config:clear
bash scripts/verify-frontend-bridge-stage06.sh --fix
```

Script memeriksa sintaks, merapikan PHP memakai Pint, memeriksa format, lalu menjalankan `phpunit.verification.xml` yang mencakup tahap 01–06. Ada test baru untuk pembungkus tautan email ke frontend dan penolakan origin yang tidak valid. Teks error sintetis dari test kegagalan pengiriman bukan otomatis test gagal; lihat ringkasan PHPUnit terakhir.

Jika hasilnya FAILURES/ERRORS, hentikan penerapan tahap berikutnya dan periksa pesan beserta lokasi kegagalannya. Jangan menjalankan `migrate:fresh` pada database anggota.

## 7. Jalankan aplikasi lokal

Nyalakan MySQL dan Mailpit. Gunakan terminal terpisah untuk proses berikut.

**Terminal API:**

```bash
cd /c/laragon/www/sorerunapp/backend
php artisan serve --host=localhost --port=8000
```

**Terminal worker email:**

```bash
cd /c/laragon/www/sorerunapp/backend
php artisan queue:work database --queue=mail --tries=1 --timeout=60 --sleep=1
```

**Terminal scheduler outbox:**

```bash
cd /c/laragon/www/sorerunapp/backend
php artisan schedule:work
```

**Terminal frontend:**

```bash
cd /c/laragon/www/sorerunapp/frontend
npm run dev
```

Buka `http://localhost:3000`. Jika 3000 dipakai proses lain, hentikan proses tersebut atau ubah seluruh origin/CORS/Sanctum/tautan secara konsisten. Jangan langsung mengikuti port alternatif tanpa menyesuaikan konfigurasi.

## 8. Uji alur nyata melalui tampilan aplikasi

Gunakan data sintetis dan alamat baru, misalnya `anggota-tahap06-01@example.test`. Alamat yang sudah pernah terdaftar menerima respons registrasi generik dan tidak otomatis membuat email baru.

| Langkah | Hasil yang diperiksa |
| --- | --- |
| Buka `/register`, kirim formulir kosong | Error terhubung ke field; input yang telah diisi tetap ada |
| Isi nama, tanggal `dd/mm/yyyy`, telepon, kota, email dan password | Tanggal valid; password minimal 12 karakter/maksimal 72 byte; konfirmasi cocok |
| Baca dua halaman kebijakan dan centang dua persetujuan | Keduanya wajib, tidak dicentang otomatis |
| Klik Daftar Member | Halaman Periksa email Anda; belum login dan belum aktif |
| Login akun baru | Beranda menunggu verifikasi; kartu QR belum tersedia |
| Buka inbox Mailpit, email verifikasi **baru** | Tombol menuju `localhost:3000/verify-email` dengan fragmen verifikasi |
| Buka tautan di browser | Fragmen dihapus dari address bar; verifikasi belum dijalankan otomatis |
| Jika belum login di browser tersebut, klik Masuk untuk Verifikasi | Login dengan akun pemilik email; kembali ke layar verifikasi |
| Klik Verifikasi dan Aktifkan Keanggotaan | Server memvalidasi pemilik/masa berlaku/signature; keanggotaan aktif |
| Buka Kartu Anggota | Nama/nomor dari API dan QR hitam-putih; tombol pembesaran/cetak berfungsi |
| Buka QR besar, tekan Esc | Dialog tertutup dan fokus kembali ke tombol pembukanya |
| Buka Profil | Identitas/status dari API tampil, belum ada editor profil |
| Logout, lalu buka `/kartu` | Kartu hilang dan halaman meminta login |

**Sesi Postman berbeda dari sesi browser.** Login di Postman tidak membuat Anda login di Chrome/Edge. Uji UI melalui login pada aplikasi.

Tautan verifikasi disimpan hanya dalam memori tab setelah fragmen dibaca, agar tidak masuk storage/cache route. Navigasi lewat tombol login dalam tab yang sama mempertahankannya. Reload/menutup tab menghilangkannya: buka lagi tautan dari email. Tautan berlaku 60 menit; akun pending dapat meminta email baru lewat tombol Kirim Ulang Email, dengan cooldown server.

Email lama sebelum tahap 06 mungkin masih mengarah langsung ke API dan menampilkan JSON. Minta email baru sesudah worker diperbarui untuk menguji halaman frontend. Tautan API lama tetap mengikuti pemeriksaan backend yang sama.

Coba juga password salah, email kedaluwarsa, akun pemilik yang berbeda, dan koneksi terputus saat logout. Logout yang belum terkonfirmasi menyembunyikan data privat dan meminta pemeriksaan sesi/percobaan keluar kembali. Aplikasi tidak mengklaim logout sukses ketika server belum memberi kepastian.

Cetak dapat menggunakan opsi Save as PDF pada browser; file tersebut berisi credential anggota, sehingga simpan sebagai dokumen privat. Kartu cetak/screenshot tidak menjamin check-in: petugas tetap harus memeriksa identitas dan eligibility melalui sistem saat modul event tersedia.

## 9. Postman tetap digunakan untuk pengujian API

Import ulang file `postman/sorerunapp-stage05.postman_collection.json` yang diperbarui. Environment lokal tahap 05 tetap dapat dipakai. Script pembaca email kini menerima tautan API langsung maupun tautan frontend baru, lalu menguji URL verifikasi API dengan sesi Postman sebagaimana sebelumnya.

Ulangi pengujian API sesuai urutan koleksi tahap 05. Pengujian frontend dilakukan lewat tampilan aplikasi; tidak perlu menyalin kode ke Console DevTools.

## 10. Catat progres dan push ke dev

Setelah pemeriksaan dan uji lokal berhasil:

```bash
cd /c/laragon/www/sorerunapp
git branch --show-current
git status --short
git diff --check
git add frontend backend postman docs
git diff --cached --check
git status --short
```

Pastikan branch adalah `dev`, dan daftar staged tidak mengandung `.env`, `.env.local`, node_modules, vendor, hasil build, log, atau data anggota. File `.env.example` memang boleh masuk Git karena hanya contoh.

```bash
git commit -m "feat: add member frontend and browser email verification"
git push origin dev
```

Jangan menandai uji backend lulus sebelum benar-benar menjalankannya pada Laragon. Jika perlu, catat hasil pengujian aktual dalam deskripsi commit/PR.

Jika tahap ini siap masuk main, buat Pull Request di GitHub dengan **base: main** dan **compare: dev**, tinjau diff dan hasil tes, lalu merge. Tetap gunakan dev untuk pengerjaan berikutnya; sinkronkan dev dengan main setelah merge. Paket ini tidak melakukan commit, push, merge, atau deploy secara otomatis.

## 11. Batas pemeriksaan yang disertakan

Pemeriksaan di lingkungan penyusunan: ESLint, TypeScript, 10 test unit, 7 test browser Chromium dengan API fixture, build produksi, audit dependency produksi, simulasi script Postman, dan pemeriksaan statis PHP/XML/shell.

PHP, MySQL, dan SMTP tidak tersedia di lingkungan penyusunan. Karena itu hasil PHPUnit/integrasi Laravel–browser–Mailpit nyata harus Anda pastikan melalui langkah 6–8. Perubahan tahap ini tidak menjadikan seluruh aplikasi siap go-live. Review konten kebijakan, kontak pengurus, operasional privasi/retensi, backup, serta konfigurasi HTTPS/SMTP produksi tetap mengikuti dokumen final dan tahap implementasinya.
