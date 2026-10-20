# Frontend Stage 06

Implementasi UI anggota berdasarkan dokumen final sorerunapp. Petunjuk penerapan: [PANDUAN_TAHAP_06.md](PANDUAN_TAHAP_06.md).

## Lingkup dan route

| Route | Fungsi |
| --- | --- |
| `/` | Mengarahkan ke beranda |
| `/register` | Form registrasi, validasi, pengakuan privasi dan ketentuan |
| `/registration-received` | Respons registrasi generik tanpa auto-login |
| `/login` | Login sesi; return path dibatasi allowlist |
| `/verify-email` | Pembacaan tautan email dan konfirmasi verifikasi |
| `/privacy`, `/terms` | Ringkasan kebijakan sesuai PRD untuk development |
| `/beranda` | Status keanggotaan dan akses kartu |
| `/kartu` | Kartu API, QR, pembesaran dan cetak |
| `/profil` | Identitas/status baca saja dari API sesi |

Belum tersedia editor profil, foto, perubahan email/password, reset password, event, check-in, admin, atau laporan. Tidak ada data event/statistik fiktif untuk mengisi UI.

## Komposisi

- Route dan metadata di `frontend/src/app/`.
- Form, DTO Zod, validasi, service, navigasi aman di `features/auth/`.
- Kartu/QR/status di `features/membership/`; profil baca saja di `features/members/`.
- Primitive shadcn bergaya Radix, Input, Button, Card, Dialog di `components/ui/`.
- Axios dan normalisasi error terpusat di `lib/`; konfigurasi Query serta provider sesi tunggal.
- Token visual di `globals.css`; font lokal Plus Jakarta Sans beserta lisensi SIL OFL.
- React Hook Form + Zod memvalidasi UX; FormRequest/Action/Policy Laravel tetap menentukan hasil final.

Dependency tambahan memiliki fungsi langsung: form/validasi, primitive dialog/fokus, ikon, penggabungan kelas, encoder QR, serta test perilaku. Next 16/React 19 tidak diganti major. QR adalah hasil encoding credential API, bukan gambar dekoratif atau member ID.

## Sesi dan data privat

Browser memanggil Laravel secara langsung dengan credentials dan header X-XSRF-TOKEN setelah mengambil CSRF cookie. Tidak ada bearer auth, penyimpanan token di localStorage/sessionStorage, SSR data anggota, atau BFF penerus cookie.

Query kartu mencakup ID pengguna dan menerima AbortSignal. Logout/pergantian akun membatalkan request aktif dan membuang cache privat. BroadcastChannel hanya mengirim penanda perubahan sesi tanpa data pribadi. QR tidak ditampilkan dari data stale setelah request kartu ditolak. Logout gagal masuk ke layar pemulihan tanpa menampilkan kartu.

Guard/menu merupakan UX; API selalu memeriksa pemilik akun, account enabled, email verified, profile complete, serta status membership. Kondisi jaringan/419 tidak memicu pengulangan mutation otomatis. Status berubah saat halaman tertutup baru diketahui setelah pemeriksaan server berikutnya; gambar cetak/screenshot juga tidak dapat dicabut dari perangkat pengguna. Backend check-in harus selalu memvalidasi credential pada saat dipakai.

## Tautan verifikasi browser

1. Outbox membuat URL API bertanda tangan melalui `EmailVerificationUrl::forUser()` seperti tahap 05.
2. `forBrowser()` membungkusnya dalam fragmen `FRONTEND_URL/verify-email#verification=…`.
3. Fragmen tidak ikut dalam request HTTP ke Next. Halaman membacanya, memvalidasi origin/path/query, menghapusnya dari address bar, lalu menyimpannya hanya dalam memori tab.
4. Guest login melalui navigasi client dalam tab yang sama. URL rahasia tidak dimasukkan ke returnTo, query key, atau storage.
5. Pengguna menekan tombol verifikasi. Laravel tetap memeriksa session ownership, signature, expiry, email hash, dan auth_version; kemudian menjalankan aktivasi atomik yang sudah ada.
6. UI menghapus intent setelah hasil terkonfirmasi dan mengambil ulang sesi. Reload memori tab mengharuskan pengguna membuka email lagi.

Origin frontend divalidasi sebelum dipakai dalam email. CORS mengekspos Retry-After/X-Request-ID agar browser dapat membaca cooldown/request ID, tetap memakai allowed origin eksplisit dan credentials. Referrer-Policy no-referrer berlaku di frontend. Tidak ada pelonggaran middleware backend atau perubahan schema.

## Pengujian dan batasnya

- Unit: decoding QR nyata/quiet zone empat modul, validasi form/date/password, return allowlist, parser tautan, error sanitasi, serta pembatalan dan pembersihan cache.
- Browser: registrasi sampai kartu, intent melewati login, logout gagal, akses kartu dicabut, cooldown server, error login/redirect, dan reflow 320 px. Dialog diuji Esc dan pengembalian fokus; test menangkap error hydration/JavaScript.
- Fixture API hanya berada di `tests/browser/`; tidak ada fallback data palsu pada aplikasi.
- Screenshot/video/trace browser dimatikan pada test agar artefak credential tidak tersimpan otomatis.
- Suite PHPUnit tahap 01–06 diperluas untuk pembungkus URL; pemeriksaan runtime PHP/MySQL dijalankan pada Laragon, bukan diklaim lulus dari lingkungan tanpa PHP.
- Postman tahap 05 diperbarui untuk membaca URL API langsung ataupun pembungkus fragmen baru. Simulasi script bukan bukti koneksi SMTP/API nyata.

Pengujian saat penyusunan memakai Node 24.19.0, Next 16.4.2, React 19.3.0 dan Chromium 153. Tidak ada commit/push/deploy otomatis. Ikuti panduan untuk mencatat hasil lokal dan push ke dev setelah verifikasi.

## Sebelum go-live

Selesaikan modul tersisa menurut PRD/arsitektur, tinjau teks kebijakan dan kanal kontak nyata, sinkronkan versi kebijakan frontend/backend, jalankan uji integrasi lengkap, serta konfigurasi domain/HTTPS/cookie/CORS/SMTP produksi. Variabel NEXT_PUBLIC dibekukan saat build sehingga perubahan origin/versi harus diikuti build ulang. Foto/gender tidak ditambahkan sebagai informasi palsu pada profil sesi yang belum menyediakan field tersebut.
