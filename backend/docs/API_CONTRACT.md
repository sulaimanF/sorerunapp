> **Kontrak autentikasi terbaru:** lihat ../../docs/API_EMAIL_FIRST.md. Ketentuan berbeda pada catatan tahap sebelumnya di bawah telah digantikan.

# API contract — sorerunapp

## Registrasi member — tahap 03

Base API: `/api/v1`. Endpoint: `POST /auth/register`. Transport browser: Sanctum stateful session, JSON, credentials, CSRF. Tidak membutuhkan login. Request harus berasal dari origin frontend yang dikonfigurasi dan dikenali Sanctum. CORS tetap memakai origin eksplisit; tidak memakai wildcard bersama credentials.

Sebelum POST, browser mengambil `/sanctum/csrf-cookie` dengan credentials, lalu mengirim cookie sesi dan header `X-XSRF-TOKEN` dari cookie XSRF-TOKEN yang sudah di-URL-decode. Axios terpusat proyek menggunakan withCredentials/withXSRFToken. Cookie/token tidak disimpan ke localStorage.

Request tanpa origin/referer frontend yang dikenali atau tanpa sesi ditolak 419. Ini menutup jalur stateless pada endpoint browser ini. Middleware CSRF Sanctum tetap aktif; autentikasi bearer bukan pengganti sesi/CSRF pada registrasi browser.

### Request

`Content-Type: application/json`, `Accept: application/json`. Body harus objek JSON, maksimal 16.384 byte dan kedalaman parse 16. Batas aplikasi ini bukan pengganti batas request pada web server. Laravel dapat memproses input melalui middleware global sebelum validasi route.

| Field | Ketentuan |
| --- | --- |
| full_name | Wajib, string 2–150 karakter; spasi luar dihapus |
| email | Wajib, string email valid maksimum 254 karakter; trim dan lowercase; tidak menghapus titik/plus alias |
| password | Wajib, string minimal 12 karakter, maksimum 72 byte untuk bcrypt; tidak mengandung null byte; spasi dipertahankan |
| password_confirmation | Wajib, sama dengan password |
| birth_date | Wajib, string tanggal kalender YYYY-MM-DD, tidak di masa depan menurut UTC; tidak ada minimum usia |
| phone | Wajib, string; normalisasi format, kemudian + diikuti 7–15 digit, digit pertama bukan nol |
| city | Wajib, string maksimum 100 karakter; spasi luar dihapus |
| gender | Opsional, null atau string maksimum 32 karakter |
| privacy_notice_accepted | Wajib true; formulir harus menyediakan teks yang diakui |
| privacy_notice_version | Wajib, sama dengan REGISTRATION_PRIVACY_VERSION pada server |
| terms_accepted | Wajib true |
| terms_version | Wajib, sama dengan REGISTRATION_TERMS_VERSION pada server |

Nomor telepon bukan unique identifier dan tidak diverifikasi melalui SMS. Separator spasi, titik, kurung, dan tanda hubung dihapus. Awalan 0 lokal menjadi +62; 62 menjadi +62; 00 menjadi +; nomor internasional dengan + dipertahankan. Contoh: `0812-3456-7890` → `+6281234567890`. Validasi bentuk tidak membuktikan nomor benar-benar aktif.

Nama/telepon yang sama pada akun berbeda diperbolehkan. Foto tidak dikirim pada endpoint JSON ini; upload foto opsional dilakukan melalui fitur profil berikutnya. Field selain daftar di atas ditolak 422 pada `_request`, termasuk role, status, user_id, actor, waktu server, atau data credential. Query string tambahan juga dapat masuk pemeriksaan input dan tidak digunakan sebagai tempat metadata bebas.

Validasi tidak memakai unique email yang membocorkan keberadaan akun. Payload invalid tetap 422 baik untuk email baru maupun email existing. Pencocokan email dan unique constraint memakai aturan database yang sudah dipasang.

### Receipt

Payload valid untuk email baru atau existing menghasilkan receipt generik dengan status **201**:

```json
{
  "data": {
    "result": "registration_received",
    "message": "Permintaan registrasi diterima. Jika alamat email dapat digunakan, instruksi verifikasi akan diproses."
  },
  "meta": { "request_id": "d6578996-20ac-4d4e-ab72-ef8d187fe122" }
}
```

201 dipakai secara seragam agar respons tidak membedakan email existing. Receipt tidak menyatakan akun baru pasti dibuat, email sudah terkirim, atau membership telah aktif. Metadata request ID dibuat server, juga dikirim sebagai header X-Request-ID; respons memakai Cache-Control: no-store. Tidak ada ID akun, profil, email, QR, member number, atau status akun dalam receipt.

Email existing tidak ditimpa, tidak dibuka blokirnya, tidak diaktifkan ulang, dan tidak menghasilkan delivery tambahan melalui endpoint ini. Pemulihan akun/resend merupakan alur terpisah pada tahap berikutnya. Hash password tetap dikerjakan pada kedua jalur untuk mengurangi perbedaan waktu sederhana; tidak ada klaim respons constant-time.

### Transaksi dan side effect

| Data | Hasil untuk email baru |
| --- | --- |
| users | enabled, email_verified_at NULL; password di-hash |
| member_profiles | Profil tervalidasi terkait user |
| memberships | pending_verification; member_number dan joined_at NULL |
| user_roles | Hanya role member dari server; assigned_by NULL, waktu server |
| policy_acknowledgements | privacy_notice dan terms, versi tervalidasi, waktu UTC server |
| audit_logs | member.registered, target membership, actor NULL karena belum login, status pending dan request ID; tanpa PII |
| notification_deliveries | type email_verification, pending, attempt_count 0, available_at waktu server; dedupe per registrasi user |

Seluruh data di atas atomik. Tidak ada pemanggilan provider/email/network dalam transaksi. Bila penulisan outbox gagal, semua write registrasi di-rollback. DB::transaction memakai maksimal 3 percobaan untuk deadlock. Unique constraint users.email menangani request paralel; hanya konflik index email yang dikenali boleh menghasilkan receipt generik, kegagalan constraint lain tetap error.

Sequence nomor member tidak berubah dan tidak ada member_credentials yang dibuat pada tahap ini. Event Registered bawaan tidak dikirim agar tidak memicu email di luar outbox. Consumer outbox, enqueue sesudah commit/sweep, retry, verifikasi, dan aktivasi dijelaskan pada bagian tahap 05 di bawah. Receipt registrasi sendiri tidak menjamin aktivasi atau pengiriman sudah selesai.

