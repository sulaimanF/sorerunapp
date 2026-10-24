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
      "email_verified": false
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

Member unverified boleh login. Membership pending/active/suspended/ended tidak menggantikan status akun enabled/blocked. Login tidak mengubah status membership, verifikasi email, nomor, QR, atau outbox. Akun enabled tanpa membership juga boleh membaca identitas sendiri; endpoint bisnis berikutnya tetap memeriksa eligibility masing-masing.

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

Endpoint reset password, block admin, dan pencabutan semua sesi oleh admin belum tersedia. Verifikasi email dan aktivasi tersedia pada tahap 05 di bawah. Tidak menandai status pending sebagai verified untuk menyelesaikan smoke test.

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
| POST /auth/email-verification/resend | Cookie pemilik, CSRF, sesi current | 202 verification_requested; 429 cooldown/rate limit |
| GET /auth/email-verification/{id}/{hash}?expires=…&v=…&signature=… | Cookie pemilik, sesi current, signed URL 60 menit | 200 hasil verifikasi/aktivasi |
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

Email verifikasi baru membuka FRONTEND_URL/verify-email dengan signed API URL di fragment #verification=. Laravel tetap membuat signed URL API melalui forUser(); forBrowser() hanya membungkusnya untuk navigasi browser. Fragment tidak dikirim ke server Next.js. Frontend memvalidasi origin/path/query, menghapus fragment dari address bar, menyimpan intent hanya di memori tab, meminta login pemilik bila perlu, lalu GET ke signed API URL setelah konfirmasi pengguna. Tidak ada signature di router query, localStorage, sessionStorage, atau Query key. Reload sesudah fragment dihapus mengharuskan pengguna membuka kembali email.

forUser(), TTL 60 menit, authenticated owner, hash email/version, dan transaksi aktivasi tetap berlaku. Email lama dengan URL API langsung tetap valid selama belum kedaluwarsa. Setelah upgrade, restart queue worker dan gunakan email baru untuk mencoba alur browser. Postman tahap 05 diperbarui untuk mengekstrak URL API dari format email lama maupun fragment baru.

CORS mengekspos Retry-After dan X-Request-ID agar browser dapat menampilkan cooldown/support ID yang sudah diberikan API. Origin/credentials tetap dibatasi seperti sebelumnya. Tidak ada endpoint atau migration baru. Kartu tetap hanya tersedia melalui API privat; frontend merender credential asli menjadi SVG QR hitam-putih dengan quiet zone empat modul, tanpa mengirim data kartu melalui SSR.

## Tahap 07 — Biodata profil sendiri

Tambahan `GET /api/v1/me/profile` dan `PATCH /api/v1/me/profile` menggunakan sesi browser, ownership, CSRF untuk PATCH, response no-store, serta audit field yang berubah. Kontrak lengkap: [PROFILE_API_STAGE_07.md](PROFILE_API_STAGE_07.md). Endpoint akun dan alur login tahap 06 dipertahankan.
