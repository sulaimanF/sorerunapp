# Kontrak API Profil — Tahap 07

Endpoint memakai base `/api/v1`. Semua operasi membutuhkan sesi browser Sanctum, akun enabled, auth_version sesi terkini, dan ownership. Pengguna unverified, suspended, atau ended boleh memperbaiki profil sendiri selama akunnya enabled. Perubahan profil tidak memulihkan status suspended/ended.

## GET /me/profile

Mengambil biodata pengguna yang login, bukan berdasarkan ID dari client. Query `user_id` tidak memilih pemilik lain. Tidak tersedia route `/me/profile/{id}`. Response `200`, `Cache-Control: no-store`, dan `meta.request_id` sama dengan `X-Request-ID`.

```json
{
  "data": {
    "full_name": "Anggota Profil Uji",
    "birth_date": "2012-02-29",
    "phone": "+6281234567890",
    "city": "Kota Uji",
    "gender": null,
    "profile_complete": true,
    "updated_at": "2026-10-24T00:00:00Z"
  },
  "meta": { "request_id": "c9340265-31f2-4f5c-a951-7631bf8b2c44" }
}
```

Tidak mengirim ID user/profil lain, role, auth_version, email, password, path foto, token, atau QR melalui resource ini. Email/status akun tetap berasal dari `/auth/me`.

## PATCH /me/profile

JSON object maksimum 16 KiB, CSRF header `X-XSRF-TOKEN`, credentials dan Origin/Referer browser yang dipercaya. Rate limit: 60 permintaan/menit pada sesi pengguna. PATCH menerima sebagian field; field yang tidak dikirim tetap dipertahankan. Harus ada minimal satu field yang dapat diedit. Field asing ditolak `422`, termasuk email, user_id, role, password, photo_path, member_number, dan status.

| Field | Ketentuan jika dikirim |
| --- | --- |
| full_name | Teks, trim, 2–150 karakter |
| birth_date | `YYYY-MM-DD`, tanggal kalender valid, bukan masa depan; tanpa batas usia minimum |
| phone | Teks, normalisasi nomor ke format internasional; 7–15 digit setelah tanda +, digit awal bukan 0 |
| city | Teks wajib tidak kosong, trim, maksimum 100 karakter |
| gender | Teks opsional maksimum 32 karakter; `null` atau string kosong menghapus nilai opsional |

```json
{
  "full_name": "Anggota Profil Uji",
  "birth_date": "2012-02-29",
  "phone": "081234567890",
  "city": "Kota Uji",
  "gender": null
}
```

Response `200` memakai resource yang sama dengan GET. Field wajib tidak dapat dikosongkan. Nomor telepon lokal berawalan 0 dinormalisasi menjadi +62; tanda pemisah umum dihapus. Nama user diselaraskan dengan full_name profil. `updated_at` UTC merupakan informasi waktu, bukan token optimistic locking. Request bersamaan diserialkan oleh row lock; setiap PATCH hanya mengubah field yang dikirim, nilai field yang sama mengikuti transaksi terakhir.

## Transaksi, aktivasi, dan audit

Action mengunci membership → user → profil. Status akun serta versi sesi diperiksa lagi di dalam transaksi; policy memeriksa pemilik. Data, sinkronisasi nama, audit, dan aktivasi terkait commit atau rollback bersama.

Keanggotaan pending yang emailnya sudah verified diaktifkan setelah profil lengkap, menggunakan action alokasi nomor/QR yang sama dengan verifikasi email. Pengguna unverified tidak menjadi verified karena mengedit profil. Status active/suspended/ended, nomor lama, joined_at, credential aktif, serta auth_version tidak diubah oleh edit profil biasa. Aktivasi pending dapat membuat satu outbox pemberitahuan aktivasi; tidak ada email untuk setiap edit biodata.

Audit `member.profile_updated` mencatat actor, target `member_profile`, waktu, request_id, dan `after.changed_fields`. Tidak menyimpan salinan nilai biodata sebelum/sesudah. PATCH identik tanpa perubahan tidak menambah audit profil. Jika aktivasi gagal, perubahan profil dan alokasi nomor/QR ikut rollback.

Tidak ada migration, upload foto, penggantian email/password, pemulihan password, atau endpoint admin baru dalam tahap ini.

## Respons ditolak

| HTTP / code | Makna |
| --- | --- |
| 400 INVALID_JSON | JSON rusak atau bukan object |
| 401 UNAUTHENTICATED / SESSION_EXPIRED | Tidak ada sesi browser sah atau versi sesi usang |
| 403 ACCOUNT_BLOCKED / FORBIDDEN | Akun diblokir atau policy menolak |
| 404 PROFILE_NOT_FOUND | Profil belum tersedia; tidak dibuat otomatis dari endpoint ini |
| 413 PAYLOAD_TOO_LARGE | Body lebih dari 16 KiB |
| 415 UNSUPPORTED_MEDIA_TYPE | PATCH tidak memakai JSON |
| 419 CSRF_MISMATCH | CSRF tidak sesuai |
| 422 VALIDATION_FAILED | Validasi field atau field asing |
| 429 RATE_LIMITED | Pembatasan permintaan |
| 503 | Gangguan sementara, lock retry habis, atau konfigurasi nomor saat aktivasi belum tersedia |

Semua error mengikuti envelope API yang ada tanpa SQL, path internal, atau biodata. UI mempertahankan input pada error. Setelah timeout/5xx, hasil penyimpanan bisa belum terkonfirmasi; pengguna dapat memilih **Periksa Data Tersimpan** yang melakukan GET, bukan mengulang PATCH otomatis.
