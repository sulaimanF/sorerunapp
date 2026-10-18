# Model tahap 02 — akun dan membership

Acuan implementasi: AGENTS.md Bagian 3, 6–7; ARCHITECTURE.md Bagian 7–9, 11, 14. Tidak mengubah PRD/ARCHITECTURE/SYSTEM_DESIGN/AGENTS final.

## Relasi

| Model | Relasi |
| --- | --- |
| User | memberProfile hasOne, membership hasOne, roles belongsToMany via UserRole, policyAcknowledgements/notificationDeliveries/auditLogs hasMany |
| Role | users belongsToMany via UserRole |
| UserRole | user, role, assignedBy belongsTo; pivot tanpa timestamps otomatis |
| MemberProfile | user belongsTo |
| Membership | user belongsTo, credentials hasMany, activeCredential hasOne dengan revoked_at NULL |
| MemberCredential | membership, issuedBy, revokedBy belongsTo |
| PolicyAcknowledgement | user belongsTo; acknowledged_at eksplisit |
| AuditLog | actor belongsTo; occurred_at eksplisit |
| NotificationDelivery | user belongsTo |
| MemberNumberSequence | PK string name, tanpa auto increment atau timestamps |

## Cast dan penjagaan

Status akun memakai AccountStatus; membership memakai MembershipStatus; role memakai RoleCode; delivery memakai NotificationDeliveryStatus. Default status untuk model baru diselaraskan dengan default database. Timestamp memakai immutable_datetime dan format penyimpanan Y-m-d H:i:s.u. birth_date memakai immutable_date dengan serialisasi Y-m-d.

User mempertahankan atribut Fillable(name,email,password). Mutator email membuang whitespace luar dan mengubah huruf menjadi kecil, tanpa aturan khusus titik/plus Gmail. Password tetap hashed. UserFactory, HasFactory, Notifiable, serta kontrak autentikasi Laravel dipertahankan; MustVerifyEmail diaktifkan sebagai kontrak model.

MemberProfile mengizinkan mass assignment data profil yang diedit pengguna, tetapi user_id dan photo_path dikelola server. Membership/credential/role/sequence/policy/audit/delivery menggunakan guard default yang ketat. UserRole mengizinkan hanya metadata assigned_by/assigned_at untuk mekanisme custom pivot Laravel; user_id/role_id tetap guarded. Action wajib mengambil actor dari session dan waktu dari server serta memeriksa otorisasi sebelum attach.

MemberCredential memakai encrypted cast pada token_encrypted. Kolom token_hash, token_encrypted, dan active_membership_id tidak muncul di serialisasi umum. Photo storage path, auth_version, password/remember_token, payload audit, dan detail internal delivery juga disembunyikan sesuai konteks model. API Resource whitelist tetap wajib; Hidden bukan policy akses.

Tidak ada observer yang memicu email, membership, nomor, atau QR hanya karena save(). Action transaksi akan menjadi pemilik seluruh transisi. Model tidak menggantikan middleware akun enabled, CSRF, ownership, policy, row lock, atau unique/check constraint MySQL.

## Bukti uji yang disediakan

Lima belas test model mencakup normalisasi email, hashed password, guard field, enum/timestamp, relasi owner, kompatibilitas UserFactory dan MustVerifyEmail, profil anggota anak, pivot/actor/waktu, enkripsi serta serialisasi QR, pemilihan credential aktif, presisi mikrodetik, policy/audit/outbox, sequence string key, tidak ada side effect aktivasi saat save, dan kegagalan dekripsi yang tidak mengganti credential.

phpunit.models.xml menjalankan test tersebut bersama 18 test schema tahap 01 pada sorerunapp_test / MySQL 8.4. Tidak ada klaim concurrency atau API telah diuji sebelum Action/endpoint terkait dibuat.

## Referensi primer

- https://laravel.com/docs/13.x/eloquent
- https://laravel.com/docs/13.x/eloquent-mutators
- https://laravel.com/docs/13.x/eloquent-relationships
- https://laravel.com/docs/13.x/verification
