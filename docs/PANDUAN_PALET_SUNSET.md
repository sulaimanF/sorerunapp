# Penerapan Palet Sunset — SYSTEM_DESIGN 1.1

Status: implementasi frontend berdasarkan SYSTEM_DESIGN.md versi 1.1 final, 21 Oktober 2026.

## 1. Cakupan perubahan

Palet baru berlaku pada login, registrasi, hasil registrasi, verifikasi email, beranda, kartu anggota, profil, navigasi, dialog, dan state form yang sudah tersedia. Token dikendalikan di `frontend/src/app/globals.css`.

| Area | Penerapan |
| --- | --- |
| CTA utama | Sunset Orange #EA6113, teks Deep Brown #2B2118 |
| Hover/pressed CTA | Warm Orange #F88F22, teks tetap Deep Brown |
| Panel identitas/kartu anggota | Deep Brown #2B2118, teks Warm Canvas #FFF9F0 |
| Latar halaman | Warm Canvas #FFF9F0 |
| Panel form/input/dialog | White #FFFFFF |
| Tautan pada latar terang | Deep Orange #B9470B; hover Deep Brown |
| Teks penekanan | Deep Orange, terpisah dari warna background CTA agar kontras cukup |
| Panel pendukung/navigasi aktif | Sunset Cream #FFE3B3, teks Deep Brown |
| Teks bantuan | Muted Brown #6B5B4D |
| Batas input | Input Border #8A7868 |
| Fokus pada bidang gelap | Golden Yellow #FBB931 |
| Sukses/warning/error/info | Makna dan palet status mengikuti dokumen final |
| QR dan panel QR, termasuk cetak | Hitam #000000 pada putih #FFFFFF |

Kelas teks penekanan sebelumnya `text-primary` menjadi `text-brand`. Warna primary tetap dipakai untuk background CTA; teks kecil dan tautan memakai warna yang lebih gelap. Referensi token forest/ivory lama diganti nama semantik brand-dark/brand-light agar tidak mengembalikan palet lama.

Tidak ada perubahan dependency, environment, endpoint, schema, guard sesi, atau proses bisnis. Patch ini tidak membawa foto, data anggota, hasil build, maupun screenshot.

## 2. File yang disertakan

Delapan file source:

1. `frontend/src/app/globals.css`
2. `frontend/src/app/(public)/registration-received/page.tsx`
3. `frontend/src/features/auth/components/login-form.tsx`
4. `frontend/src/features/auth/components/register-form.tsx`
5. `frontend/src/features/auth/components/verification-screen.tsx`
6. `frontend/src/features/membership/components/dashboard-screen.tsx`
7. `frontend/src/features/membership/components/card-screen.tsx`
8. `frontend/src/features/members/components/profile-screen.tsx`

Paket juga membawa panduan ini dan manifest checksum. SYSTEM_DESIGN.md 1.1 yang sudah Anda pindahkan ke root tidak perlu diganti kembali. Perbaikan instant navigation tahap 06 tetap digunakan.

## 3. Cara menerapkan pada project lokal

1. Pastikan Anda berada di branch dev:

```bash
cd /c/laragon/www/sorerunapp
git branch --show-current
git status --short
```

2. Hentikan frontend yang sedang berjalan dengan Ctrl+C. Simpan/bandingkan dahulu perubahan lokal jika Anda sudah mengedit sendiri salah satu dari delapan file tersebut.
3. Ekstrak ZIP ke folder sementara. Gabungkan **folder frontend dan docs di dalam paket** ke `C:\laragon\www\sorerunapp`. Ganti file dengan path yang sama; jangan menghapus seluruh folder frontend atau membuat project baru.
4. Dependency tidak berubah, sehingga tidak perlu npm ci/npm install. Jalankan pemeriksaan dari folder frontend:

```bash
cd /c/laragon/www/sorerunapp/frontend
npm run lint
npm run typecheck
npm run build
npm run test:e2e
```

