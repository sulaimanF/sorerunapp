# Pemeriksaan paket unduhan email-first

Paket disiapkan ulang pada 25 Oktober 2026 karena file unduhan sebelumnya tidak lagi tersedia. Alur bisnis revisi 24 Oktober 2026 dipertahankan. Empat dokumen final yang telah disimpan sebelumnya dipulihkan tanpa mengganti keputusan produk.

| Pemeriksaan ulang | Hasil |
| --- | --- |
| ESLint | Lulus |
| TypeScript --noEmit | Lulus |
| Unit frontend | 10/10 lulus |
| Playwright Chromium | 9/9 lulus, API fixture sintetis |
| Next.js build | Lulus, termasuk /resend-verification |
| Simulasi script Postman | 15 request, 29 assertion lulus; tidak menghubungi API nyata |
| Parser source PHP | 19 file implementasi/test/shared parse tanpa error |
| Sintaks Node/Bash, JSON collection | Valid |
| Installer shared files | Diterapkan pada baseline yang tersedia; pemanggilan kedua tidak mengubah file |
| git diff --check | Lulus |

Browser mencakup unverified login ditolak, verifikasi publik eksplisit tanpa auto-login, pembukaan ulang link, kartu/QR/logout, cache privat ketika verification dicabut, cooldown dan resend unknown-email generik, input login saat error, return URL eksternal ditolak, serta reflow 320 px. Tidak ada error JavaScript/hydration yang tertangkap test. Cache build sementara lama dipindahkan sebelum pengujian; konfigurasi aplikasi tidak dilonggarkan.

PHP/Composer/MySQL/SMTP tidak tersedia dalam lingkungan penyusunan. PHPUnit/Pint, row-lock/concurrency MySQL nyata, pengiriman Mailpit, dan integrasi langsung ke backend belum dijalankan. Parsing source dan API fixture tidak menggantikan pemeriksaan tersebut. Jalankan script backend dan Postman sesuai panduan di Laragon.

Source workspace yang tersedia adalah baseline tahap06. Action/policy/helper profil tahap07 dipatch terbatas jika dikenali, tanpa menyalin routes/provider baseline di atas file yang lebih baru; integrasi source profil lokal tahap07 perlu diperiksa di Laragon. Tidak ada migration/dependency baru, reset data, commit/push, deployment, atau pengiriman email nyata otomatis.
