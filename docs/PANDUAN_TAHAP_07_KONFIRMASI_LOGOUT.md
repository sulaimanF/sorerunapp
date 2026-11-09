# Tahap 7 — revisi konfirmasi logout

Tanggal: 25 Oktober 2026. Tambahan pada tahap 7 yang sudah berjalan.

## Hasil yang diterapkan

Tombol Keluar dari akun membuka dialog:

- Judul: Keluar dari akun?
- Pesan: Apakah Anda ingin logout dari sorerunapp?
- Tidak: menutup dialog, mempertahankan sesi, dan mengembalikan fokus ke tombol keluar.
- Ya, Keluar: memanggil logout existing. Setelah berhasil, pengguna menuju `/login` tanpa query.

Escape atau menutup dialog membatalkan. Fokus awal berada pada Tidak, dan navigasi Tab tetap di dalam dialog. Tombol memakai komponen dan token Sunset existing; styling tambahan terpusat di globals.css. Dialog responsif pada lebar 320 px.

Membuka atau membatalkan dialog tidak mengirim request logout dan tidak membersihkan data privat. Sesudah konfirmasi, loading Mengakhiri sesi… dan pemulihan jika hasil logout belum terkonfirmasi tetap memakai mekanisme sesi existing. Penolakan autentikasi/sesi berakhir dari server tetap ditangani guard secara otomatis.

## 1. Terapkan berurutan

Hentikan seluruh `npm run dev` frontend terlebih dahulu dengan Ctrl+C. Ekstrak ZIP ke `C:\laragon\www\sorerunapp`, mengikuti struktur foldernya. Paket hanya membawa komponen baru, empat test tambahan, script penerapan, dan panduan ini.

Dari Git Bash di root:

```bash
cd /c/laragon/www/sorerunapp
node scripts/apply-logout-confirmation-stage07.cjs
```

Script melakukan patch terbatas pada source lokal Anda:

1. Mengganti tombol logout langsung di member-shell dengan komponen LogoutConfirmation.
2. Menambahkan blok styling tombol dialog di akhir globals.css, mempertahankan isi/palet existing.
3. Menambahkan langkah Ya, Keluar setelah klik logout pada pernyataan test browser existing yang dikenali, termasuk test profil tahap 7 bila tersedia. Seluruh assertion dan fixture profil lokal dipertahankan.
4. Menambahkan catatan revisi tahap 7 ke PRD.md, ARCHITECTURE.md, dan SYSTEM_DESIGN.md. Status final tetap.

Backup source disimpan di `.auth-navigation-backup/stage07-logout-.../`, yang diabaikan Git. Script aman dijalankan ulang tanpa menambahkan dialog, CSS, atau langkah konfirmasi berulang. Jika pola tombol atau blok CSS sudah dimodifikasi dan tidak dikenali, script berhenti sebelum menulis source; baca pesan tersebut sebelum melanjutkan.

Editor profil tahap 7, layanan profil, session provider, backend, database, dependency, serta konfigurasi framework tetap memakai source lokal Anda. Tidak perlu menjalankan migration atau npm install.

## 2. Periksa frontend

Jalankan berurutan dari frontend:

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

`.next` adalah cache/build generated yang dibuat ulang oleh Next.js. Jangan menyalakan frontend manual saat E2E; Playwright menjalankan server sendiri sesuai konfigurasi proyek. Jika satu perintah gagal, periksa hasilnya sebelum melanjutkan.

Ada empat test browser tambahan. Jika sebelumnya proyek Anda memiliki 31 test browser, setelah revisi ini jumlahnya menjadi 35. Sembilan test profil tahap 7 existing tetap dijalankan dalam suite lokal Anda. Test yang tadinya mengharapkan logout langsung sekarang perlu mengonfirmasi Ya, Keluar dahulu; script menyesuaikan pola `await ...getByRole("button", { name: "Keluar dari akun" }).click()` dan alias locator yang dikenali. Penyesuaian hanya dilakukan pada test positif logout, sementara suite baru menguji pembatalan secara terpisah.

## 3. Uji manual

Setelah pemeriksaan selesai, jalankan `npm run dev` dan login dengan akun uji yang sudah terverifikasi.

| Langkah | Hasil |
| --- | --- |
| Klik ikon keluar di beranda/profil/kartu | Dialog konfirmasi terbuka; sesi belum diakhiri |
| Klik Tidak | Dialog tertutup; tetap pada halaman dan sesi yang sama |
| Tekan Escape | Dialog tertutup; fokus kembali ke tombol keluar |
| Buka ulang lalu klik Ya, Keluar | Proses mengakhiri sesi; setelah berhasil menuju /login |
| Coba logout dengan koneksi gagal | Pemulihan hasil logout belum terkonfirmasi existing tetap muncul |
| Buka pada lebar 320 px | Dialog muat dan kedua tombol mudah ditekan |

Sesudah konfirmasi logout, data privat tetap dibersihkan mengikuti session provider existing. Tidak ada logout sukses palsu ketika jaringan gagal.

## 4. Hasil pemeriksaan penyusunan

| Pemeriksaan | Hasil |
| --- | --- |
| ESLint frontend | Lulus |
| TypeScript --noEmit | Lulus |
| Browser Chromium dengan API fixture sintetis | 26/26 lulus, termasuk empat test konfirmasi logout serta regresi logout gagal |
| Build produksi Next.js | Lulus |
| Script penerapan | Lulus: idempotent, patch contoh test profil multibaris, mempertahankan editor/token lokal, menolak pola tombol custom sebelum source ditulis |
| git diff --check | Lulus |

Sembilan test profil tahap 7 milik proyek lokal Anda tidak tersedia di lingkungan penyusunan dan tetap perlu dijalankan di komputer Anda. Source editor profil tersebut tidak diganti atau diturunkan ke tahap sebelumnya. Uji browser memakai API fixture, bukan integrasi Laravel/MySQL/SMTP nyata. Tidak ada perubahan backend, pengujian PHP ulang, commit, push, atau deploy otomatis.
