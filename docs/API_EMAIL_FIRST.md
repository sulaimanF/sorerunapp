# Kontrak autentikasi email-first — final, 24 Oktober 2026

Menggantikan aturan login-before-verification pada panduan sebelumnya. Base API http://localhost:8000. Envelope error.code/message/fields dan meta.request_id tetap. Tidak ada perubahan schema atau nomor/QR existing.

| Endpoint | Akses/input | Hasil |
| --- | --- | --- |
| GET /sanctum/csrf-cookie | Stateful guest/browser, credentials | 204, sesi tamu + XSRF |
| POST /api/v1/auth/register | CSRF, registrasi existing | 201 registration_received, tidak login |
| POST /api/v1/auth/login | CSRF, email/password | 200 jika enabled dan verified; 403 EMAIL_NOT_VERIFIED setelah password benar; 401 INVALID_CREDENTIALS jika salah/tidak dikenal/blocked |
| GET /api/v1/auth/me | Sesi current, enabled, auth_version sesuai, verified | 200 identitas; 401 tamu/expired; 403 EMAIL_NOT_VERIFIED dan invalidasi sesi lama unverified |
| GET /api/v1/auth/email-verification/{id}/{hash}?expires=…&v=…&signature=… | Publik signed + expiry | 200 verification_link_ready; tidak membaca profil atau memutasi domain |
| POST URL signed yang sama | Publik, CSRF, JSON {} | 200 email_verified / already_verified / email_verified_profile_incomplete; tanpa auto-login atau pergantian identitas sesi verified lain |
| POST /api/v1/auth/email-verification/resend | Publik stateful + CSRF; JSON email; tanpa query/field tambahan | 202 verification_requested + pesan generik, Retry-After 60 |
| GET /api/v1/me/membership/card | Sesi verified, enabled, membership active, profil lengkap, credential valid | 200 kartu privat existing, no-store |

Subject verifikasi berasal dari signed URL tervalidasi, bukan body/session. POST mengunci membership → user, memeriksa email hash/auth_version/status lalu menerbitkan aktivasi/nomor/credential/audit/outbox dalam satu transaksi. Public response hanya result dan meta, tanpa email/nama/nomor/QR. Actor audit/issued_by untuk proof publik NULL, target membership tetap tercatat. Jika ada sesi lama unverified, sesi itu dihentikan sebelum proof agar keberhasilan verifikasi tidak menghidupkannya sebagai sesi login.

GET hanya readiness signature/expiry, bukan jaminan subject dapat diaktifkan. POST stale email/auth_version menghasilkan 403 VERIFICATION_LINK_STALE; invalid/expired signature 403 INVALID_VERIFICATION_LINK; blocked 403 ACCOUNT_BLOCKED; suspended/ended 409 MEMBERSHIP_NOT_ACTIVATABLE; active tetapi email belum verified 409 MEMBERSHIP_STATE_CONFLICT. Kegagalan alokasi/credential dapat menghasilkan 503 dan rollback. Repeated/parallel confirmation tidak menerbitkan QR/nomor kedua.

POST confirm/resend: 419 tanpa CSRF, 415 non-JSON, 413 body >4 KiB, 400 JSON malformed/non-object/depth >8, 422 extra fields atau email invalid. Konfirmasi hanya menerima {}. Signed query dipertahankan persis; user_id/role/actor/version tidak diterima dari body.

Resend hanya menulis outbox untuk enabled + unverified + pending_verification. Email unknown, verified, blocked, ineligible, dan recipient cooldown seluruhnya menerima 202 dengan data dan Retry-After yang sama. Cooldown penerima 60 detik. Limiter seragam tanpa lookup akun: 5 request/menit/IP, 20/jam/IP, 5/jam/email normalized; batas 429 + Retry-After. Penolakan validasi setelah limiter dapat memakai jatah. Tidak menjanjikan email telah terkirim atau constant-time SMTP/DB.

Frontend meminta klik konfirmasi eksplisit, bukan POST otomatis pada render. URL proof berada dalam fragment lalu dipindah ke memori dan dibersihkan dari address bar. Tidak disimpan pada localStorage/sessionStorage/router query/query key/log. Reload membutuhkan membuka ulang email. Sukses menawarkan login; incomplete profile memakai returnTo internal /profil. UX login awal dan countdown 429 dipertahankan.
