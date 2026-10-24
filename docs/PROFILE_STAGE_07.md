# Tahap 07 — Biodata Anggota

Tanggal: 24 Oktober 2026. Acuan bisnis: PRD final FR-04, BR-18, BP-02 serta ketentuan aktivasi otomatis; arsitektur Bagian 7–8, 11–12 dan urutan implementasi Bagian 20; design system untuk form profil dan state error/loading; aturan AGENTS.md.

## Hasil implementasi

- API `GET /api/v1/me/profile` dan `PATCH /api/v1/me/profile` untuk pengguna yang sedang login.
- Editor `/profil`: nama lengkap, tanggal lahir, telepon, domisili, serta jenis kelamin opsional.
- Validasi UX memakai React Hook Form + Zod; validasi akhir memakai FormRequest Laravel. Field wajib tidak dapat dikosongkan; tidak ada batas usia minimum; telepon dinormalisasi di backend.
- Model profil yang sudah tersedia dipakai kembali; tidak ada migration atau perubahan tabel.
- Resource whitelist dengan no-store; ID pemilik tidak dipilih oleh client. Policy serta pemeriksaan sesi/status diulang setelah row lock.
- Perubahan profil, sinkronisasi nama user, audit, dan aktivasi pending yang terkait berada dalam satu transaksi.
- Aktivasi karena verifikasi email dan karena pelengkapan profil menggunakan `ActivatePendingMembership` yang sama. Action tidak memverifikasi email; hanya pengguna yang emailnya sudah verified yang dapat diaktifkan lewat pelengkapan profil.
- Nama/identitas baru diambil kembali oleh API sesi dan kartu; nomor member, QR aktif, joined_at, serta auth_version tidak diganti pada edit profil yang sudah aktif.
- Status suspended/ended tidak dipulihkan melalui editor profil.
- Audit `member.profile_updated` menyimpan field yang berubah, bukan salinan biodata. PATCH yang identik tidak menambah audit profil.
- Frontend mempertahankan input saat error. Hasil timeout/5xx dapat diperiksa dengan GET tanpa mengulang PATCH otomatis.
- Request di-abort saat editor dilepas. Hasil mutation hanya diterapkan ke cache jika akun sesi masih sama, termasuk pemeriksaan ulang setelah pembatalan query.

## Batas tahap

Tahap 07 berfokus pada biodata sendiri. Upload foto, penggantian email/password, lupa/reset password, admin anggota/status/QR, event, scanner, kehadiran, dan laporan tetap mengikuti tahapan berikutnya. Tidak ada endpoint atau fallback data palsu untuk fitur tersebut.

Alur login awal dipertahankan. Paket tidak memuat login-form, session-provider, globals.css, atau empat dokumen final. Salinan SYSTEM_DESIGN.md di lingkungan penyusunan masih 1.0; keputusan Sunset 1.1 yang sudah disetujui pengguna diterapkan dengan memakai token/komponen existing, tanpa mengganti CSS atau dokumen desain pada project pengguna.

## Alur penyimpanan

1. Browser memuat identitas sesi dan biodata sendiri melalui API.
2. Member memperbaiki biodata; background focus tidak menimpa isi editor yang belum disimpan. Pemeriksaan sesi umum tetap aktif.
3. Submit valid mengambil CSRF lalu mengirim PATCH berisi lima field yang diizinkan. Tombol/field dinonaktifkan selama penyimpanan.
4. Backend mengunci membership → user → profil, memeriksa akun/sesi/policy, menyimpan perubahan dan audit. Pending verified yang profilnya lengkap diaktifkan dalam transaksi yang sama.
5. Setelah response sukses, form memakai nilai hasil normalisasi server. Sesi dan query kartu di-invalidasi untuk mengambil identitas terbaru.
6. Saat error field, input tetap tersedia dan fokus kembali ke field terkait. Saat status hasil tidak diketahui, member dapat mengambil ulang data tersimpan secara eksplisit.

Kontrak lengkap: [PROFILE_API_STAGE_07.md](../backend/docs/PROFILE_API_STAGE_07.md). Cara penerapan: [PANDUAN_TAHAP_07.md](PANDUAN_TAHAP_07.md). Bukti pemeriksaan: [VERIFICATION_STAGE_07.md](VERIFICATION_STAGE_07.md).
