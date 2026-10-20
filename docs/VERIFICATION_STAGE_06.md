# Hasil Pemeriksaan Tahap 06

Tanggal: 20 Oktober 2026. Baseline: `dev`, commit `0aeb7e04ac36c67cfe1acfa6fe68681d015622c8`.

## Pemeriksaan yang dijalankan

| Pemeriksaan | Hasil |
| --- | --- |
| `npm run lint` | Lulus |
| `npm run typecheck` | Lulus |
| `npm run test` | 10 test, 10 lulus |
| `npm run test:e2e` | 7 test, 7 lulus; Chromium 153, API fixture sintetis |
| `npm run build` | Lulus; Next 16.4.2/Turbopack, route publik dan shell privat berhasil dibangun |
| `npm audit --omit=dev` | 0 vulnerability dependency produksi pada waktu pemeriksaan |
| `node postman/verify-scripts.cjs` | Simulasi: 17 request, 29 script, 98 assertion lulus |
| Parsing PHP statis | 6 file PHP baru/berubah tidak memiliki syntax-tree error |
| XML PHPUnit dan sintaks script shell | Valid |
| `git diff --check` | Lulus |
| Tinjauan visual | Halaman login desktop dan registrasi mobile ditinjau dari build produksi |

Build memakai API origin localhost:8000 serta versi kebijakan dev-v1. Runtime penyusunan Node 24.19.0; lockfile disertakan. Pemeriksaan browser memakai binary Chromium sementara di luar dependency project; pengguna dapat memasang browser melalui Playwright seperti dalam panduan. File binary tersebut tidak disertakan dalam paket.

Test browser mencakup happy path registrasi/login/verifikasi/kartu/logout, intent verifikasi saat berpindah ke login, logout gagal, pencabutan akses kartu, cooldown kirim ulang, penolakan external return URL/error login, dan viewport 320 px. Test juga memeriksa tidak ada error JavaScript/hydration yang tertangkap, QR tidak tersimpan di browser storage, serta dialog mengembalikan fokus setelah Esc.

## Belum dijalankan di lingkungan penyusunan

- PHPUnit/Pint menggunakan PHP 8.4.
- Integrasi MySQL 8.4, termasuk suite concurrency yang sudah ada.
- Integrasi browser langsung ke Laravel serta pengiriman SMTP Mailpit nyata.
- Pengujian lintas browser/perangkat fisik dan scan kamera petugas.
- Operasi Git push/merge maupun deploy.

PHP/MySQL/SMTP tidak tersedia di lingkungan ini. Jalankan pemeriksaan tersebut di Laragon sesuai PANDUAN_TAHAP_06.md sebelum menyatakan tahap ini lulus integrasi. Simulasi Postman dan fixture browser tidak merupakan bukti koneksi backend/SMTP nyata. Tidak ada hasil tes produksi yang diklaim.