Playwright menggunakan Chromium yang sudah Anda pasang pada tahap 06 dan menjalankan server test sendiri pada port 3100. Port tersebut harus kosong.

5. Jalankan frontend:

```bash
npm run dev
```

Buka `http://localhost:3000/login`, lalu reload agar browser memuat stylesheet baru. Backend dan layanan lokal tahap 06 tetap digunakan ketika menguji login/verifikasi nyata.

## 4. Pemeriksaan tampilan lokal

- Login dan registrasi: panel merek cokelat gelap, form putih, teks gelap, tombol oranye berlabel gelap.
- Hover tombol: bidang menjadi Warm Orange; hover tautan: teks menjadi Deep Brown.
- Tab keyboard: fokus Deep Orange pada bidang terang, Golden Yellow pada panel merek gelap.
- Form kosong/password salah: error berwarna danger dan isi form tetap dipertahankan sesuai alur sebelumnya.
- Beranda: panel pendukung krem dan status keanggotaan tetap memiliki label/ikon bermakna.
- Kartu: bidang cokelat gelap, identitas terang, QR hitam-putih. Periksa QR besar dan preview cetak.
- Navigasi aktif: bidang krem dengan teks gelap; periksa desktop dan mobile.
- Lebar 320 px: form/kartu tetap muat; dialog dapat ditutup Esc dan mengembalikan fokus.
- Ulangi login/logout dan verifikasi email dengan akun uji sesuai panduan tahap 06 untuk memastikan integrasi lokal Anda.

Pemeriksaan warna tidak menggantikan pengujian aksesibilitas penuh atau scan QR pada perangkat fisik.

## 5. Hasil verifikasi saat penyusunan

| Pemeriksaan | Hasil |
| --- | --- |
| ESLint | Lulus |
| TypeScript | Lulus |
| Build produksi Next 16.4.2 | Lulus |
| Playwright | 7 test browser lulus dengan API fixture sintetis |
| Pemeriksaan warna pada build produksi | 19 pasangan/state yang diperiksa memenuhi ambang masing-masing |
| Mobile/reflow | Login dan registrasi 320 px, ditambah suite mobile kartu/dialog yang sudah ada |
| Tinjauan visual | Login desktop dan registrasi mobile ditinjau |
| Pemeriksaan sumber | Tidak ada referensi palet forest/ivory lama pada CSS frontend; referensi variabel valid |

Contoh rasio warna solid yang dihitung dari computed style browser:

- Label CTA normal: 4,66:1.
- Label CTA hover: 6,71:1.
- Tautan pada panel putih: 5,29:1.
- Placeholder pada input putih: 6,50:1.
- Teks panel/kartu gelap: 15,04:1.
- Teks disabled pada muted background: 4,90:1.
- Fokus pada panel gelap: 9,06:1.
- QR pada layar dan mode cetak: 21:1.

Browser QA memakai data sintetis dan API fixture. Tidak ada klaim pengujian SMTP/MySQL nyata pada lingkungan penyusunan. Perubahan ini murni tampilan; suite backend tidak diubah.

## 6. Catat perubahan ke GitHub

Tinjau perubahan dan daftar staged sebelum commit agar isinya sesuai progres yang ingin dicatat:

```bash
cd /c/laragon/www/sorerunapp
git diff --check
git status --short
git add frontend/src docs/PANDUAN_PALET_SUNSET.md
git diff --cached --check
git diff --cached --stat
git status --short
```

Jika tahap 06 sebelumnya belum di-commit, folder frontend/src juga memuat source tahap 06. Tinjau daftar tersebut dan sesuaikan isi/pesan commit dengan keseluruhan perubahan yang benar-benar masuk. Jika SYSTEM_DESIGN.md versi 1.1 belum dicatat, sertakan dokumen itu dalam commit terkait dengan `git add SYSTEM_DESIGN.md`.

Untuk commit yang hanya berisi penerapan palet:

```bash
git commit -m "style: apply sunset palette from system design v1.1"
git push origin dev
```

Patch ini tidak melakukan commit, push, merge, atau deploy otomatis.
