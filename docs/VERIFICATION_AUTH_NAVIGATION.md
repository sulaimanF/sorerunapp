# Hasil pemeriksaan navigasi dan tampilan auth

Tanggal: 25 Oktober 2026. Scope: login selalu ke beranda, URL login bersih, pengalihan root sebelum render, dan susunan tautan tiga halaman sesuai preview yang disetujui.

| Pemeriksaan | Hasil |
| --- | --- |
| ESLint frontend | Lulus |
| TypeScript --noEmit | Lulus setelah tipe route lama dibersihkan |
| Unit frontend | 9 test lulus; test helper return-path lama dihapus karena fitur tersebut sudah dihilangkan |
| Browser Chromium Playwright | 22/22 lulus dengan API fixture sintetis |
| Next.js production build | Lulus; root ditangani redirects, bukan page yang merender redirect |
| Installer shared config | Lulus pada konfigurasi tanpa/dengan redirect existing; header/redirect lain/cache dipertahankan, idempotent, root custom ditolak sebelum shared files ditulis |
| git diff --check | Lulus |

Regressi browser mencakup root 307 ke /login; query login lama untuk profil/kartu/eksternal/nested diabaikan dan dibersihkan; login selalu /beranda; tamu pada halaman privat menuju login bersih; sesi valid berpindah menu tanpa login ulang; logout dari profil; sesi berakhir; banner sesi aktif/manual lanjut; verifikasi publik termasuk profil incomplete tanpa auto-login; susunan footer dan jarak minimum pada desktop/320 px; serta alur email-first/kartu/logout/gagal logout/cooldown yang sudah ada.

Tidak menonaktifkan cache, validasi, atau CSRF untuk meluluskan test. Styling auth memakai CSS biasa dengan selector berprefiks src-auth- dan mewarisi warna/typography existing. Sesuai revisi pemilik produk, seluruh aturan tersebut sekarang berada di globals.css; file auth-layout.css dan ketiga import-nya dihapus. Cache .next lama sempat merujuk root page yang sudah dipindahkan; cache tersebut dipindahkan dan tipe route dibangun ulang sebelum pemeriksaan akhir.

Paket tidak menimpa globals.css lengkap; script hanya menambahkan blok styling auth dan mempertahankan isi sebelumnya. Paket tidak mengganti services.ts, query-client.ts, session-provider.tsx, backend, migration, manifest, atau lockfile. Salinan lokal tahap06 pada lingkungan penyusunan dipadankan dengan prerequisite frontend email-first yang telah disetujui agar kontrak API fixture sesuai; file prerequisite itu tidak dikirim ulang dalam paket ini. Source profil tahap07 lokal pengguna belum tersedia, sehingga pengujian integrasi tambahan yang ada pada source lokal tetap perlu dijalankan.

Browser tests memakai API fixture dan bukan bukti integrasi ke Laravel/MySQL/SMTP nyata. PHP/MySQL tidak dijalankan ulang pada perubahan frontend ini. Gunakan Laravel lokal yang sudah lulus test email-first dan profil untuk smoke test sesuai panduan. Tidak ada commit, push, deploy, reset database, atau pengiriman email nyata yang dijalankan otomatis.

Dokumen acuan tetap final: PRD 1.2, ARCHITECTURE 1.2, SYSTEM_DESIGN 1.3, AGENTS 1.2. Palet Sunset dan aturan verifikasi email sebelum login dipertahankan.

## Pemeriksaan setelah styling dipusatkan di globals.css

Revisi lanjutan pada 25 Oktober 2026 sesuai instruksi pemilik produk. Aturan CSS dan class tetap sama; hanya lokasi stylesheet dan import yang berubah.

- ESLint dan TypeScript --noEmit lulus.
- Satu browser test layout existing lulus untuk ketiga halaman pada desktop 1280 px dan mobile 320 px, memakai API fixture sintetis. Seluruh 22 browser test pada tabel awal tidak dijalankan ulang untuk pemindahan CSS ini.
- Next.js production build lulus.
- Patch merger mempertahankan tambahan CSS/token lokal, aman dijalankan ulang, dan menolak penghapusan auth-layout.css yang sudah dimodifikasi sebelum source ditulis.
- git diff --check lulus.

Cache .next lokal lama sempat memuat stylesheet sebelumnya sehingga pemeriksaan pertama gagal; setelah cache generated tersebut dipindahkan dan dibangun ulang, test layout lulus. Tidak ada aturan CSS atau assertion yang dilonggarkan. Ikuti langkah pembersihan cache di panduan penerapan. Tidak ada perubahan backend atau pengujian ulang PHP/MySQL.
