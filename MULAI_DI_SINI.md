# sorerunapp — navigasi dan tampilan auth

Ekstrak isi paket ke root C:\laragon\www\sorerunapp setelah menghentikan npm run dev. Jalankan dari root Git Bash:

```bash
node scripts/apply-auth-navigation.cjs
node scripts/centralize-auth-css.cjs
```

Lanjutkan docs/PANDUAN_AUTH_NAVIGATION.md untuk membersihkan tipe route lama, menjalankan pemeriksaan, dan uji manual.

Hasil: root / → /login; semua login berhasil → /beranda; logout/sesi habis → /login tanpa returnTo. Tiga halaman auth memakai susunan tautan sesuai preview yang disetujui. Banner sesi aktif tetap manual lanjut ke beranda. Verifikasi email wajib sebelum login tetap berlaku.

Paket melanjutkan email-first yang sudah lulus pengujian Anda. Styling auth sekarang terpusat di globals.css. Script kedua menambahkan blok CSS tanpa menimpa isi globals.css existing, menghapus import dan file auth-layout.css lama, serta membuat backup. Tidak mengganti backend, palet, layanan API, session provider, database, atau dependency. next.config.ts dipatch terbatas oleh script, menjaga konfigurasi existing. Root page yang hanya berisi redirect lama dihapus setelah backup. Tidak ada commit/push/deploy otomatis.
