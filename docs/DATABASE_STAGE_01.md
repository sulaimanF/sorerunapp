# Database tahap 01 — akun dan membership

Acuan: ARCHITECTURE.md Bagian 7–9, 11.1–11.4, 11.6–11.7, dan 14; PRD.md BR-01–BR-06 serta keputusan final pada Bagian 4.2/19. Dokumen ini mencatat implementasi tahap schema, tanpa mengubah dokumen final.

## Cakupan dan constraint

| Objek | Implementasi |
| --- | --- |
| users | email VARCHAR(254) dengan unique index existing, status enabled/blocked, auth_version positif default 1, blocked_at; timestamp akun DATETIME(6) |
| roles | BIGINT id, code unik member/staff/admin, name; CoreRolesSeeder idempotent |
| user_roles | PK gabungan user_id/role_id sekaligus unique assignment; actor nullable; assigned_at DATETIME(6) |
| member_profiles | Satu per user; full_name, birth_date, phone, city wajib; gender/photo_path opsional; nama dan telepon tidak unique |
| memberships | user_id nullable unique; member_number nullable unique; empat status final; indeks status/joined_at dan ended_at |
| member_number_sequences | PK name; satu row membership dengan next_value positif, diinisialisasi sekali dalam migration |
| member_credentials | token_hash BINARY(32) unique; ciphertext nullable untuk cleanup setelah revoke; FK membership RESTRICT; actor SET NULL |
| policy_acknowledgements | Jenis dan versi kebijakan serta waktu pengakuan; indeks user/type/version; tidak ada persetujuan promosi otomatis |
| audit_logs | Actor nullable, target, action, waktu, alasan, JSON before/after, request_id; tidak ada payload credential |
| notification_deliveries | Outbox dengan dedupe_key unique, status, attempt_count, waktu lease/retry/delivery; tanpa salinan token/email dalam payload |

Semua tabel baru memakai InnoDB dan utf8mb4. Timestamp domain bertipe DATETIME(6), tanggal lahir DATE. Kolom status memakai VARCHAR dengan PHP backed enum dan CHECK constraint. Status/codes penting menggunakan collation utf8mb4_bin agar pemeriksaan nilai tidak menjadi case-insensitive.

Satu QR aktif dijaga oleh generated column tersimpan:

```sql
CASE WHEN revoked_at IS NULL THEN membership_id ELSE NULL END
```

Unique index pada active_membership_id membatasi satu row aktif per membership, sambil mengizinkan banyak row revoked. token_hash tetap unik walaupun credential revoked. Token aktif wajib memiliki ciphertext. Tidak ada FK menuju generated column; FK membership_id memakai RESTRICT untuk delete/update, sesuai batas MySQL generated column.

Membership yang sudah keluar dari pending harus mempunyai nomor dan joined_at. Tombstone anonymized harus ended, user_id NULL, mempertahankan nomor, dan membersihkan status_reason. Constraint ini hanya menjaga bentuk record; Action bisnis tetap wajib memeriksa verifikasi email, kelengkapan profil, status akun, izin admin, dan transisi yang diizinkan.

Status teknis outbox adalah pending/processing/sent/failed/cancelled. processing menandai lease worker; cancelled untuk delivery yang tidak lagi relevan. Ini detail implementasi worker, bukan status membership baru.

## Penghapusan dan rollback

FK profil, membership, assignment role, policy, dan credential memakai RESTRICT untuk mencegah penghapusan sejarah tanpa Action privacy. Actor audit/credential/assignment serta penerima outbox yang boleh terhapus memakai SET NULL. Penghapusan seluruh relasi PII memerlukan Action retensi/privasi pada tahap berikutnya; belum tersedia endpoint hapus.

Migration users bersifat additive untuk kolom bisnis dan mempertahankan name bawaan. down() menghapus tambahan kolom/status constraint dan memperluas email ke 255; DATETIME(6) dipertahankan agar presisi waktu yang sudah tersimpan tidak hilang. Rollback schema tidak dijanjikan sebagai pemulihan data. Tidak ada perubahan terhadap migration Laravel yang sudah dijalankan.

## Yang dibuktikan suite schema

Suite MySQL asli memeriksa engine/charset, tipe hash/timestamp/generated column, CHECK status, unique email/profil/membership/nomor/hash/role/delivery, revocation yang membebaskan slot QR aktif, bentuk tombstone, FK sejarah, serta seed role yang tidak membuat akun atau menggandakan ID.

Suite ini belum membuktikan row locking paralel, idempotensi aktivasi, keamanan API, enkripsi pada response, delivery email, atau kuota event. Hal tersebut diuji bersama Action/endpoints yang terkait. Tidak ada klaim seluruh schema produksi atau aplikasi telah selesai.

## Sumber teknis

- https://laravel.com/docs/13.x/migrations
- https://laravel.com/docs/13.x/database-testing
- https://dev.mysql.com/doc/refman/8.4/en/create-table-generated-columns.html
