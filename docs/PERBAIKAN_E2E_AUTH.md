# Perbaikan dua kegagalan E2E auth

Tanggal: 25 Oktober 2026.

## Arti kegagalan

1. Test navigasi memakai heading `Profil Anggota` dari tampilan tahap 6 sebagai penanda selesai berpindah halaman. Heading tersebut tidak ditemukan pada proyek Anda. Ini belum membuktikan sesi login terputus. Test ini sekarang memeriksa URL profil, penanda menu Profil aktif, sesi yang tetap masuk dengan satu kali login, tombol logout yang dapat digunakan, serta tujuan logout `/login`. Editor profil dan data profil tetap diperiksa oleh suite profil tersendiri.
2. Pada pemeriksaan layout, koordinat kedua tautan menunjukkan keduanya belum tersusun vertikal ketika diukur. Log ini tidak cukup untuk memastikan apakah blok CSS belum tergabung, CSS belum termuat, atau cache lama masih terpakai. Patch memastikan blok auth tersedia melalui script merger existing; cache build dibangun ulang. Test sekarang menunggu dan memeriksa computed CSS flex/column, gap 12 px dan margin 28 px sebelum pemeriksaan koordinat yang tetap dipertahankan. CSS yang tidak termuat tetap menyebabkan test gagal; tidak ada skip, force click, atau penambahan sleep.

Pesan `[WebServer] Route ... server caches disabled` bukan assertion yang membuat kedua test tersebut gagal. Tidak perlu menonaktifkan Cache Components, CSRF, atau aturan sesi.

## Terapkan patch

1. Hentikan semua `npm run dev` frontend di terminal lain dengan Ctrl+C. Jangan menjalankan dua server Next.js pada folder frontend yang sama selama pengujian ini.
2. Ekstrak paket perbaikan ke `C:\laragon\www\sorerunapp`, mengikuti struktur folder di ZIP. Ganti hanya file yang disertakan. Paket ini merupakan tambahan untuk paket auth-navigation-ui-globals yang sudah Anda terapkan.
3. Dari Git Bash, jalankan berurutan. Jika satu perintah gagal, hentikan langkah berikutnya dan periksa output-nya.

```bash
cd /c/laragon/www/sorerunapp
node scripts/centralize-auth-css.cjs
```

Output sukses dapat berbunyi `Styling auth sudah terpusat di globals.css. Tidak ada perubahan ulang.` Itu normal jika CSS sudah tergabung sebelumnya. Jika script menolak perubahan CSS lokal, jangan hapus modifikasi tersebut; periksa konflik yang disebutkan terlebih dahulu. Script mempertahankan isi globals.css dan membuat backup sebelum perubahan.

Kemudian:

```bash
cd /c/laragon/www/sorerunapp/frontend
rm -rf .next
npx --no-install next typegen
npm run lint
npm run typecheck
npm run test:e2e
```

`.next` adalah cache/build generated Next.js, dibuat ulang oleh framework. Perintah di atas tidak menghapus source, `.env.local`, atau database. Tidak perlu menghapus node_modules, menginstal dependency, menjalankan migration, ataupun menyalakan frontend manual untuk E2E; Playwright menjalankan server sesuai konfigurasi proyek.

Untuk menjalankan hanya dua test yang sebelumnya gagal lebih dahulu:

```bash
npm run test:e2e -- tests/browser/auth-navigation.spec.ts -g 'a valid session survives|approved auth footers'
```

Setelah itu jalankan seluruh `npm run test:e2e`, sehingga sembilan test profil tahap 7 Anda tetap ikut diuji. Jumlah di proyek Anda tetap 31 jika tidak ada penambahan test lain.

## Cakupan dan pemeriksaan penyusunan

File patch:

- `frontend/tests/browser/auth-navigation.spec.ts`: sinkronisasi shell navigasi dan pemeriksaan computed CSS sebelum geometri.
- `scripts/centralize-auth-css.cjs`: script merger dari paket sebelumnya, disertakan agar langkah penerapan lengkap.
- Dokumen panduan ini.

Tidak mengganti profile-screen.tsx, profile.spec.ts, fake-member-api.ts, backend, palet, konfigurasi framework, dependency, maupun aturan verifikasi email sebelum login. Tidak ada perubahan alur bisnis.

Hasil di lingkungan penyusunan:

| Pemeriksaan | Hasil |
| --- | --- |
| Browser Chromium dengan API fixture sintetis | 22/22 lulus, termasuk kedua test yang dilaporkan gagal |
| ESLint | Lulus |
| TypeScript --noEmit | Lulus |
| Syntax script merger dan git diff --check | Lulus |

Sembilan test profil tahap 7 milik proyek lokal Anda tidak tersedia di lingkungan penyusunan dan tidak diklaim telah diuji ulang. Uji browser memakai API fixture, bukan integrasi Laravel/MySQL/SMTP nyata. Build produksi dan test PHP tidak diulang untuk perubahan test ini. Tidak ada commit, push, deploy, atau pengiriman email nyata yang dijalankan.

Jika masih gagal pada computed CSS setelah langkah di atas, data yang diperlukan untuk diagnosis berikutnya adalah output script merger dan `test-results/.../error-context.md` untuk test yang gagal, beserta blok `BEGIN: SRC auth layout` sampai `END: SRC auth layout` dari globals.css. Jangan sertakan `.env.local`, password, atau data akun nyata.
