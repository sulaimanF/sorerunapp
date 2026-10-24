# Revisi login tahap 06 — langsung masuk

Revisi ini menghilangkan banner “Anda sedang masuk sebagai … / Lanjutkan ke akun Anda” pada halaman login. Setelah login berhasil, pengguna langsung berpindah ke Beranda. Jika sesi masih aktif saat membuka `/login`, perpindahan berlangsung otomatis tanpa mengisi ulang form atau menekan tombol tambahan.

Tujuan internal yang diizinkan melalui `returnTo` tetap dipertahankan, termasuk kembali ke verifikasi email. Login tidak mengaktifkan keanggotaan yang belum terverifikasi; tombol verifikasi email tetap digunakan sesuai proses sebelumnya. Pesan kesalahan login tetap ditampilkan ketika diperlukan.

## 1. Terapkan file revisi

Hentikan frontend yang sedang berjalan menggunakan **Ctrl+C** pada terminalnya. Ekstrak ZIP ke root `C:\laragon\www\sorerunapp`, lalu gabungkan folder `frontend` dan `docs` yang sudah ada. Ganti dua file berikut dengan versi dalam ZIP:

- `frontend/src/features/auth/components/login-form.tsx`
- `frontend/tests/browser/membership.spec.ts`

ZIP ini juga berisi panduan ini pada `docs/PANDUAN_REVISI_LOGIN_TAHAP_06.md`. Revisi hanya memerlukan file tersebut; tidak perlu instal ulang dependency atau menjalankan migrasi. Komponen login menggunakan `text-brand` yang sudah tersedia pada implementasi palet Sunset versi 1.1. File CSS tidak disertakan atau ditimpa.

## 2. Jalankan pemeriksaan frontend

Dari Git Bash:

```bash
cd /c/laragon/www/sorerunapp/frontend
npm run lint
npm run typecheck
npm run test
npm run build
npm run test:e2e
```

Jalankan berurutan dan pastikan setiap perintah selesai sebelum melanjutkan. Hasil yang diharapkan: lint, pemeriksaan tipe, dan build berhasil; 10 unit test serta 10 browser test lulus. Playwright menggunakan port 3100; pastikan port itu tidak dipakai server lain. Browser Chromium dari tahap 06 tetap digunakan.

Browser test menggunakan API fixture terisolasi; hasil tersebut memverifikasi perilaku frontend, bukan koneksi ke MySQL atau pengiriman email nyata.

## 3. Uji melalui browser dengan backend lokal

Jalankan backend seperti biasa dari terminal backend, lalu frontend dari terminal terpisah:

```bash
cd /c/laragon/www/sorerunapp/frontend
npm run dev
```

Buka `http://localhost:3000` dan periksa:

1. Setelah logout, buka `/login`. Form login tampil setelah pemeriksaan sesi selesai.
2. Isi email dan password yang benar. Pengguna langsung tiba di Beranda, tanpa klik konfirmasi tambahan.
3. Saat sesi masih aktif, buka `/login` secara langsung. Pengguna otomatis kembali ke Beranda tanpa banner akun aktif.
4. Logout, lalu coba password salah. Pesan kesalahan tampil dan input tetap tersedia untuk diperbaiki.
5. Coba alur verifikasi email yang membutuhkan login. Setelah login, pengguna otomatis kembali ke halaman verifikasi; keanggotaan hanya aktif setelah verifikasi berhasil.
6. Untuk mengganti akun, gunakan tombol **Keluar dari akun**, kemudian login dengan akun lain.

Saat pemeriksaan sesi atau perpindahan halaman berjalan, indikator singkat “Menyiapkan akun…” tidak memerlukan klik.

## 4. Simpan riwayat ke branch dev

Setelah semua pemeriksaan berhasil, tinjau perubahan dari root:

```bash
cd /c/laragon/www/sorerunapp
git branch --show-current
git status --short
git diff --check
git diff -- frontend/src/features/auth/components/login-form.tsx frontend/tests/browser/membership.spec.ts
```

Pastikan branch aktif `dev`. Untuk mencatat revisi ini saja:

```bash
git add frontend/src/features/auth/components/login-form.tsx frontend/tests/browser/membership.spec.ts docs/PANDUAN_REVISI_LOGIN_TAHAP_06.md
git diff --cached --check
git diff --cached --stat
git commit -m "fix(auth): redirect active sessions from login automatically"
git push origin dev
```

Periksa staging sebelum commit apabila sebelumnya sudah ada file lain yang di-stage. Perubahan lain yang belum disimpan, misalnya palet Sunset, dapat dicatat terpisah setelah ditinjau.
