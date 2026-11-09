# Panduan penerapan navigasi dan tampilan auth

Tanggal 25 Oktober 2026. Menerapkan preview yang disetujui: posisi tautan pada Login, Verifikasi Email, dan Kirim Ulang Verifikasi. Login berhasil selalu ke beranda, seluruh pengalihan ke login memakai URL bersih tanpa returnTo.

## 1. Terapkan perubahan

Paket ini melanjutkan revisi email-first yang backend-nya sudah Anda uji berhasil. Tidak mengganti backend, layanan API frontend, session provider, palet Sunset, database, atau dependency. Tidak perlu menjalankan ulang migration atau npm install.

1. Hentikan `npm run dev` terlebih dahulu. Simpan pekerjaan lokal pada branch dev.
2. Ekstrak isi ZIP ke `C:\laragon\www\sorerunapp`, mengikuti struktur folder di dalamnya. Ganti file yang disertakan, tanpa menghapus folder proyek.
3. Jalankan dari Git Bash:

```bash
cd /c/laragon/www/sorerunapp
node scripts/apply-auth-navigation.cjs
node scripts/centralize-auth-css.cjs
```

Script menggunakan TypeScript yang sudah terpasang di frontend/node_modules. Script menambahkan/memperbarui hanya redirect root di next.config.ts, menjaga header dan konfigurasi lain. Root page.tsx yang hanya berisi redirect lama dipindahkan fungsinya ke konfigurasi dan dihapus setelah dibuat backup. Jika root page berisi logika lain atau konfigurasi tidak dikenali, script berhenti sebelum menulis shared files.

Script kedua menambahkan aturan layout auth ke akhir frontend/src/app/globals.css, menghapus import auth-layout.css pada tiga komponen, lalu menghapus file CSS lama. Isi globals.css existing dipertahankan. Jika CSS auth lama sudah dimodifikasi atau selector yang sama sudah ditambahkan tanpa penanda paket, script berhenti sebelum mengubah source agar Anda dapat memeriksa perubahan tersebut. Script aman dijalankan ulang.

Backup shared source tersedia pada `.auth-navigation-backup/`, ditambahkan ke root .gitignore bila file tersebut tersedia. Jangan commit backup. Pemanggilan script kedua aman dan tidak menggandakan redirect. File konfigurasi lengkap milik Anda tidak ditimpa oleh file baseline.

Script juga menyesuaikan helper login positif browser test membership/profile yang dikenali: login wajib melewati /beranda dahulu, lalu test boleh membuka halaman sasaran secara eksplisit. Source profil tahap07 milik Anda tetap dipertahankan.

## 2. Bangun ulang tipe route dan periksa frontend

Karena halaman root dipindahkan ke konfigurasi redirect, cache tipe route lama perlu dibersihkan. Jalankan perintah berikut **dari folder frontend**:

```bash
cd /c/laragon/www/sorerunapp/frontend
rm -rf .next
npx --no-install next typegen
npm run lint
npm run typecheck
npm run test
npm run test:e2e
npm run build
```

Daftar route hasil build tidak lagi menampilkan page `/` karena root sekarang ditangani konfigurasi redirect; ini sesuai rancangan.

`.next` berisi cache/build Next.js yang dibuat ulang. Tidak menghapus source aplikasi atau .env.local. Browser Playwright yang telah dipasang tetap dapat digunakan. Bila belum terpasang, jalankan `npx --no-install playwright install chromium` sesuai panduan sebelumnya.

Unit test return-path lama dihapus karena fitur tujuan dinamis sudah dihilangkan. Sembilan unit test lain tetap berlaku; regressi tujuan login kini diperiksa lewat browser untuk URL profil/kartu, tautan eksternal/nested, logout, dan sesi berakhir. Test browser menggunakan API fixture lokal, bukan database atau SMTP nyata. Pengujian profil frontend lokal tahap07 tetap dijalankan bila file test-nya tersedia.

## 3. Jalankan kembali dan uji manual

```bash
npm run dev
```

Perintah tersebut menjalankan server. Ketika membuka `http://localhost:3000/`, aplikasi mengalihkan ke `http://localhost:3000/login`. Perintah ini tidak membuka tab browser secara otomatis.

| Skenario | Hasil |
| --- | --- |
| Membuka / | /login, redirect 307 sebelum render root page |
| Membuka /profil, /kartu, /beranda sebagai tamu | /login, tanpa query |
| Membuka /login?returnTo=%2Fprofil atau tautan login lama lainnya | URL dibersihkan menjadi /login; query tidak menentukan tujuan |
| Login berhasil | /beranda |
| Klik Profil/Kartu saat sesi aktif | Menu yang dipilih, tanpa login ulang |
| Logout dari Profil atau Kartu | /login, tanpa returnTo |
| Sesi habis saat mengakses halaman anggota | /login; setelah login ulang tetap /beranda |
| Membuka login saat sesi sudah aktif | Banner sesi aktif/manual lanjut tetap tersedia, tujuan lanjut /beranda |
| Hasil verifikasi email, termasuk profil incomplete | Tautan Masuk ke Akun menuju /login; tidak otomatis login |
| Login sebelum email verified | Tetap ditolak oleh aturan email-first existing |

Jika logout gagal atau koneksi belum terkonfirmasi, aplikasi tetap memakai penanganan pemulihan yang sudah tersedia; tidak mengaku logout sukses. Perpindahan menu bukan alasan meminta login ulang selama sesi valid.

## 4. Periksa tampilan sesuai preview

- Login: baris Belum menjadi anggota? + Daftar Member dipusatkan pada footer 28 px setelah tombol Masuk. Tautan Belum menerima email verifikasi? berada di bawahnya, gap 12 px.
- Verifikasi: Kirim Ulang Email dan Kembali ke Login menjadi dua baris vertikal rata kiri. Kelompok tautan dimulai 28 px setelah tombol Verifikasi Email, gap 12 px.
- Kirim ulang: Kembali ke Login rata kiri, 28 px setelah tombol Kirim Ulang Email.

Styling auth terpusat di frontend/src/app/globals.css, memakai prefix src-auth- agar selector hanya menargetkan elemen auth. Tidak ada lagi file auth-layout.css ataupun import CSS pada tiga komponen auth. Tidak mengubah font, warna, ukuran kontrol, maupun tombol loading/cooldown. Pada perangkat sentuh, target tautan tetap minimal 44 px. Periksa juga viewport 320 px dan navigasi keyboard.

Dokumen final yang diselaraskan: PRD 1.2, ARCHITECTURE 1.2, SYSTEM_DESIGN 1.3, AGENTS 1.2. Styling auth pada SYSTEM_DESIGN diselaraskan dengan keputusan pemilik produk untuk memusatkannya di globals.css. Revisi verifikasi sebelum login tetap berlaku.

## 5. Catat ke GitHub setelah lulus

Dari root, pastikan tidak memasukkan .env, hasil build, backup source, atau nilai rahasia Postman:

```bash
cd /c/laragon/www/sorerunapp
git status --short
git add AGENTS.md ARCHITECTURE.md PRD.md SYSTEM_DESIGN.md frontend docs scripts
git diff --cached --check
git diff --cached --stat
git commit -m "Use fixed auth navigation and refine auth link layouts"
git push origin dev
```

Tidak ada commit/push/deploy yang dijalankan otomatis. Detail hasil pemeriksaan penyusunan tersedia di docs/VERIFICATION_AUTH_NAVIGATION.md.