### Error dan pembatasan

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Periksa kembali data yang Anda isi.",
    "fields": { "email": ["Masukkan alamat email yang valid."] }
  },
  "meta": { "request_id": "d6578996-20ac-4d4e-ab72-ef8d187fe122" }
}
```

`error.fields` selalu objek JSON, kosong `{}` jika tidak ada kesalahan field. API tidak mengembalikan SQL, stack trace, path internal, atau exception message mentah, termasuk ketika APP_DEBUG aktif. Pencatatan QueryException hanya menyimpan SQLSTATE/driver code, bukan SQL/bindings profil. Request ID masuk konteks log bila middleware route sudah berjalan.

| HTTP | Code | Makna |
| --- | --- | --- |
| 400 | INVALID_JSON | JSON rusak/nonobjek/terlalu dalam |
| 404 | NOT_FOUND | Route/data tidak ditemukan |
| 405 | METHOD_NOT_ALLOWED | Metode tidak didukung |
| 413 | PAYLOAD_TOO_LARGE | Body melebihi batas |
| 415 | UNSUPPORTED_MEDIA_TYPE | Request bukan JSON |
| 419 | CSRF_MISMATCH | Sesi/origin/token tidak memenuhi pemeriksaan browser |
| 422 | VALIDATION_FAILED | Input/pengakuan/versi kebijakan tidak valid |
| 429 | RATE_LIMITED | Batas IP atau email tercapai; header Retry-After dipertahankan |
| 503 | REGISTRATION_UNAVAILABLE | Role member atau versi kebijakan belum dikonfigurasi |
| 503 | SERVICE_UNAVAILABLE | Kegagalan DB sementara/konfigurasi koneksi yang dikenali |
| 500 | SERVER_ERROR | Kegagalan lain; respons disanitasi |

Pembatas awal 5 percobaan/menit/IP dan 3 percobaan/jam/email ternormalisasi; key menyimpan hash, bukan email/IP mentah. Berlaku untuk percobaan yang mencapai middleware throttle, bukan hanya akun yang berhasil dibuat. Pemeriksaan middleware bisa menolak sebelum validasi payload sehingga urutan error tidak dijanjikan. Jangan retry mutation secara otomatis setelah 419, 429, atau timeout; periksa hasil dan perbaiki sesi/input atau tunggu Retry-After.

Runtime aplikasi memakai cache database yang sudah disiapkan; pengujian memakai array cache. Saat deploy di belakang proxy, trusted proxy harus ditetapkan sesuai topologi agar alamat IP limiter benar; tidak mempercayai X-Forwarded-For sembarang client.

### Pemetaan dokumen final dan status

- PRD FR-01: akun/profil/membership pending valid tanpa data parsial; BR-01: maksimal satu membership; BR-02/BR-03: nomor/QR menunggu aktivasi.
- PRD bagian 7 dan keputusan 4.2/19: telepon/domisili wajib, foto opsional, tanpa approval admin/minimum usia; pengakuan privasi dan ketentuan berversi.
- PRD NFR-01/02/04/05: CSRF, rate limit, hashing, respons publik generik, transaksi atomik.
- ARCHITECTURE bagian 12/14: envelope API dan fondasi outbox. Pengiriman email diproses oleh outbox tahap 05.

Route `/api/user` dipertahankan sebagai alias legacy. Sejak tahap 04, alias ini mengikuti guard dan whitelist respons /api/v1/auth/me pada bagian sesi di bawah. Tidak ada frontend UI baru pada tahap 03/04.

Referensi implementasi framework: [Laravel 13 validation](https://laravel.com/docs/13.x/validation), [Sanctum SPA authentication](https://laravel.com/docs/13.x/sanctum#spa-authentication), [CSRF](https://laravel.com/docs/13.x/csrf), [database transactions](https://laravel.com/docs/13.x/database#database-transactions), dan [Symfony Process](https://symfony.com/doc/current/components/process.html). Keputusan bisnis tetap mengikuti dokumen final proyek.

## Sesi browser — tahap 04

Requirement: PRD FR-03 (login/logout), NFR-01/02/04, serta ARCHITECTURE bagian 7, 8.2/8.3, dan 12. Reset password dalam FR-03 belum termasuk tahap ini. Email verification dan delivery dalam FR-02/FR-16 dilanjutkan pada tahap 05 di bawah.

### POST /api/v1/auth/login

Publik, stateful browser session, JSON, CSRF, dan throttle:login. Hanya menerima dua field:

```json
{
  "email": "uji-registrasi-03@example.test",
  "password": "Test-only-password-123"
}
```

Email wajib string email valid maksimal 254 karakter, trim+lowercase tanpa mengubah alias titik/plus. Password wajib string tidak kosong, maksimal 72 byte, tanpa null byte; spasi dipertahankan. Minimum 12 karakter diterapkan saat menetapkan password melalui registrasi, bukan sebagai pengganti pemeriksaan credential pada login. Field lain termasuk remember, redirect, user_id, role, status, dan auth_version ditolak 422. Server tidak menyediakan remember-me atau redirect berdasarkan URL client pada tahap ini.

Body login harus objek JSON maksimal 16.384 byte/depth 16, mengikuti pola error 400/413/415 registrasi. Browser mengambil CSRF cookie sebelum POST dan mengirim cookie sesi + X-XSRF-TOKEN. Stateless request tidak dapat mengganti pemeriksaan browser dengan header bearer.

Email tidak ditemukan, password salah, dan akun blocked memakai **401 INVALID_CREDENTIALS** dengan pesan yang sama: `Email atau password tidak sesuai.` Tidak ada informasi membership/status akun dalam error login. Pemeriksaan dummy hash pada email yang tidak dikenal mengurangi perbedaan kerja hashing sederhana; tidak menjanjikan constant-time atau perlindungan enumerasi timing secara mutlak.

Credential diperiksa sebelum row lock yang singkat. Action membaca ulang user di dalam transaksi, memeriksa enabled serta memastikan hash tidak berubah sejak pemeriksaan credential, lalu memperbarui cost hash jika diperlukan. Tidak ada request network/email dalam lock. Session dibuat setelah pemeriksaan sukses, session ID dan token CSRF diregenerasi, lalu auth_version user disimpan server dalam payload sesi. Pembaruan cost hash bukan fitur ganti password; jangan mengandalkannya sebagai mekanisme pencabutan sesi.

Login sukses **200**, dengan shape yang sama seperti GET /auth/me:

```json
{
  "data": {
    "user": {
      "id": 42,
      "full_name": "Anggota Uji",
      "email": "uji-registrasi-03@example.test",
      "email_verified": true
    },
    "roles": ["member"],
    "profile_complete": true,
    "membership": {
      "status": "pending_verification",
      "member_number": null,
      "joined_at": null
    }
  },
  "meta": { "request_id": "d6578996-20ac-4d4e-ab72-ef8d187fe122" }
}
```

Data hanya tentang akun sendiri. Resource tidak mengembalikan password/hash, remember token, auth_version, ID sesi, QR token, foto path, tanggal lahir, atau telepon. Roles dibaca dari database dan diurutkan. full_name memakai profil, atau nama akun bila profil belum ada. membership dapat null; profile_complete dihitung dari model. joined_at memakai ISO 8601 UTC jika ada. Respons memakai Cache-Control:no-store dan X-Request-ID.

Member unverified tidak dapat login: password benar menghasilkan 403 EMAIL_NOT_VERIFIED tanpa sesi authenticated. Membership pending/active/suspended/ended tidak menggantikan status akun enabled/blocked. Login tidak mengubah status membership, verifikasi email, nomor, QR, atau outbox. Akun enabled tanpa membership juga boleh membaca identitas sendiri; endpoint bisnis berikutnya tetap memeriksa eligibility masing-masing.

Login berhasil pada sesi yang sudah terautentikasi mengganti akun dan menghapus isi sesi sebelumnya. Login gagal tidak mengganti identitas sesi existing. Frontend harus membersihkan cache privat sebelum menampilkan akun baru; implementasi frontend belum termasuk paket ini.

Throttle awal: **20 percobaan/menit/IP dan 5 percobaan/menit/email ternormalisasi**, mencakup percobaan sukses/gagal yang mencapai middleware. Batas memakai hash untuk key dan cache database aplikasi. Tidak menulis status blocked permanen. 429 memakai envelope RATE_LIMITED dan Retry-After; throttling tidak direset otomatis hanya karena satu login berhasil. Proxy/IP dikonfigurasi sesuai topologi deployment, bukan mempercayai forwarded header sembarang client.

### GET /api/v1/auth/me

Middleware: RequireBrowserSession → auth:sanctum → EnsureCurrentBrowserSession. Urutan eksekusi final tetap mengikuti middleware priority Laravel. Gunakan credentials dan origin frontend yang dipercaya. GET tidak memerlukan header token CSRF, tetapi tetap memakai sesi browser. Tidak ada endpoint lookup akun melalui ID/email query.

Guard membaca ulang user dan membandingkan marker integer auth_version dalam session dengan versi database. Marker tidak ada, bertipe salah, atau berbeda menghasilkan 401 SESSION_EXPIRED dan mengakhiri sesi tersebut. Akun blocked pada sesi yang sebelumnya authenticated menghasilkan 403 ACCOUNT_BLOCKED dan invalidasi sesi. Request berikutnya menjadi unauthenticated. Guest/expired session atau request tanpa konteks browser yang diterima menghasilkan 401 UNAUTHENTICATED; middleware framework dapat lebih dahulu mengembalikan 401 bila autentikasi sudah tidak valid.

Perubahan role/profil/membership dibaca lagi, bukan mengandalkan payload browser atau snapshot login. Endpoint tetap tidak menerbitkan kartu ataupun memberi izin aksi petugas/admin dari tampilan frontend saja. Respons 200 menggunakan resource di atas.

Penolakan berlaku pada pemeriksaan request berikutnya; pekerjaan yang sudah berjalan tidak dibatalkan secara push. Aksi yang menulis status, QR, atau attendance di tahap berikutnya tetap memerlukan row lock dan pemeriksaan ulang sesuai arsitektur.

### POST /api/v1/auth/logout

Authenticated browser session dengan CSRF. Body boleh kosong atau `{}`; endpoint tidak membaca target user/session dari client. Logout mengakhiri sesi saat ini, menghapus row sesi lama, membuat sesi anonim dengan token CSRF baru, dan membersihkan user pada guard web/Sanctum. Sesi browser lain tidak dihapus oleh logout biasa.

Sukses **204 tanpa body**. Jangan memanggil response.json() pada 204. X-Request-ID dan Cache-Control:no-store tetap dikirim. Logout guest menghasilkan 401, CSRF tidak valid menghasilkan 419. Sesi blocked/stale ditolak dan diakhiri oleh guard terlebih dahulu dengan 403/401.

Frontend harus membuang data akun, query/cache privat, serta resource kamera ketika logout/pergantian akun, lalu membaca CSRF cookie terbaru untuk mutation berikutnya. Tidak membuat mekanisme retry mutation otomatis.

### Alias legacy dan pencabutan sesi

GET `/api/user` sekarang memakai guard yang sama dan BrowserSessionResource sebagai /api/v1/auth/me, termasuk envelope data/meta. Tidak lagi memakai serialisasi langsung model User. Konsumen baru memakai path v1. Route register serta receipt generiknya tetap seperti tahap 03.

Stage 04 hanya membangun pemeriksaan marker pencabutan. Fitur reset password dan block admin berikutnya **wajib** menaikkan users.auth_version dan menghapus sessions user dalam transaksi/urutan lock yang ditetapkan. Membandingkan marker membuat row sesi lama yang terlambat ditulis tidak dapat digunakan setelah versi berubah. Test mensimulasikan replay row tersebut di MySQL test; perubahan auth_version bukan field publik.

Endpoint reset password tersedia pada tahap 08 di bawah; block admin dan pencabutan semua sesi oleh admin belum tersedia. Verifikasi email dan aktivasi tersedia pada tahap 05 di bawah. Tidak menandai status pending sebagai verified untuk menyelesaikan smoke test.

### Error sesi tambahan

| HTTP | Code | Kondisi |
| --- | --- | --- |
| 401 | INVALID_CREDENTIALS | Credential tidak sesuai atau akun tidak boleh login; pesan publik generik |
| 401 | UNAUTHENTICATED | Tidak ada sesi browser terautentikasi yang diterima |
| 401 | SESSION_EXPIRED | Marker versi sesi hilang/tidak valid/sudah dicabut |
| 403 | ACCOUNT_BLOCKED | Akun pada sesi authenticated sudah diblokir |
| 419 | CSRF_MISMATCH | Konteks browser/session/CSRF tidak memenuhi pemeriksaan mutation |
| 422 | VALIDATION_FAILED | Payload login tidak memenuhi kontrak |
| 429 | RATE_LIMITED | Batas percobaan login tercapai |

Error lainnya mengikuti envelope dan sanitasi tahap 03, termasuk response no-store dan server-generated request ID. Referensi primer: [Laravel authentication](https://laravel.com/docs/13.x/authentication), [session](https://laravel.com/docs/13.x/session), serta [Sanctum](https://laravel.com/docs/13.x/sanctum).



## Tahap 05 — email, aktivasi, dan kartu

Kontrak tahap 03–04 di atas tetap berlaku. Semua response API memakai request_id dan no-store. Semua akun uji di collection adalah data sintetis.

| Method dan path di bawah /api/v1 | Akses | Hasil |
| --- | --- | --- |
| POST /auth/email-verification/resend | Publik stateful + CSRF, JSON email | 202 generik + Retry-After 60; 429 request limit |
| GET /auth/email-verification/{id}/{hash}?expires=…&v=…&signature=… | Publik signed URL 60 menit | 200 verification_link_ready; tanpa mutasi |
| GET /me/membership/card | Cookie pemilik, sesi current, akun enabled, email verified, profil complete, membership active | 200 kartu privat dan payload QR |

POST resend menerima body kosong `{}` dan tidak menerima email/user_id/role/status dari client. Cooldown minimum 60 detik sejak delivery verifikasi terakhir; limiter 5 permintaan/jam/akun dan 10/jam/IP menghitung permintaan termasuk yang ditolak oleh action. Akun verified mendapatkan 202 tanpa record verifikasi baru. Suspended/ended tidak diaktifkan melalui resend/verifikasi.

GET verifikasi menggunakan middleware web untuk sesi, bukan menumpuk web dan stateful API. Alamat email dapat dibuka dari navigasi email tanpa Origin frontend; ownership, session auth_version, signature, waktu kedaluwarsa, hash email terkini, dan query v tetap diperiksa. Query tambahan/perubahan URL merusak signature. Frontend flow login/return internal akan dibuat pada tahap frontend; pada tahap ini buka URL melalui GET Postman setelah login di cookie jar Postman yang sama.

Sukses aktivasi:

```json
{
  "data": {
    "result": "membership_activated",
    "membership": {"status": "active", "member_number": "SRC-000001"}
  },
  "meta": {"request_id": "<server-uuid>"}
}
```

`SRC` contoh konfigurasi pengembangan, bukan penetapan format branding baru. Nomor berasal dari sequence membership yang dikunci, dipadding minimum enam digit tanpa reset tahunan; tidak diambil dari MAX+1. Prefix harus dikonfigurasi melalui MEMBER_NUMBER_PREFIX, 2–12 huruf/angka uppercase dengan karakter pertama huruf.

Hasil lain: `already_active` mengembalikan nomor existing tanpa membuat credential/delivery/audit aktivasi kedua. `email_verified_profile_incomplete` menandai email verified tetapi tetap pending, nomor null, dan tanpa credential. Modul pembaruan profil berikutnya harus menjalankan aturan aktivasi yang sama. Untuk sekarang, pengujian profil incomplete/pemulihannya dilakukan pada feature test.

Transaksi aktivasi mengunci membership → user → sequence → credential; memverifikasi ulang versi sesi saat lock sudah dipegang. Email verified, nomor, status active, credential, audit, dan outbox aktivasi commit/rollback bersama. Tidak ada SMTP dalam lock. Kegagalan kredensial/outbox membatalkan semua perubahan, termasuk pemakaian sequence. Keanggotaan suspended/ended memerlukan alur admin terpisah.

Kartu privat:

```json
{
  "data": {
    "community_name": "Sore Running Club",
    "full_name": "Anggota Verifikasi Postman",
    "member_number": "SRC-000001",
    "qr_payload": "src-member:v1:<43-karakter-base64url>",
    "issued_at": "<UTC-ISO8601>"
  },
  "meta": {"request_id": "<server-uuid>"}
}
```

Token dibuat dari random_bytes(32), dienkripsi satu kali oleh cast model; token_hash menyimpan SHA-256 binary dari string token, bukan dari seluruh payload. Kartu mengembalikan credential yang sama selama belum dirotasi. Endpoint belum merender PNG/SVG; frontend akan merender payload sebagai QR asli. QR bukan URL, token login, atau bukti kehadiran event. Tidak ada PII dalam payload QR. Ciphertext rusak/hash tidak cocok/credential aktif hilang menghasilkan 503 CREDENTIAL_UNAVAILABLE tanpa penggantian diam-diam.

| Status | Code yang relevan |
| --- | --- |
| 401 | UNAUTHENTICATED, SESSION_EXPIRED |
| 403 | ACCOUNT_BLOCKED, INVALID_VERIFICATION_LINK, VERIFICATION_OWNER_MISMATCH, VERIFICATION_LINK_STALE, MEMBERSHIP_CARD_UNAVAILABLE |
| 409 | MEMBERSHIP_NOT_ACTIVATABLE, MEMBERSHIP_STATE_CONFLICT |
| 419 | CSRF_MISMATCH pada POST |
| 422 | VALIDATION_FAILED pada input resend yang tidak diizinkan |
| 429 | RATE_LIMITED, header Retry-After |
| 503 | MEMBER_NUMBER_CONFIGURATION_UNAVAILABLE, MEMBER_NUMBER_UNAVAILABLE, CREDENTIAL_UNAVAILABLE |

### Outbox dan operasi

Registrasi existing tetap generik dan tidak membuat email baru. Delivery pending registrasi/aktivasi dicatat dalam transaksi. Enqueue setelah commit menggunakan database connection dan queue `mail`; kegagalan enqueue menyisakan pending untuk scheduler. Payload job hanya deliveryId, tanpa alamat/token/URL.

`outbox:dispatch --limit=100` dijadwalkan setiap menit dengan withoutOverlapping. Sweep memilih pending/failed yang due dan attempt<5, atau processing dengan lease expired. Job ganda dapat masuk queue; row lock + lease memastikan satu claim yang masih berlaku. Worker melepas lock sebelum SMTP. Lease 180 detik, job timeout 60 detik, SMTP timeout 15 detik, maksimal lima claim/pengiriman dengan backoff 60/300/900/3600 detik. Status failed bisa masih retryable; attempt_count=5 berarti perlu ditangani operator. Crash pada attempt terakhir dipulihkan menjadi failed/ATTEMPTS_EXHAUSTED setelah lease expired.

Penyelesaian memakai kondisi id+processing+attempt_count+lease_until. Worker lama tidak boleh menimpa hasil lease baru. Pengiriman eksternal tetap at-least-once: timeout sesudah SMTP menerima email bisa menghasilkan email duplikat. `sent` berarti transport menerima, bukan penerima membaca. Aktivasi tetap idempotent.

Recipient, status, email, dan auth_version dibaca saat worker berjalan. Delivery yang tidak relevan/akun unavailable dibatalkan dengan kode terkontrol. Error provider disimpan sebagai MAIL_SEND_FAILED, tanpa teks exception, credential SMTP, URL verifikasi, atau QR. Mailer `log` ditolak untuk mencegah signed URL masuk log. Mailer array hanya untuk APP_ENV=testing; tahap ini mendukung SMTP untuk email sungguhan atau Mailpit lokal. Pemilihan provider go-live tetap tahap tersendiri.

Jangan catat query signed URL atau response kartu di access log/analytics. Saat go-live, konfigurasi access log web server hanya path tanpa query string dan redaksi body/header rahasia. Jangan ekspor Postman environment yang sudah berisi tautan aktif.


## Tahap 06 — navigasi verifikasi frontend

Email verifikasi baru membuka FRONTEND_URL/verify-email dengan signed API URL di fragment #verification=. Laravel tetap membuat signed URL API melalui forUser(); forBrowser() hanya membungkusnya untuk navigasi browser. Fragment tidak dikirim ke server Next.js. Frontend memvalidasi origin/path/query, menghapus fragment dari address bar, menyimpan intent hanya di memori tab, melakukan POST ke signed API URL dengan CSRF setelah konfirmasi pengguna, tanpa login. Tidak ada signature di router query, localStorage, sessionStorage, atau Query key. Reload sesudah fragment dihapus mengharuskan pengguna membuka kembali email.

forUser(), TTL 60 menit, hash email/version, dan transaksi aktivasi tetap berlaku; proof awal publik menggantikan authenticated owner. Email lama dengan URL API langsung tetap valid selama belum kedaluwarsa. Setelah upgrade, restart queue worker dan gunakan email baru untuk mencoba alur browser. Postman tahap 05 diperbarui untuk mengekstrak URL API dari format email lama maupun fragment baru.

CORS mengekspos Retry-After dan X-Request-ID agar browser dapat menampilkan cooldown/support ID yang sudah diberikan API. Origin/credentials tetap dibatasi seperti sebelumnya. Tidak ada endpoint atau migration baru. Kartu tetap hanya tersedia melalui API privat; frontend merender credential asli menjadi SVG QR hitam-putih dengan quiet zone empat modul, tanpa mengirim data kartu melalui SSR.

## Tahap 07 — Biodata profil sendiri

Tambahan `GET /api/v1/me/profile` dan `PATCH /api/v1/me/profile` menggunakan sesi browser, ownership, CSRF untuk PATCH, response no-store, serta audit field yang berubah. Kontrak lengkap: [PROFILE_API_STAGE_07.md](PROFILE_API_STAGE_07.md). Endpoint akun dan alur login tahap 06 dipertahankan.


## Revisi email-first — 24 Oktober 2026

Login wajib enabled + verified. GET signed URL read-only. POST signed URL + CSRF + JSON {} memverifikasi tanpa login/auto-login. Response publik hanya result dan meta; tanpa identitas, nomor, atau QR. Resend publik menerima email dengan respons generik dan cooldown. Rincian dan pengujian: ../../docs/API_EMAIL_FIRST.md dan ../../docs/PANDUAN_EMAIL_FIRST.md.

## Lupa dan reset password — tahap 08, 9 November 2026

`POST /api/v1/auth/forgot-password` dan `POST /api/v1/auth/reset-password` adalah endpoint **publik**, tanpa syarat login/email verified. Tetap wajib origin stateful tepercaya, session cookie dan CSRF: GET `/sanctum/csrf-cookie`, lalu POST dengan credentials dan `X-XSRF-TOKEN`. Origin tak dikenal atau CSRF tidak sesuai menghasilkan 419, bukan fallback bearer/stateless. GET pada kedua endpoint API menghasilkan 405; GET `/reset-password` di frontend hanya menampilkan form.

`Accept: application/json`, `Content-Type: application/json`. Body objek JSON maksimal 4.096 byte, kedalaman parse 8. Query string dan field di luar whitelist ditolak 422. Email dinormalisasi trim/lowercase tanpa menghapus alias titik/plus, RFC email maksimal 254 karakter.

### Permintaan tautan

```json
{ "email": "anggota@example.test" }
```

Payload valid selalu menerima **202**, `Cache-Control: no-store`, `Retry-After: 60`, terlepas dari keberadaan/status akun atau cooldown penerima. Error validasi/CSRF/rate limit tetap berlaku sebelum lookup akun. Tidak ada ID akun/status/metadata cooldown per penerima yang dikembalikan.

```json
{
  "data": { "result": "password_reset_requested" },
  "meta": { "request_id": "d6578996-20ac-4d4e-ab72-ef8d187fe122" }
}
```

Receipt bukan bukti email terkirim. Frontend menampilkan: “Jika alamat email memenuhi syarat, tautan reset password akan diproses. Periksa kotak masuk dan folder spam.” Akun blocked/unverified dapat menerima reset tetapi tidak berubah status; akun tidak dikenal tidak membuat token atau outbox. Broker users sudah memakai expiry 60 menit dan throttle 60 detik; delivery terakhir juga membatasi cooldown setelah token dikonsumsi. Permintaan baru setelah cooldown menggantikan token lama.

### Simpan password baru

| Field | Ketentuan |
| --- | --- |
| email | Email dari tautan, mengikuti normalisasi di atas |
| token | Proof broker, 64 digit hex lowercase; wajib dikirim hanya di body POST |
| password | Minimal 12 karakter, maksimum 72 byte UTF-8 sesuai bcrypt existing; tanpa null byte, spasi dipertahankan |
| password_confirmation | Wajib sama dengan password |

Contoh berikut menggunakan placeholder, bukan secret yang valid:

```json
{
  "email": "anggota@example.test",
  "token": "<64-digit-hex-dari-tautan-email>",
  "password": "<password-baru-minimal-12-karakter>",
  "password_confirmation": "<nilai-yang-sama>"
}
```

Sukses **200**, no-store:

```json
{
  "data": { "result": "password_reset" },
  "meta": { "request_id": "d6578996-20ac-4d4e-ab72-ef8d187fe122" }
}
```

Reset mengubah password dan remember token, menaikkan auth_version, menghapus semua session akun terkait, mengonsumsi token, membatalkan pending reset delivery, serta membuat audit tanpa password/proof. Semua atomik dalam transaksi dengan user/token row lock. Request paralel token sama menghasilkan paling banyak satu 200; lainnya 422. Sesi yang terlambat tersimpan dengan versi lama ditolak pada akses privat berikutnya. Tidak ada auto-login, perubahan email verification/blokir/membership/member number/QR, atau perubahan identitas sesi akun lain.

### Error dan limit

Kedua endpoint mempunyai bucket terpisah: **5/menit/IP, 20/jam/IP, 5/jam/email ternormalisasi**. Key menggunakan hash dan cache existing, tanpa pemeriksaan keberadaan akun. 429 memiliki `Retry-After` sisa bucket server; frontend mengikuti nilai ini, bukan cooldown hardcoded. Tidak melakukan retry mutation otomatis setelah timeout/419. Timeout reset bisa berarti commit sudah terjadi; UI menawarkan login dengan password baru atau tautan baru.

| HTTP | Code | Keadaan |
| --- | --- | --- |
| 400 | INVALID_JSON | JSON rusak/nonobjek/terlalu dalam |
| 405 | METHOD_NOT_ALLOWED | GET/metode selain POST |
| 413 | PAYLOAD_TOO_LARGE | Body melewati batas |
| 415 | UNSUPPORTED_MEDIA_TYPE | Bukan JSON |
| 419 | CSRF_MISMATCH | Origin/session/CSRF tidak sesuai |
| 422 | VALIDATION_FAILED | Field/konfirmasi/password/token format tidak valid, query atau field tambahan |
| 422 | PASSWORD_RESET_INVALID | Email/token tidak cocok, expired, bekas, binding user/version stale |
| 429 | RATE_LIMITED | Bucket IP/email tercapai, Retry-After tersedia |
| 500/503 | SERVER_ERROR / SERVICE_UNAVAILABLE | Kegagalan server/database tersanitasi, tanpa sukses palsu |

```json
{
  "error": {
    "code": "PASSWORD_RESET_INVALID",
    "message": "Tautan reset tidak valid, kedaluwarsa, atau sudah digunakan. Minta tautan reset baru.",
    "fields": {}
  },
  "meta": { "request_id": "d6578996-20ac-4d4e-ab72-ef8d187fe122" }
}
```

`meta.request_id` dan `X-Request-ID` mengikuti envelope existing. Input invalid tidak mengonsumsi token; kegagalan transaksi tidak meninggalkan perubahan parsial. Password dan token tidak ditulis ke log/audit/analytics/storage browser.

### Email dan pemulihan delivery

Outbox `password_reset` menyimpan proof terenkripsi sementara, bukan token mentah pada job. Callback broker membuat hash/token binding dan outbox dalam satu transaksi; dispatch hanya setelah commit. Job existing dan sweep pending memulihkan kegagalan dispatch. Worker memeriksa email/version/token masih current lalu mengirim SMTP di luar row lock. Retry menggunakan token dan expiry awal; consumed/replaced/expired/stale delivery dibatalkan, tidak menerbitkan ulang token. Payload dibersihkan saat sent/cancelled/final failure. SMTP masih dapat menerima duplikasi jika worker crash setelah provider menerima email.

Tautan hanya berasal dari `FRONTEND_URL` tepercaya dengan path `/reset-password` dan fragment email/token; URL tujuan bebas ditolak. Browser menghapus fragment setelah parsing dan menyimpan proof hanya selama form terbuka. Konfigurasi/operasi dan batas pengujian: [PASSWORD_RESET_STAGE08.md](../../docs/PASSWORD_RESET_STAGE08.md).

## Perubahan email terverifikasi — tahap 09, 11 November 2026

Kedua endpoint di bawah membutuhkan sesi browser pemilik, akun enabled, email aktif verified, serta auth_version terkini. Gunakan GET `/sanctum/csrf-cookie`, credentials, Origin frontend tepercaya dan header `X-XSRF-TOKEN` pada POST. Tidak menerima user_id, tujuan redirect, role, atau status dari client. JSON object maksimal 4.096 byte; query/field tambahan ditolak. Error format: 400 INVALID_JSON, 413 PAYLOAD_TOO_LARGE, 415 UNSUPPORTED_MEDIA_TYPE; metode selain POST pada endpoint ini 405. Semua respons memakai no-store, `meta.request_id` dan X-Request-ID existing.

### POST /api/v1/me/email-change

```json
{
  "email": "alamat-baru@example.test",
  "password": "<password-akun-saat-ini>"
}
```

Email trim/lowercase, RFC, maksimum 254 karakter sesuai aturan akun; tidak menghapus alias titik/plus. Password tidak di-trim, mengikuti batas byte login existing, dan harus cocok dengan hash akun saat lock dipegang. Email sama dengan alamat aktif atau alamat yang telah dipakai mendapat 409 EMAIL_UNAVAILABLE tanpa identitas pemilik alamat. Email pending tidak mereservasi alamat.

Sukses **202**, `Retry-After: 60`:

```json
{
  "data": { "result": "email_change_requested" },
  "meta": { "request_id": "d6578996-20ac-4d4e-ab72-ef8d187fe122" }
}
```

Receipt menerima pemrosesan outbox, bukan bukti email terkirim atau alamat aktif berubah. Permintaan berlaku 60 menit. Permintaan baru yang diterima mencabut semua request sebelumnya dan delivery pending terkait. Setelah cooldown, permintaan ke tujuan sama juga membuat proof baru; tidak ada endpoint resend terpisah.

Cooldown **60 detik per akun** dihitung dari request terakhir, termasuk setelah reload atau penggantian tujuan. Limit request: **5/menit/IP, 20/jam/IP, 5/jam/akun, 5/jam/email tujuan ternormalisasi**. Cooldown/bucket yang menolak mengembalikan 429 RATE_LIMITED dengan Retry-After sisa waktu server. Key cache di-hash. Salah password tetap dihitung oleh limiter.

### Status pada GET/PATCH /api/v1/me/profile

Response profil mendapat field tambahan `email_change`; field edit PATCH tetap sama dan tidak menerima email/password. Contoh potongan data:

```json
{
  "email_change": {
    "active_email": "alamat-lama@example.test",
    "retry_after": 42,
    "pending": {
      "email": "alamat-baru@example.test",
      "expires_at": "2026-11-11T05:00:00Z",
      "status": "pending"
    }
  }
}
```

`pending` null bila tidak ada request unconsumed/unrevoked dengan versi akun terkini. Status menjadi `expired` ketika waktu habis; request expired tetap ditampilkan sampai diganti/dibersihkan. `retry_after` detik nonnegatif untuk cooldown penerima; bucket rate limit tetap dapat menolak lebih lama. Tidak mengekspos request ID, hash, token, atau versi akun. `email_change` nullable jika pemilik tidak lagi tersedia ketika resource dibentuk. Detail biodata tetap pada [PROFILE_API_STAGE_07.md](PROFILE_API_STAGE_07.md).

### POST /api/v1/me/email-change/verify

Contoh placeholder, bukan proof aktif:

```json
{
  "request_id": "d6578996-20ac-4d4e-ab72-ef8d187fe122",
  "email": "alamat-baru@example.test",
  "token": "<64-digit-hex-lowercase-dari-email>"
}
```

request_id UUID; email mengikuti normalisasi request; token 64 digit hex lowercase. Pemilik berasal dari sesi. Token untuk akun lain, versi stale, tujuan berbeda, consumed/revoked/expired mendapat 422 EMAIL_CHANGE_INVALID dengan pesan aman, tanpa mengubah sesi akun lain. Request invalid tidak mengonsumsi proof. Limit verify memakai bucket terpisah **5/menit/IP, 20/jam/IP, 20/jam/akun**.

Sukses **200**:

```json
{
  "data": { "result": "email_changed" },
  "meta": { "request_id": "d6578996-20ac-4d4e-ab72-ef8d187fe122" }
}
```

Transaksi mengunci membership → user → request; memeriksa ulang token, pemilik, tujuan, expiry, versi dan uniqueness. Constraint UNIQUE users.email adalah perlindungan akhir; konflik paralel menjadi 409 EMAIL_UNAVAILABLE dengan rollback. Email baru dan verified_at, konsumsi proof, auth_version +1, remember token, pencabutan request/delivery terkait, penghapusan broker proof lama dan seluruh sesi, serta audit commit bersama. Cookie sesi pemilik kemudian diakhiri. Dua konfirmasi proof sama menghasilkan maksimal satu sukses; request lainnya dapat ditolak 401 versi sesi usang atau 422 proof bekas. Tidak mengubah status blokir, membership, nomor/QR, atau riwayat; tanpa auto-login.

| HTTP / code | Keadaan |
| --- | --- |
| 401 UNAUTHENTICATED / SESSION_EXPIRED | Login diperlukan atau sesi sudah dicabut; gunakan email aktif lama bila belum konfirmasi |
| 403 ACCOUNT_BLOCKED / EMAIL_NOT_VERIFIED | Akun/sesi tidak memenuhi guard existing |
| 409 EMAIL_UNAVAILABLE | Alamat tidak dapat dipakai, termasuk konflik saat commit |
| 419 CSRF_MISMATCH | Origin/session/CSRF tidak sesuai |
| 422 VALIDATION_FAILED | Format/field tidak memenuhi kontrak |
| 422 PASSWORD_CONFIRMATION_FAILED | Password saat ini tidak sesuai; error.fields.password tersedia |
| 422 EMAIL_CHANGE_INVALID | Proof tidak sesuai akun/tujuan, expired, stale, revoked, atau bekas |
| 429 RATE_LIMITED | Bucket/cooldown; ikuti Retry-After |
| 500/503 SERVER_ERROR / SERVICE_UNAVAILABLE | Error tersanitasi; tidak mengklaim sukses |

Timeout dapat berarti transaksi sudah commit. Jangan retry mutation otomatis: periksa status/sesi atau coba login dengan email baru. Login berhasil tetap ke `/beranda`, tanpa returnTo.

### Delivery dan browser

Outbox `email_change` memakai payload terenkripsi sementara `{request_id, token}`; job hanya delivery ID. Worker memilih alamat pending dari request yang masih valid, bukan alamat aktif User. Hash, owner, expiry, versi dan consumed/revoked diperiksa sebelum pengiriman di luar lock. Retry/sweep tidak menerbitkan token baru. Payload dihapus saat sent/cancelled/final failure. Provider dapat menerima email duplikat atau tautan yang telanjur invalid akibat race; ini tidak menghidupkan kembali proof. Reset password mencabut pending request/delivery dalam transaksi reset; perubahan auth_version lain membuatnya stale.

Tautan dari FRONTEND_URL tepercaya menuju `/verify-email-change` dengan fragment request_id/email/token. GET halaman read-only; fragment dihapus dari address bar dan proof hanya di memori komponen. POST memerlukan klik eksplisit. Guest login memakai email aktif lama lalu membuka ulang email; tidak ada penyimpanan proof/returnTo atau pergantian identitas sesi. Sukses membersihkan cache privat dan menuju `/login` dengan pesan memakai email baru. Pesan sukses hanya memori, tidak memuat alamat/token dan tidak persisten setelah reload.

Audit hanya menyimpan actor/target/request_id dan flag tindakan, tanpa alamat, password atau proof. Tidak mencatat body/fragment dalam log/analytics. Migration, SMTP/worker/scheduler, serta matriks verifikasi yang **belum dijalankan**: [EMAIL_CHANGE_STAGE09.md](../../docs/EMAIL_CHANGE_STAGE09.md).

## Landing page dan editorial admin — tahap 10

Status: implementasi disiapkan 14 November 2026; review dan pengujian terisolasi dilakukan 15 dan 20 November 2026. Migration Stage 10 tersedia pada MySQL `sorerunapp_test` (batch 4), **belum diterapkan pada database aplikasi**. Hasil aktual dan batas verifikasi: [LANDING_PAGE_STAGE10.md](../../docs/LANDING_PAGE_STAGE10.md). Acuan PRD FR-19–21/AC-31–35, ARCHITECTURE §11.8/12.4 dan SYSTEM_DESIGN §11.5.

### Akses, transport dan pembatasan

Base `/api/v1`. Endpoint `/public/landing-page` dan media publik adalah GET tanpa login/CSRF, tidak memilih draft atau mempersonalisasi respons. Next.js memanggil API publik dari server tanpa cookie pengguna. Semua endpoint admin membutuhkan sesi browser Sanctum stateful, origin frontend, akun enabled, email verified, auth_version terkini dan policy role admin; membership aktif tidak disyaratkan. Sebelum mutation browser mengambil `/sanctum/csrf-cookie`, mengirim cookie dan X-XSRF-TOKEN. Bearer/stateless bukan pengganti. GET admin juga memerlukan origin/session; tidak ada preview publik bertoken.

Body save/publish berupa objek JSON ≤131072 byte/depth 16; field di luar whitelist ditolak. Upload memakai multipart/form-data dengan satu file `image` dan CSRF; jangan menetapkan boundary sendiri. Respons JSON memakai envelope `data`/`meta.request_id`, error memakai envelope existing dan X-Request-ID. Semua respons konten/media memakai no-store; admin/preview/media juga X-Robots-Tag noindex, nofollow. Tidak ada `returnTo`.

Rate limit per IP/menit: GET publik 120, media 240; GET admin/preview/library 60, save 30, publish/upload/delete 10. Bucket route Laravel terpisah; 429 mempertahankan Retry-After. Tidak mengulang mutation otomatis pada timeout/419. Batas webserver/PHP upload perlu mendukung file 5 MB ditambah overhead multipart.

### GET /public/landing-page

Sukses 200 sebelum publikasi pertama:

```json
{"data":{"publication":null},"meta":{"request_id":"d6578996-20ac-4d4e-ab72-ef8d187fe122"}}
```

Setelah terbit, `data.publication` memuat `version` integer, `published_at` UTC ISO8601, `content` sesuai schema di bawah, dan `media` array descriptor `{id: UUID, url: URL API publik, mime: "image/webp", width: integer, height: integer}`. Field draft, actor, path, session, profil anggota, QR dan event internal tidak tersedia. Section hidden/kosong mempunyai isi kosong pada snapshot; semua referensi media di sini hanya milik snapshot terbit. Kegagalan database/schema tidak disamarkan sebagai publication null.

### Schema content versi 1

Request `PUT /admin/landing-page/draft`:

```json
{
  "version": 0,
  "content": {
    "schema_version": 1,
    "header": {"login_label":"Masuk ke Aplikasi","nav_labels":{"about":"Tentang","activities":"Kegiatan","gallery":"Galeri","faq":"FAQ","contact":"Kontak"}},
    "hero": {"eyebrow":"","title":"","description":"","login_label":"Masuk ke Aplikasi","image":null},
    "about": {"title":"","description":"","items":[]},
    "activities": {"title":"","description":"","items":[]},
    "gallery": {"title":"","items":[]},
    "faq": {"title":"","items":[]},
    "contact": {"title":"","description":"","email":"","phone":"","socials":[]},
    "footer": {"text":""},
    "seo": {"title":"","description":"","image":null},
    "sections": [{"key":"about","visible":true},{"key":"activities","visible":true},{"key":"gallery","visible":true},{"key":"faq","visible":true},{"key":"contact","visible":true}]
  }
}
```

Contoh adalah draft kosong, bukan konten resmi yang siap dipublikasikan. Field schema wajib hadir; teks draft boleh kosong, dinormalisasi trim dan null teks menjadi string kosong. Semua objek memakai whitelist; tidak menerima URL CTA, HTML/JavaScript, CSS, permission, actor atau konfigurasi. Tombol header/hero selalu menuju /login.

| Field | Batas/struktur |
| --- | --- |
| header.login_label / hero.login_label | 60 karakter; wajib tidak kosong saat publish |
| header.nav_labels | Lima key section; masing-masing 40 karakter, wajib bila section tampil |
| hero.eyebrow / title / description | 80 / 150 / 1000 karakter; title/description wajib saat publish |
| about / activities | title 100, description 2000; maksimal 12 items `{title:100,description:1000}` |
| gallery | title 100, maksimal 24 items gambar |
| faq | title 100, maksimal 20 items `{question:200,answer:2000}` |
| contact | title 100, description 1000, email valid maksimal 254, telepon maksimal 32 dengan digit/+()/spasi/hyphen; maksimal 8 socials `{label:60,url:500}` |
| contact.socials.*.url | HTTPS, tanpa credential/kontrol/spasi; tidak di-fetch server |
| footer.text | 500 karakter |
| seo.title / description | 70 / 180 karakter, wajib saat publish |
| hero.image / seo.image / gallery.items.* | null untuk hero/SEO atau `{media_id: UUID,alt:180,caption:300}`; gallery tidak menerima null; alt nonkosong wajib ketika gambar diterbitkan |
| sections | Tepat lima key unik about/activities/gallery/faq/contact; urutan array menentukan susunan; visible boolean |

Publish menyembunyikan section tanpa judul/isi yang cukup, meskipun visible true. Item pada section yang tampil harus lengkap. Semua UUID media draft wajib ada dan state ready, termasuk section yang disembunyikan. Caption/alt adalah bagian dokumen versi, bukan atribut global file.

### Endpoint admin

| Metode/path | Request dan sukses |
| --- | --- |
| GET /admin/landing-page | 200 `{version,content,published_version,published_at,media}`; versi awal 0, published_* null |
| PUT /admin/landing-page/draft | `{version,content}`; 200 `{version: versi_baru}`; naik satu, audit content.draft_saved atomik |
| GET /admin/landing-page/preview | 200 bentuk draft, content disaring seperti publikasi; 422 jika belum lengkap untuk ditampilkan sebagai calon publikasi; tetap privat/no-store |
| POST /admin/landing-page/publish | `{version: integer}`; 200 `{version,published_at}`; snapshot/pivot/pointer/audit content.published atomik |
| GET /admin/landing-page/media?page=1 | 200 `{items:[descriptor + state ready/deleting],page,last_page}`; 30 item/halaman, terbaru dahulu; page 1–100000 |
| POST /admin/landing-page/media | multipart `image`; 201 descriptor media privat, audit content.media_uploaded |
| GET /admin/landing-page/media/{UUID} | Byte gambar ready, membutuhkan sesi admin; tamu menerima envelope JSON 401 juga untuk Accept image/*, text/html atau */*, tanpa redirect login backend |
| DELETE /admin/landing-page/media/{UUID} | CSRF, tanpa body diperlukan; 204 setelah file/row terhapus; audit permintaan penghapusan tanpa path |
| GET /public/landing-page/media/{UUID} | Byte gambar ready yang dipakai publikasi aktif; selain itu 404 |

Konflik save/publish terhadap draft_version terkini menghasilkan 409 CONTENT_VERSION_CONFLICT tanpa write parsial. Publish ulang versi yang masih sama idempotent; UNIQUE(page,version) menjaga revisi ganda. Editor perlu memuat ulang setelah hasil tak pasti, bukan menebak versi. Revisi terbit tidak mempunyai endpoint edit/delete.

Upload menerima JPEG/PNG/WebP maksimal 5 MB, sisi 16–6000 px dan ≤16 megapixel. Isi/MIME/ekstensi diperiksa, SVG ditolak; GD WebP memproses ulang maksimal sisi 2400 px, kualitas 85, metadata dibuang. Memori decoder juga dibatasi. File hasil baru tidak menimpa file lain. Tidak ada URL storage internal atau EXIF di respons. Media referenced oleh draft atau revisi yang dipertahankan ditolak penghapusannya. Tombstone deleting memungkinkan retry kegagalan file; tidak dapat direferensikan draft baru. Orphan/commit tak pasti mengikuti prosedur tahap 10 tanpa cleanup massal.

| HTTP / code | Kondisi |
| --- | --- |
| 400 INVALID_JSON; 413 PAYLOAD_TOO_LARGE; 415 UNSUPPORTED_MEDIA_TYPE | Format/body JSON tidak memenuhi batas |
| 401 UNAUTHENTICATED / SESSION_EXPIRED | Tidak ada sesi pemilik atau versi sesi stale |
| 403 FORBIDDEN / ACCOUNT_BLOCKED / EMAIL_NOT_VERIFIED | Tidak punya role admin atau guard akun menolak |
| 404 NOT_FOUND | Media tidak tersedia/tidak dipublikasikan; tanpa path atau identitas pengunggah |
| 409 CONTENT_VERSION_CONFLICT / MEDIA_IN_USE | Versi draft berubah / file masih direferensikan |
| 419 CSRF_MISMATCH | Origin/session/CSRF tidak sah |
| 422 VALIDATION_FAILED | Field/URL/media/kelengkapan publish tidak valid; field content.* atau image |
| 429 RATE_LIMITED | Ikuti Retry-After |
| 503 IMAGE_PROCESSOR_UNAVAILABLE / IMAGE_PROCESSING_FAILED | GD WebP belum tersedia atau pemrosesan gagal |
| 503 MEDIA_STORAGE_UNAVAILABLE / MEDIA_DELETE_PENDING | Storage tidak tersedia / penghapusan perlu dilanjutkan |
| 500/503 SERVER_ERROR / SERVICE_UNAVAILABLE | Kegagalan tersanitasi; jangan mengklaim sukses |

## Super Admin dan provisioning Admin — tahap 11, 21 November 2026

Status: kontrak diuji melalui feature/concurrency dan integrasi browser nyata pada database khusus sorerunapp_stage11_test. Schema juga sudah diterapkan pada development lokal sorerunapp, batch 7; bootstrap aplikasi serta smoke terautentikasi masih tertunda karena kandidat pertama belum terverifikasi. Hasil aktual dan batas verifikasi: docs/SUPER_ADMIN_STAGE11.md di root repository.

### Role, sesi dan transport

Roles pada GET /auth/me dapat memuat member, staff, admin, super_admin secara bersamaan. Super Admin memakai izin administratif bersama Admin, tetapi tidak melewati ownership, status, eligibility, CSRF atau audit. Hanya Super Admin current/enabled/verified/aktivasi selesai boleh provisioning/status/resend. Admin biasa 403 FORBIDDEN. Akun provisioning pending tidak dapat login atau memakai sesi privat: 403 ADMIN_ACTIVATION_REQUIRED; middleware mencabut sesi. Reset password tidak menyelesaikan aktivasi undangan.

Semua mutation memakai Sanctum stateful, GET /sanctum/csrf-cookie, Origin frontend tepercaya, cookie dan X-XSRF-TOKEN. Tidak memakai bearer sebagai pengganti. JSON object maksimal 8192 byte/depth 8; query dan field tambahan ditolak. Respons envelope data/meta.request_id dan X-Request-ID existing; no-store, admin/aktivasi noindex. Tidak menerima role/status/actor_id/user_id/verification flags dari client.

### GET /api/v1/admin/accounts

Khusus Super Admin current/enabled/verified, dengan Sanctum session cookie dan Origin stateful tepercaya seperti endpoint privat lain. GET read-only tidak memerlukan token CSRF; mutation tetap wajib CSRF. Query opsional `page` integer 1–1000000 (default 1); field/query lain ditolak 422. Urutan ID menurun, 20 akun per halaman, termasuk akun Admin lama/multi-role dan blocked; tidak mencakup akun yang tidak mempunyai role Admin. Rate limit bersama baca status undangan: 60/menit/actor, 429 dengan Retry-After. 401 untuk guest/sesi stale; 403 untuk izin/status/verifikasi tidak memenuhi syarat. Respons no-store dan noindex.

```json
{
  "data": {
    "items": [{"user_id":123,"full_name":"Admin Uji","email":"admin-uji@example.test","account_status":"enabled","email_verified":false,"has_invitation":true,"can_delete":true,"can_change_password":false}],
    "pagination": {"current_page":1,"last_page":1,"per_page":20,"total":1}
  },
  "meta": {"request_id":"4741bd90-1bdf-464f-9874-65ec278af437"}
}
```

`has_invitation` menunjukkan record provisioning tersedia sehingga status undangan dapat dibuka, bukan bukti email terkirim atau proof masih valid. Akun lama tanpa provisioning bernilai false, tidak dibuatkan undangan otomatis. Daftar kosong/halaman melampaui data: items [], total sesuai seluruh akun Admin dan last_page minimal 1. Tidak memuat password/hash/token, role lain, membership, QR, actor/provisioning key atau payload outbox.

### POST /api/v1/me/password dan POST /api/v1/admin/accounts/{userId}/password

`/me/password`: Member/Admin/Super Admin current/enabled/verified untuk password sendiri, subjek hanya dari sesi (tidak menerima user_id client). Membership aktif tidak diperlukan untuk perubahan password sendiri. Route target: hanya Super Admin; target harus mempunyai role Admin, enabled, email terverifikasi dan aktivasi selesai, tanpa role Super Admin. Akun multi-role Admin+member tetap mengikuti aturan password ini tanpa mengubah membership. Target self melalui route target ditolak; gunakan /me/password. Member tidak dapat memakai route target untuk mengganti password akun lain. Tidak memberikan verifikasi email atau melewati undangan.

Body JSON:

```json
{"current_password":"<password-pelaku-saat-ini>","password":"<password-baru-minimal-12-karakter>","password_confirmation":"<konfirmasi-yang-sama>"}
```

Contoh memakai placeholder, bukan credential. `current_password` milik pelaku (Super Admin untuk tindakan target). Password baru minimal 12 karakter, maksimal 72 byte dan tanpa null; konfirmasi wajib sama. Field role/status/subject/email/actor lain dan query ditolak 422. JSON object ≤8192 byte/depth 8, Sanctum session/Origin frontend tepercaya/X-XSRF-TOKEN wajib. Limit bersama 5/menit dan 20/jam/actor; 429 + Retry-After.

204 tanpa body sesudah transaksi; no-store/noindex dan X-Request-ID. Hash baru/auth_version, pencabutan sesi/proof reset/pending email/undangan/delivery terkait serta audit atomik. Perubahan sendiri mengakhiri cookie saat ini; browser membersihkan cache privat dan menuju /login dengan notice password berubah. Perubahan target tidak mengganti/mengakhiri identitas pelaku. Tidak ada email konfirmasi, auto-login, token atau password dalam response/audit, maupun perubahan status/verifikasi/role/membership.

Error envelope existing: 401 guest/sesi versi/hash pelaku berubah, 403 izin/status/verifikasi pelaku ditolak, 419 CSRF, 422 validasi atau `CURRENT_PASSWORD_INVALID` dengan `fields.current_password`, 404 target tidak ditemukan, 409 `ADMIN_PASSWORD_NOT_ALLOWED` untuk target tidak eligible/self/Super. Tidak mengulang POST otomatis setelah timeout; hasil dapat belum diketahui. Flag `can_change_password` pada GET daftar memandu tampilan tombol tetapi seluruh syarat diperiksa ulang saat POST.

### DELETE /api/v1/admin/accounts/{userId}

Hanya Super Admin dengan sesi current/enabled/verified; bukan bearer. Body JSON `{}`, tanpa query/field tambahan. Sanctum CSRF cookie/Origin tepercaya/X-XSRF-TOKEN wajib seperti mutation lain. Limit 10/menit/actor, 429 + Retry-After. 204 tanpa body setelah commit, no-store/noindex dan X-Request-ID existing. Client tidak mengulang mutation otomatis; timeout meminta periksa daftar dahulu.

Penghapusan logis hanya bagi akun yang memiliki role Admin saja, tanpa profil anggota/membership. `can_delete` pada GET daftar menunjukkan eligibility saat baca, bukan izin final. Self/Super Admin/multi-role/akun anggota: 409 `ADMIN_DELETE_NOT_ALLOWED`, tanpa write. Akun tak ditemukan 404, guest/sesi stale 401, izin/status/verifikasi pelaku ditolak 403, CSRF 419, query/field tambahan 422, JSON rusak/bukan objek 400, content type bukan JSON 415, ukuran >8192 byte 413.

Dalam transaksi: blokir akun, auth_version naik, role Admin/sesi/undangan/pending email-change/reset token/delivery tertunda dicabut dan audit dicatat. Record akun, email unique, provisioning/idempotensi serta audit historis dipertahankan; tidak ada hard delete, anonimisasi, penghapusan media/publikasi atau pemulihan melalui endpoint ini. Retry target blocked/roleless dengan audit penghapusan mengembalikan 204 tanpa audit/versi tambahan; setelah audit habis retensi, dapat 409 tanpa write. Replay POST provisioning terhadap akun ini 409 `ADMIN_ACCOUNT_DELETED`; email tetap konflik `EMAIL_UNAVAILABLE`. GET status undangan akun yang kehilangan role Admin 404. Tidak menghidupkan kembali akun lewat reset password.

### POST /api/v1/admin/accounts

Body:
```json
{
  "full_name": "Admin Uji",
  "email": "admin-uji@example.test",
  "request_key": "07cc29ab-5043-434b-8dd8-ed9cc29e5837"
}
```

Nama di-trim (2–150 karakter); email trim/lowercase (maksimal 254, RFC); UUID request_key dinormalisasi lowercase. Role selalu admin. Kunci unik per pelaku; payload nama/email ternormalisasi diikat hash. Retry kunci/payload sama mengembalikan akun serta status terkini tanpa akun/undangan/outbox/audit ganda; kunci sama/payload berbeda 409 IDEMPOTENCY_CONFLICT. Kunci baru dengan email existing 409 EMAIL_UNAVAILABLE, tanpa promosi akun existing. Tidak ada biodata anggota/membership/nomor/QR atau perubahan sequence.

201, termasuk replay idempotensi:
```json
{
  "data": {
    "user_id": 123,
    "full_name": "Admin Uji",
    "email": "admin-uji@example.test",
    "role": "admin",
    "status": "pending",
    "expires_at": "2026-11-22T10:00:00Z",
    "retry_after": 60,
    "activated_at": null
  },
  "meta": {"request_id": "4741bd90-1bdf-464f-9874-65ec278af437"}
}
```

Respons hanya menyatakan akun dan undangan diterima untuk diproses; bukan email sudah terkirim. Password awal hash nilai acak yang dibuang. Target tidak dapat login sebelum menerima undangan.

### GET /api/v1/admin/accounts/{userId}/invitation

Super Admin saja. 200 dengan Resource sama. Hanya akun yang dibuat melalui provisioning dapat dibaca; target lain 404. status: pending, active (aktivasi selesai, bukan status enabled), expired, revoked, stale. retry_after adalah detik cooldown tersisa. Tidak memuat token/hash, provisioning key, pelaku, payload outbox atau path internal. Bukan endpoint daftar/manajemen seluruh pengguna.

### POST /api/v1/admin/accounts/{userId}/invitation/resend

Body JSON {}. 202 dengan Resource sama dan Retry-After. Cooldown 60 detik dari undangan terakhir: 429 dan Retry-After aktual. Target sudah diaktivasi, blocked, terverifikasi di luar aktivasi, atau role tidak sesuai: 409 INVITATION_NOT_AVAILABLE. Pending stale karena reset boleh menerima undangan baru setelah cooldown jika target tetap eligible. Proof/delivery lama dicabut; undangan baru 24 jam.

### POST /api/v1/auth/admin-invitations/accept

Publik stateful + CSRF, tanpa middleware login/verified. Browser yang sedang login ditolak 403 INVITATION_SESSION_CONFLICT tanpa logout, perubahan identitas, atau konsumsi proof. Halaman /accept-admin-invitation membaca fragment invitation_id/email/token, segera membersihkannya, menyimpan hanya di memori tab; GET tidak mengirim/mengonsumsi proof.

Body (nilai proof hanya placeholder dokumentasi):
```json
{
  "invitation_id": "221ad629-4676-47e6-a762-4edcffd2f785",
  "email": "admin-uji@example.test",
  "token": "<64 karakter hex dari undangan, bukan token contoh aktif>",
  "password": "<password pilihan penerima minimal 12 karakter>",
  "password_confirmation": "<konfirmasi yang sama>"
}
```

Password mengikuti batas bcrypt existing: maksimal 72 byte, tanpa null, konfirmasi cocok. Validasi proof meliputi target, token SHA-256, email akun/undangan/payload, auth_version, expiry, consumed/revoked, provisioning pending, akun enabled/unverified dan role Admin, tanpa Super Admin. Error proof memakai 422 ADMIN_INVITATION_INVALID yang sama untuk invalid/expired/used/stale; validasi field 422 VALIDATION_FAILED. Transaksi menetapkan password/email_verified_at/activated_at, konsumsi proof, auth_version +1, remember token baru, pencabutan sessions/broker proof/delivery dan audit actor NULL berdasarkan email proof. Tidak menerbitkan sesi atau membership.

200:
```json
{"data":{"result":"admin_activated"},"meta":{"request_id":"4741bd90-1bdf-464f-9874-65ec278af437"}}
```

Sesudah sukses pengguna menuju /login secara manual, login berhasil tetap /beranda; tanpa auto-login/returnTo. Proof invalid meminta undangan baru kepada Super Admin, bukan memakai reset untuk verifikasi.

### GET /api/v1/me/account

Sesi pemilik current/enabled/verified/aktivasi selesai. Tidak menerima parameter user_id atau query apa pun. 200:
```json
{
  "data": {
    "full_name": "Admin Uji",
    "email": "admin-uji@example.test",
    "email_verified": true,
    "email_change": {"active_email":"admin-uji@example.test","retry_after":0,"pending":null}
  },
  "meta": {"request_id":"4741bd90-1bdf-464f-9874-65ec278af437"}
}
```

email_change mengikuti kontrak tahap 09. Akun operasional menggunakan endpoint ini, tanpa GET/PATCH profil anggota atau pembuatan profil fiktif. POST perubahan email existing tetap memerlukan password dan kebijakan sesi tahap 09.

### Limit, audit dan delivery

Provisioning/resend dan accept: 5/menit/IP, 20/jam/IP, 10/jam/actor (guest IP), 5/jam/recipient; recipient email hash atau target ID pada resend. Baca undangan 60/menit/actor. 429 mempertahankan Retry-After; 400 JSON/query invalid, 413 payload, 415 content type, 419 CSRF, 401 session, 403 permission/status, 409 konflik, 422 validasi, 503 layanan. Tidak retry mutation otomatis pada 419/timeout.

Audit whitelist: admin.account_created, admin.invitation_issued, admin.invitation_accepted, security.super_admin_bootstrapped; actor, target user, waktu dan request_id tanpa password/token/email penuh pada payload audit. Bootstrap CLI actor NULL. Outbox admin_invitation memakai payload_encrypted proof sementara; job hanya delivery ID. Retry/backoff/lease/sweep existing, tidak menerbitkan token baru atau melakukan SMTP dalam transaksi. Stale/expired/consumed/revoked dibatalkan; payload dibersihkan pada sent/cancelled/attempt terakhir. LocalMailScope untuk tipe normal tetap hanya @example.test melalui Mailpit/mail-local; pengecualian aktivasi awal yang terbatas dijelaskan di bawah.

Tidak ada endpoint pembuatan Super Admin, promosi akun existing, role editor, penghapusan audit atau akses password/token. Bootstrap terpisah pada panduan operasional tahap 11.

### Aktivasi akun operasional pertama — terpisah dari pemberian role

Tidak ada endpoint penerbitan, resend, pembuatan akun, atau pemilihan kandidat awal. CLI lokal `admin:activate-first-account --user-id=… --email=…` memerlukan target eksplisit, enabled/unverified, tanpa role/profil/membership/provisioning serta fence bootstrap pertama yang belum selesai. Penerbitan pertama mengikat kandidat; ID/email lain ditolak. Undangan purpose `first_account_activation` berlaku 24 jam, cooldown CLI 60 detik dan maksimal lima penerbitan/jam/akun; resend mencabut proof/delivery lama. Perintah tidak mengeluarkan proof atau password. Pengiriman terkontrol memakai `admin:deliver-first-activation` dengan ID/email sama, queue database `mail-bootstrap`, SMTP Mailpit loopback saja. Tipe notifikasi normal tetap terbatas penerima sintetis pada development.

`GET /activate-first-account` pada frontend hanya menampilkan form, noindex; fragment proof dihapus dari address bar dan disimpan di memori tab yang terpisah dari undangan Admin. Tidak ada efek aktivasi dari GET.

`POST /api/v1/auth/first-account-activation/accept`

Publik, tetap memerlukan Origin/Referer stateful, cookie sesi tamu dan XSRF dari `/sanctum/csrf-cookie`. JSON maksimal 8 KB; tidak menerima query/field tambahan (`role`, `purpose`, `status`, `actor_id`, `user_id`, `email_verified_at`). Field sama seperti accept undangan Admin:

```json
{
  "invitation_id": "00000000-0000-4000-8000-000000000000",
  "email": "initial@example.test",
  "token": "<64 karakter hex dari undangan khusus, bukan proof aktif>",
  "password": "<password pilihan penerima, minimal 12 karakter>",
  "password_confirmation": "<konfirmasi yang sama>"
}
```

Email trim/lowercase; password maksimal 72 byte tanpa null, konfirmasi cocok. Dalam transaksi lock user → fence → invitation, periksa purpose, issuer NULL, ID/email/token hash, versi akun, expiry, consumed/revoked, eligibility dan belum ada bootstrap/Super. Token Admin/reset tidak dapat digunakan untuk purpose ini; proof awal ditolak endpoint Admin/reset. Sesi yang sudah login ditolak 403 INVITATION_SESSION_CONFLICT dan identitasnya tetap. Invalid/expired/revoked/consumed/stale/blocked/bootstrap selesai memakai error aman yang sama: **422 FIRST_ACTIVATION_INVALID**. Field invalid memakai 422 VALIDATION_FAILED. CSRF 419; rate limit 429 dengan Retry-After (limit terpisah 5/menit/IP, 20/jam/IP, 10/jam/guest IP, 5/jam/recipient hash); JSON/type/size memakai 400/415/413 seperti undangan Admin.

Sukses 200, Cache-Control no-store, X-Robots-Tag noindex,nofollow:

```json
{
  "data": { "result": "first_account_activated" },
  "meta": { "request_id": "00000000-0000-4000-8000-000000000000" }
}
```

Finalisasi menetapkan password, email_verified_at, consumed_at, auth_version +1, rotasi remember token, menghapus sesi/broker proof, membatalkan proof/delivery terkait dan audit security.first_activation_accepted secara atomik. **Tidak memberikan role, permission, profil, membership, nomor atau QR; tidak auto-login.** Penerbitan diaudit security.first_activation_issued, actor NULL. Job hanya delivery ID dan proof retry sementara terenkripsi; retry tidak menerbitkan proof baru. Reset membatalkan undangan ini tanpa memverifikasi email.

Setelah penerima sendiri menyelesaikan aktivasi, operator memeriksa status sebelum `admin:bootstrap-super` existing, tetap satu kali/enabled/verified dengan ID/email sama. Inbox Mailpit membuktikan alur lokal, bukan kepemilikan alamat produksi. Persyaratan dan hasil penerapan pada panduan Stage11.
