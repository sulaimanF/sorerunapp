# Landing page publik dan editor admin — tahap 10

Tanggal: 14 November 2026. Acuan PRD FR-19–21, AC-26/31–35; ARCHITECTURE §5/7.1.1/11.8/12.4; SYSTEM_DESIGN §11.5. Rencana dan batas upload/publikasi/SSR telah disetujui pemilik produk.

**Status 20 November 2026: review ulang, perbaikan aksesibilitas dan pengujian terisolasi; menunggu persetujuan penerapan development lokal.** Riwayat 14 November: implementasi disiapkan tanpa pemeriksaan eksekusi. Hasil 15 November dipertahankan di bawah; hasil sesi 20 November dicatat terpisah. Pengujian memakai MySQL sorerunapp_test, storage test dan akun sintetis; tidak mengubah .env aktual, menetapkan admin aplikasi, menerapkan migration pada sorerunapp, mengirim email, commit, push atau deploy. Bukan bukti kesiapan produksi.

## Baseline dan pekerjaan yang dipertahankan

Branch dev, HEAD 35b8169. Perubahan lokal worker/scheduler/mail-local, README/.env.example/backend config operasional dan catatan Stage 09 sudah ada sebelum tugas ini. Dropdown jenis kelamin pada registrasi/profil juga dipertahankan. Tidak mengubah delivery, broker, sesi, nomor member, QR, membership, event atau attendance. Penambahan pada ARCHITECTURE/README mempertahankan catatan operasional existing. Riwayat pengujian tahap sebelumnya tidak dianggap sebagai hasil tahap 10.

## Implementasi

- `/` pada `(marketing)/page.tsx` menjadi landing, terpisah AuthShell. Redirect root di next.config.ts dihapus; Cache Components/Partial Prefetching tetap aktif. Brand link menuju root tanpa prefetch agar halaman publik tidak diambil sebelum diperlukan. Login tetap /login → /beranda, logout/expiry tetap /login tanpa returnTo.
- Review menemukan provider per layout meninggalkan state login-redirect pada layout auth yang dipertahankan Activity. Kini ApplicationScope pada root tidak memasang provider untuk `/`, sementara auth/member/admin berbagi satu AppProviders. SessionProvider existing tidak diubah; regresi transisi, notice perubahan email dan pembersihan cache privat diuji ulang. Guard admin dipasang per halaman agar segmen server tetap dapat divalidasi Next.
- Editor `/admin/landing-page`, preview `/admin/landing-page/preview`, akses dari tautan admin pada shell anggota. Backend tetap authority: sesi stateful/current, enabled/verified dan role admin; tidak memerlukan membership aktif. Tidak menyediakan pengelolaan akun/role/dashboard umum.
- Draft terstruktur versi 1: header/hero, tentang/nilai, kegiatan editorial, galeri, FAQ, kontak/footer, SEO, urutan/visibility. Tidak ada page builder, HTML, script, warna/font bebas atau CTA URL arbitrary. Section opsional kosong/hidden dikeluarkan dari tampilan/menu dan disanitasi pada snapshot publik. Konten awal kosong; tidak membuat foto/kontak/statistik/cerita resmi palsu.
- Save menggunakan optimistic version check di bawah lock; 409 mempertahankan input, menawarkan reload eksplisit. Editor tidak melakukan refetch window-focus yang menimpa draft sedang diedit. Snapshot publish, relasi media, pointer dan audit atomik. Unique page/version dan return existing mencegah publish ganda. Revisi terbit tidak dapat diubah melalui model/action/API.
- Locking read untuk lookup revisi existing dan referensi media menghindari snapshot REPEATABLE READ yang dibuat sebelum menunggu page lock. UUID input dinormalisasi; pengecekan referensi delete memakai UUID canonical row. Pembuktiannya memakai race MySQL nyata, bukan dianggap lulus melalui inspeksi saja.
- JSON admin maksimum 128 KiB/depth 16. Named limiter dipisahkan publik/read/save/publish/upload/delete/media, menggunakan hash IP dan Retry-After. Upload multipart terpisah dari middleware JSON.
- Media memakai disk landing di `storage/app/landing-private`, tidak diekspos symlink, terpisah foto privat anggota. JPEG/PNG/WebP ≤5 MB, sisi 16–6000 px, ≤16 MP dan anggaran memori decoder. GD WebP decode/reencode maksimal sisi 2400 px kualitas 85, tanpa metadata. Nama UUID baru setiap upload; alt/caption per penggunaan ikut versi dokumen.
- Preview/pustaka menggunakan API admin dan blob URL dalam memori yang dicabut saat unmount. Tidak mengirim file draft ke optimizer publik atau menyimpan token/konten di browser storage. Media publik hanya tersedia jika dipakai snapshot aktif; API/resource tidak membocorkan path, actor atau draft.
- SSR mengambil API publik tanpa cookie/header pengguna dengan no-store, timeout dan redirect error. React cache memoizes hanya satu render agar generateMetadata/body membaca snapshot sama. Tidak ada cache konten lintas request atau webhook/worker baru. Gambar hasil WebP backend memakai next/image unoptimized dengan ukuran/loading/sizes; tidak memperluas izin optimizer localhost.
- Catch SSR mempertahankan control-flow internal Next menggunakan helper rethrow yang tersedia pada versi terpasang; tidak menelan sinyal render dinamis/Partial Prerendering sebagai error API. Build/streaming diuji terisolasi. Produksi opt-in indeks juga menolak origin HTTP/loopback/.test/.localhost.
- `publication: null` adalah belum ada publikasi. Schema/API gagal tetap error; UI gangguan berbeda dari state belum publish. Metadata error noindex. Karena streaming/Suspense, HTML yang sudah mulai dikirim bisa berstatus 200; API konten tetap mengembalikan status error sebenarnya.

## Migration dan konfigurasi

Migration baru: `backend/database/migrations/2026_11_14_120000_create_landing_page_content_tables.php`.

Tabel: landing_pages (singleton draft/pointer), landing_page_revisions (snapshot), landing_page_media (file ready/deleting), landing_page_revision_media (referensi revisi). Migration hanya menambah schema dan singleton kosong. **Diterapkan hanya pada sorerunapp_test MySQL 8.4.3, batch 4**, menggunakan --path migration Stage 10; dependensi Stage 01–09 sudah tersedia. Inspeksi information_schema menemukan keempat tabel beserta PK/FK/unique/CHECK. Database aplikasi tidak dimigrasikan; inspeksi read-only memastikan jumlah tabel landing di sana 0. Tidak memakai migrate:fresh, wipe, truncate atau fallback schema. Tidak menguji rollback migration pada sesi ini.

Konfigurasi yang perlu diperiksa pada tahap berikutnya:

| Area | Ketentuan |
| --- | --- |
| Frontend canonical | SITE_URL origin tepercaya tanpa path/query/credential; contoh lokal pada frontend/.env.example |
| Indeks | LANDING_INDEXABLE default false; true hanya situs produksi publik dengan NODE_ENV production. Lokal/test/staging tetap false; akun/admin/preview noindex |
| Koneksi SSR | Next server harus dapat mengakses NEXT_PUBLIC_API_BASE_URL; tidak meneruskan cookie pengguna |
| Media URLs | APP_URL Laravel origin API tepercaya, tanpa path; jangan mengandalkan Host client |
| Runtime gambar | PHP CLI/server 8.4.12, GD/WebP tersedia dan re-encoding JPEG/PNG/WebP nyata diuji. Jika tidak tersedia, upload 503 IMAGE_PROCESSOR_UNAVAILABLE, bukan sukses palsu |
| PHP/webserver | upload_max_filesize minimal 5 MB; post_max_size/request body lebih besar untuk overhead multipart; memori decoder diperiksa sebelum decode |
| Storage | Disk landing privat writable/persisten, termasuk backup yang sesuai; tidak menjalankan storage:link untuk disk ini |
| Admin | Perlu akun enabled/verified dengan role admin. Keberadaan/penetapannya tidak dilakukan pada sesi ini; tanpa promosi role atau password default |

Jangan menjalankan migration pada aplikasi hanya untuk mencoba UI. Helper tests/Support/prepare-landing-database.php memaksa testing, host loopback, database sorerunapp_test, email array dan menolak cached test config; memeriksa DATABASE()/VERSION() sebelum migration. --migrate hanya menerapkan path Stage 10, --inspect read-only. Penerapan pada database aplikasi tetap memerlukan persetujuan terpisah dan verifikasi backup/target.

## Operasi editorial dan pemulihan media

1. Admin login melalui /login; tujuan tetap /beranda. Buka Kelola Landing Page.
2. Isi form/upload privat, pilih gambar dan isi alt. Simpan Draft menaikkan versi. Kegagalan mempertahankan input; jangan retry mutation otomatis.
3. Preview Draft Tersimpan memeriksa kelengkapan calon publikasi; bagian belum lengkap bisa ditolak 422. Tidak membuat URL preview publik.
4. Publikasikan melalui dialog konfirmasi. Pengunjung pada request baru membaca snapshot aktif; edit draft sesudahnya tidak mengubah halaman publik. Tidak ada publish awal otomatis.
5. Konflik versi: simpan salinan perubahan secara manual sebelum reload draft terbaru; tidak menyimpan draft privat ke localStorage.
6. DELETE media menolak referensi draft atau semua revisi yang dipertahankan. Melepas gambar dari draft saja tidak membebaskan gambar pada revisi terbit lama. Tahap ini tidak menyediakan purge revisi.
7. Penghapusan file gagal menyisakan row state deleting. File tidak dapat dipilih/dipublikasikan; pustaka menyediakan Lanjutkan penghapusan. Setelah storage pulih, DELETE UUID yang sama melanjutkan; tidak perlu menghapus row langsung.
8. Upload gagal setelah write file mencoba menghapus file baru jika database memastikan belum attached. Jika koneksi/commit tak pasti atau cleanup gagal, file dipertahankan dan log teknis `Landing media orphan cleanup required.` hanya mencatat UUID. Rekonsiliasi manual: cocokkan UUID dengan row media pada database target serta file privat `images/<UUID>.webp`; jangan menghapus bila row/reference ada atau status database belum pasti. Penghapusan orphan manual hanya setelah target/status/backup jelas dan diotorisasi; tidak ada cleanup massal atau scheduler baru. File yang tersimpan tetapi belum dipilih draft tetap muncul pada pustaka privat, bukan orphan.

File yang pernah dipublikasikan sudah pernah diakses publik. Melepasnya dari halaman/endpoint aktif tidak menarik salinan yang telah diunduh pihak lain. Preview draft tidak pernah dibuat publik sebagai jalan pintas.

## Sumber pengujian dan hasil review 15 November 2026

| Sumber | Cakupan yang disiapkan |
| --- | --- |
| backend/phpunit.landing.xml | Memaksa testing, MySQL sorerunapp_test, DB_URL kosong, session database, cache array, email array; tidak menggunakan SQLite |
| tests/Feature/Content/LandingPageTest.php | API/no-publication, CSRF, admin tanpa membership, guest/member/staff, versi stale/role/block/verified, konflik/idempotensi, hidden/draft, audit rollback, media draft/publik/referensi, pemrosesan/rollback upload, SVG/ukuran/dimensi dan delete gagal/retry, rate limit |
| tests/Feature/Content/LandingConcurrencyTest.php + tests/Support/landing-race-worker.php | Lima race dengan dua proses PHP/koneksi MySQL nyata dan barrier: publish sama, save sama, publish bersamaan save baru, publish vs delete media, save referensi vs delete. Memeriksa connection ID berbeda dan cleanup fixture sendiri |
| frontend/tests/unit/landing.test.ts | Section opsional, schema publik, penolakan markup/URL/CTA arbitrary/section ganda |
| frontend/playwright.landing.config.ts + tests/landing/landing.spec.ts | HTTP fixture terpisah untuk SSR/browser, root publik, gagal vs belum terbit, draft/preview/publish, metadata, konflik input, admin/member, 320 px/keyboard/FAQ/reduced motion |
| frontend/tests/browser/auth-navigation.spec.ts | Assertion root 307 dipindahkan ke suite landing SSR; test auth lain dipertahankan. Browser interception tidak cukup untuk server fetch; suite root memakai HTTP fixture sendiri |

Fixture HTTP landing berjalan hanya ketika LANDING_FIXTURE_SERVER=true, bind localhost:18100, frontend localhost:3110 dan output `.next/landing-browser`. Tidak menghubungi Laravel/MySQL/email nyata. Server fixture dapat dikontrol test melalui endpoint lokal khusus; tidak masuk source aplikasi/production routes. Jangan menganggap fixture ini membuktikan permission/locking Laravel. Fixture konten sintetis hanya ada di tests/.

Command utama yang telah dijalankan setelah preflight (bukan petunjuk migration database aplikasi):

```powershell
# backend, helper memaksa MySQL sorerunapp_test dan fake mail.
php tests/Support/prepare-landing-database.php
php tests/Support/prepare-landing-database.php --migrate
php tests/Support/prepare-landing-database.php --inspect
php vendor/bin/phpunit --configuration=phpunit.landing.xml
# Pint/Pint --test dibatasi file Stage 10; tidak memformat modul worker/scheduler.

# frontend:
npm.cmd run lint
npm.cmd run typecheck
npm.cmd test
npx.cmd --no-install playwright test --config=playwright.landing.config.ts
npm.cmd run test:e2e
npx.cmd --no-install playwright test --config=playwright.landing-live.config.ts
# Build: SORERUNAPP_TEST_OUTPUT=landing-build, API=http://localhost:8110,
# SITE_URL=http://localhost:3111, LANDING_INDEXABLE=false, telemetry disabled.
npm.cmd run build
# Sesudah build, set LANDING_PRODUCTION_TEST=true untuk suite landing-live
# agar memakai next start, SITE_URL HTTPS sintetis dan opt-in indexability.
```

Checklist awal 14 November mencakup rollback relasi/audit, multipart/CSRF, MIME/metadata, media invalid/deleting, storage/orphan, SEO/SSR, cache privat dan auth. Hasil aktual serta batas yang belum dijalankan dicatat di bawah; checklist awal tidak dianggap bukti kelulusan.

Integrasi nyata browser → Laravel → MySQL test → disk test telah dijalankan terpisah dari fixture, pada next dev dan next start. Router/bridge Stage 10 hanya di tests/Support dan memaksa target test, mail array, queue sync tanpa dispatch outbox. Sanctum memakai CSRF nyata (shortcut testing dinonaktifkan pada router). API localhost:8110, frontend localhost:3111, cookie stage10_live_session, cache database prefix stage10_live_, disk framework/testing/stage10-media; tidak memakai server/storage aplikasi. Tidak memerlukan Mailpit karena Stage 10 tidak mengirim email.

### Temuan dan perbaikan

- Validasi caption nullable sebelumnya mewajibkan child saat image null; diperbaiki tanpa melonggarkan required_array_keys pada objek gambar. Draft kosong/gambar null dan gambar tidak lengkap diuji.
- Import ApiError diubah menjadi import nilai; rendering error tidak lagi membuat JSX dalam try, pemantauan RHF memakai useWatch compute, handler submit tidak membaca ref saat render. Tidak menonaktifkan aturan ESLint/React Compiler.
- Batas provider sesi diperbaiki sebagaimana di atas. Test login ulang setelah perpindahan menu/logout sebelumnya hang, kini diuji ulang bersama seluruh regresi.
- Radius CSS yang menunjuk token tidak tersedia diganti ukuran existing yang sesuai; tidak mengubah palet/font.
- Request media admin tanpa Accept JSON sebelumnya memicu redirect login backend yang tidak tersedia (500). Callback guest kini mengembalikan respons 401 API untuk api/*, termasuk Accept image/html; tidak menambah route login backend atau melewati middleware. Ditambah test header negatif dan regresi auth diulang.
- Selector test menyesuaikan label opsional dan alert aplikasi. Satu test email-change existing menangkap route announcer Next yang kosong sebelum error request; sekarang menunggu alert berisi teks, dengan ekspektasi satu request/tanpa retry tetap sama. Tidak menghapus cakupan auth selain mengganti ekspektasi root yang memang berubah.
- Filename modul Stage 10 diperiksa dari filesystem; tidak ditemukan nama non-ASCII/karakter tidak sengaja.

### Hasil eksekusi

| Pemeriksaan | Hasil |
| --- | --- |
| PHPUnit phpunit.landing.xml | 30 test, 338 assertion lulus; termasuk lima race MySQL nyata, upload/reencode, CSRF/limit, permission/session, rollback, orphan recovery dan akses tamu media non-JSON |
| Regresi phpunit.passwords.xml dengan filter BrowserSessionTest, EmailVerificationTest, PasswordResetTest, EmailChangeTest | 122 test, 1129 assertion lulus pada MySQL test; bukan seluruh suite historis |
| npm test | 23 unit test lulus, termasuk schema landing dan pembersihan cache lintas akun |
| npm run lint; npm run typecheck | Lulus tanpa menonaktifkan aturan atau mengecualikan source aplikasi |
| Playwright landing (HTTP fixture) | 7 test lulus: root, SSR/snapshot/metadata/fresh request tanpa cookie, error/null, draft/preview/publish, konflik, member ditolak, keyboard/mobile/gambar gagal dan transisi landing/login |
| npm run test:e2e | 71 test regresi fixture lulus: login/loading/menu/logout/dialog/expiry, verify/resend, reset, email change dan cache privat |
| Playwright landing-live | Integrasi nyata lulus pada next dev dan next start, dengan gambar GD sintetis; bukan fixture API |
| npm run build | Lulus pada .next/landing-build, API test 8110; root Partial Prerender, Cache Components/Partial Prefetching tetap aktif; tanpa data palsu/build aplikasi |
| Pint --test | Lulus khusus file Stage 10; formatter dijalankan pada file Stage 10 yang perlu dirapikan |

Unit test awal di sandbox gagal pada uv_os_get_passwd/ENOMEM; pengulangan di luar sandbox lulus, bukan kegagalan schema aplikasi. Percobaan awal lint/type/backend/browser juga menemukan masalah di atas, kemudian diperbaiki dan pemeriksaan terkait diulang. Tidak menganggap hasil percobaan gagal sebagai pass.

Review konfigurasi: ESLint hanya mengabaikan direktori generated/artifact, bukan source aplikasi; TypeScript strict tetap aktif. Next output test memakai flag eksplisit, default development tetap .next, tanpa menonaktifkan Cache Components/Partial Prefetching. Playwright tidak memakai reuseExistingServer. Tidak ada formatter frontend khusus di manifest; pemeriksaan gaya frontend menggunakan ESLint/diff, bukan klaim Prettier lulus. Pint dan git diff --check lulus; tidak memasang dependency baru atau mengubah lockfile.

Race memakai barrier dua worker PHP dan CONNECTION_ID berbeda, bukan dua pemanggilan serial. Hasil: satu save menang, publish versi sama satu snapshot/audit, publish/save tidak bercampur versi, referensi draft/revisi mencegah delete, atau delete menang dan save ditolak tanpa referensi rusak. Ini menguji skenario konkret, bukan pembuktian semua interleaving atau exactly-once I/O.

Integrasi nyata membuat akun stage10-<run acak>@example.test pada database test saja, tanpa membership/admin aplikasi. Upload PNG 640×360 diproses menjadi WebP; blob preview privat berhasil, draft tidak publik, satu snapshot/gambar publik tersedia sesudah publish, draft berikutnya tetap privat, logout dan akses admin tamu ditolak. Akun/file/revisi/audit fixture milik run dibersihkan secara terarah; tidak melakukan purge global.

Inspeksi akhir: singleton draft_version 0, revisi 0, metadata media 0 dan akun stage10-* tersisa 0 di test; database aplikasi tetap memiliki 0 tabel landing. PK/FK/unique/CHECK terpasang pada test, migration batch 4 dipertahankan. Port server test 3100/3110/3111/8110/18100 sudah tidak listening setelah suite selesai. Tidak menjalankan worker/scheduler tambahan atau meninggalkan server test. Artefak screenshot dan storage fake berada pada direktori test yang diabaikan Git.

Mode next start dengan konfigurasi HTTPS sintetis membuktikan landing index/follow dan canonical konfigurasi, sementara login/admin/preview tetap noindex. Media draft diketahui UUID-nya tetap 404 publik, 401 admin tanpa sesi (termasuk Accept non-JSON), dan optimizer Next menolak URL media admin (400). Tidak memublikasikan konten pada layanan/domain nyata.

Screenshot sintetis .next/stage10-public-desktop.png (1440px), stage10-public-320.png dan stage10-editor-320.png ditinjau langsung. Sunset/Plus Jakarta Sans, reflow, CTA, caption dan layout editor terlihat; kontrol utama minimal 44px. Browser juga menguji menu/FAQ via keyboard, fokus terlihat, dialog publish/Escape/fokus kembali, reduced motion, reflow ekuivalen zoom 200% (viewport CSS 320px, device scale 2), dan gambar gagal yang tidak menghalangi teks/login. Ini bukan pengujian screen reader atau zoom manual lintas browser. Screenshot tidak dilacak Git.

## Dokumentasi dan batas akhir sesi

PRD 1.6, ARCHITECTURE 1.6, SYSTEM_DESIGN 1.7 dan aturan root AGENTS 1.4 diselaraskan; status Final/ID lama/riwayat pengujian dipertahankan. Kontrak utama backend/docs/API_CONTRACT.md dan indeks root diperbarui. README/.env.example frontend memuat konfigurasi baru tanpa mengubah .env aktual. Panduan/script root redirect lama ditandai superseded dan diberi guard.

PRD, SYSTEM_DESIGN dan AGENTS tidak ditambah ulang pada sesi review karena perilaku produk tidak berubah. Kontrak API menegaskan 401 untuk media admin tanpa Accept JSON, sesuai kebijakan respons API existing. ARCHITECTURE §12.4 menjelaskan koreksi batas provider; README root/backend mengarahkan ke hasil aktual di catatan ini. Riwayat Stage 09 dan perubahan lokal lainnya dipertahankan.

Batas: browser yang dijalankan Chromium/Windows; belum menguji Firefox/WebKit, screen reader manual, rollback migration atau kehilangan acknowledgement COMMIT karena putus koneksi nyata. Storage/audit failure dan cleanup orphan diuji terisolasi; tidak menjalankan cleanup scheduler/data aplikasi. Belum ada akun admin aplikasi/konten resmi/foto yang ditetapkan. Sebelum penerapan development perlu otorisasi migration sorerunapp, backup/target terverifikasi, konfigurasi origin/GD/disk/ukuran request pada PHP webserver yang dipakai, serta penetapan admin secara eksplisit. Landing lokal tetap LANDING_INDEXABLE=false. Jangan memakai hasil lokal sebagai kesiapan produksi.

## Review ulang dan pengujian 20 November 2026

Inspeksi ulang mencakup instruksi root/frontend/backend, FR-19–21/AC-31–35, kontrak, perubahan lokal, route/provider/guard, seluruh action konten, validasi, resource/policy, storage, editor, SSR serta pengujian existing. Tidak ditemukan nama file source Stage 10 non-ASCII/tidak sengaja. Perubahan worker/scheduler, dropdown jenis kelamin dan Stage 09 dipertahankan. Tidak mengganti konfigurasi development dengan konfigurasi test, melonggarkan permission/CSRF/tipe/lint, menonaktifkan Cache Components, atau mengubah dependency/lockfile.

### Temuan dan perubahan sesi ini

- Error caption gambar sebelumnya tidak mempunyai aria-invalid, aria-describedby atau pesan error terkait, meskipun validasi menolak markup. Browser test mereproduksi kegagalan sebelum perbaikan. ImageFields kini menghubungkan error caption hero/SEO/galeri dengan input; test memeriksa deskripsi aksesibel, fokus kembali dan draft tidak tersimpan.
- Browser test baru mencakup field editorial, CTA tetap /login, kontak/media sosial, gambar berbagi/alt, galeri/caption, FAQ, urutan dan section tersembunyi, serta error 429/503 yang mempertahankan input tanpa replay mutation. Fixture memakai media sintetis dan mengekspos Retry-After seperti CORS Laravel; selector loading mengikuti label existing, bukan melonggarkan assertion.
- Ditambahkan race keenam yang deterministik: writer memegang page lock, reader membuat snapshot REPEATABLE READ sebelum menunggu lock, writer memublikasikan gambar kemudian melepasnya dari draft dalam commit yang sama. Delete tetap menghasilkan MEDIA_IN_USE karena pivot revisi dipertahankan harus dibaca dengan locking read terkini. Dua subprocess menggunakan CONNECTION_ID berbeda; isolasi REPEATABLE READ dibuat eksplisit pada koneksi worker test. Lima race existing tetap dipertahankan.
- Fetch SSR kini mencatat hanya kode transport yang dibatasi panjang/karakter, status HTTP, atau pesan kegagalan kontrak/body yang tetap. Tidak mencatat URL, cookie, exception lengkap, body, token, atau konten editorial. Control-flow internal Next tetap dilempar ulang sebelum logging. Envelope JSON diperlakukan sebagai unknown sebelum validasi schema. Assertion integrasi melaporkan flag render aman, bukan dump HTML/RSC ketika gagal.
- Status kontrak API Stage 10 yang masih menyebut belum pernah diuji diperbarui agar sesuai catatan aktual. Tidak mengubah payload/otorisasi atau menambah ulang requirement. PRD, ARCHITECTURE, SYSTEM_DESIGN, AGENTS dan README dibiarkan karena keputusan serta prosedur existing sudah memadai.

### Lingkungan dan hasil eksekusi baru

PHP 8.4.12 dengan GD/encoding WebP tersedia; MySQL 8.4.3 pada sorerunapp_test, Node 24.22.0 dan npm 11.19.1. PHPUnit memaksa DB_URL kosong, host loopback, database test, konfigurasi cache test terpisah, email array dan outbox nonaktif. Browser nyata memakai router/bridge test, cookie/cache/storage test serta admin sintetis tanpa membership. Tidak memakai akun anggota nyata, SQLite atau inbox/email eksternal.

| Pemeriksaan sesi 20 November | Hasil |
| --- | --- |
| phpunit.landing.xml | 31 test / 347 assertion lulus, termasuk enam race paralel MySQL, validasi/CSRF/rate limit, izin/sesi stale, snapshot/audit rollback, re-encoding JPEG/PNG/WebP, media privat, storage/orphan dan kegagalan delete |
| LandingConcurrencyTest sesudah isolasi worker dibuat eksplisit | 6 test / 41 assertion lulus |
| Regresi phpunit.passwords.xml, filter BrowserSessionTest/EmailVerificationTest/PasswordResetTest/EmailChangeTest | 122 test / 1129 assertion lulus pada MySQL test; tidak mengklaim seluruh suite backend |
| npm test | 23 unit test lulus |
| npm run lint; npm run typecheck | Lulus; source aplikasi tetap diperiksa dan TypeScript strict tetap aktif |
| Playwright landing, HTTP fixture | 11 test lulus setelah perbaikan caption dan fixture cooldown; tiga test SSR error/null/snapshot diulang sesudah perubahan penanganan respons dan lulus. Tidak membuktikan transaksi/permission Laravel |
| npm run test:e2e | 71 test regresi fixture lulus, termasuk login/loading/menu/logout/dialog/expiry, verifikasi/resend/reset, perubahan email dan pembersihan cache privat |
| Playwright landing-live, next dev | Alur nyata browser → Laravel → MySQL test → storage test → draft → preview → publish → publik lulus (51,7 detik pada pengulangan mandiri) |
| Playwright landing-live, next start | Dua pengulangan berturut-turut lulus (43,1 dan 42,4 detik); HTML/metadata publik, canonical HTTPS sintetis, index/follow landing dan noindex login/admin/preview diperiksa |
| Build | Lulus pada .next/landing-build, API localhost:8110, tanpa database aplikasi/data dummy fallback; root Partial Prerender dengan Cache Components/Partial Prefetching aktif |
| Pint --test | Lulus pada file Stage 10; dua file concurrency yang diperbarui diperiksa ulang. Tidak memformat modul lain |

Percobaan gagal tetap dicatat: test caption awal gagal sebelum perbaikan; test cooldown awal memakai header fixture yang belum diekspos serta selector tombol yang tidak mengikuti state loading. Beberapa startup/build dihentikan Next untuk menampilkan advisory keamanan; pengulangan command diizinkan framework berjalan tanpa menonaktifkan pemeriksaan. Satu integrasi next dev mencapai navigasi terakhir lalu timeout 120 detik ketika suite browser lain berjalan bersamaan; pengulangan mandiri lulus tanpa menaikkan timeout. Percobaan next start awal menerima state gangguan API pada HTML; sesudah rebuild dengan logging tersanitasi, alur lulus dan kegagalan tersebut belum dapat direproduksi. Penyebab transien itu belum dipastikan, sehingga tidak diklaim sebagai bug SSR yang sudah diketahui akar masalahnya.

Screenshot desktop 1440 px serta landing/editor 320 px tanggal 20 November ditinjau langsung. Ditambahkan screenshot viewport editor agar tidak hanya mengandalkan gambar full-page yang diperkecil. Headline, CTA, caption, Sunset/Plus Jakarta Sans dan reflow terlihat; assertion memastikan tidak ada overflow horizontal. Test keyboard/menu/FAQ/fokus/dialog, reduced motion, target sentuh dan gambar gagal lulus. Emulasi viewport CSS 320 px/device scale 2 adalah pemeriksaan reflow ekuivalen 200%, bukan zoom manual browser atau pengujian pembaca layar. Firefox/WebKit, screen reader/zoom manual, rollback migration dan kehilangan acknowledgement COMMIT nyata tetap belum diuji.

### Prasyarat dan batas penerapan

Tidak menjalankan migration baru pada sesi ini: migration Stage 10 sudah tersedia di sorerunapp_test, batch 4, dari sesi terdahulu. Database aplikasi tetap di luar target migration. Penerapan development masih memerlukan instruksi terpisah, backup/target terverifikasi, origin/cookie/CSRF, PHP webserver dengan GD/WebP dan batas request, storage privat writable, serta penetapan akun admin secara eksplisit.

Inspeksi read-only akhir memastikan 4 tabel/16 constraint Stage 10 tersedia pada test, migration tetap batch 4, draft_version 0, revisi/media/akun integrasi sintetis tersisa 0. Jumlah tabel landing pada database aplikasi tetap 0. Cleanup hanya milik masing-masing run test; tidak menghapus queue, token, failed jobs atau data aplikasi.

git diff --check lulus (hanya peringatan normalisasi CRLF existing). Port test 3100/3110/3111/8110/18100 tidak lagi listening setelah suite; inspeksi port hanya menemukan MySQL lokal 3306. Tidak meninggalkan server test, worker atau scheduler tambahan. Artefak screenshot dan build tetap pada direktori test yang diabaikan Git.

Next.js terpasang masih 16.4.2. [Advisory resmi 3 November 2026](https://nextjs.org/blog/november-2026-security-release) menyebut patch 16.4.5. Peringatan tetap berlaku meskipun pengulangan build/test berhasil; tidak ada perubahan dependency atau klaim bahwa konfigurasi Stage 10 membuktikan seluruh advisory tidak berlaku. Pembaruan keamanan perlu ditindaklanjuti sebelum penerapan publik/produksi. Hasil lokal ini bukan penilaian kesiapan produksi.

## Sesi manual terisolasi — 20 November 2026

Pemilik produk meminta lingkungan browser yang tetap tersedia sebelum migration aplikasi. Launcher source `scripts/landing-manual.cjs` memakai Laravel/API/storage nyata, bukan HTTP fixture. Mode manual helper Stage 10 mempertahankan default automated test, tetapi memakai origin IPv4, cookie/cache/storage dan output Next tersendiri. `.env` aktual dan dependency tidak berubah; tidak menjalankan migration, reset, cleanup, worker, scheduler atau pengiriman email nyata.

| Area | Target manual |
| --- | --- |
| Landing | http://127.0.0.1:3120/ |
| Login | http://127.0.0.1:3120/login |
| Editor admin | http://127.0.0.1:3120/admin/landing-page |
| Preview privat | http://127.0.0.1:3120/admin/landing-page/preview |
| API Laravel | http://127.0.0.1:8120/api/v1/public/landing-page |
| Health test | http://127.0.0.1:8120/__stage10_manual/health (testing, sorerunapp_test, mail array) |
| Database | MySQL 8.4.3 sorerunapp_test; migration Stage 10 existing batch 4 |
| Media | backend/storage/framework/testing/stage10-manual-media, tidak disymlink |
| Output Next | frontend/.next/landing-manual; noindex, Cache Components/Partial Prefetching tetap aktif |
| Sesi/cache | stage10_manual_session / stage10_manual_; terpisah dari development dan suite live otomatis |

Persiapan membuat **akun baru** `stage10-manual-8129b1839c31@example.test`, enabled/verified/admin tanpa membership, dengan profil sintetis. Role akun existing tidak diubah. Password acak hanya diberikan pada output prepare pertama dan percakapan; tidak disimpan mentah dalam state/log/dokumentasi. Manifest kepemilikan tanpa password berada di `backend/storage/framework/testing/stage10-manual/state.json`, diabaikan Git.

Data awal: satu draft versi 1, satu snapshot publikasi (ID 72 pada sesi ini), tiga media WebP dari PNG geometris sintetis. Seluruh section about/activities/gallery/faq/contact ditampilkan; hero/footer/SEO dan kontak contoh jelas berlabel data uji. Tidak memakai foto/identitas anggota, jadwal atau kontak resmi. Publikasi hanya ada pada database test. PNG untuk mencoba upload tersedia di `backend/storage/framework/testing/stage10-manual/sample-orange.png`, `sample-gold.png`, `sample-cream.png`.

Jalankan dari root repository pada terminal Windows/Laragon dengan PHP 8.4, Node 24, dependency existing dan MySQL aktif:

```powershell
node scripts/landing-manual.cjs status
# Jika belum berjalan, jalankan dan biarkan terminal ini tetap terbuka:
node scripts/landing-manual.cjs start
```

Hentikan dengan Ctrl+C pada terminal launcher, atau dari terminal lain:

```powershell
node scripts/landing-manual.cjs stop
# Menjalankan kembali memakai akun dan konten yang sama:
node scripts/landing-manual.cjs start
```

`prepare` hanya diperlukan untuk persiapan awal; sudah dijalankan pada sesi ini. Menjalankan `node scripts/landing-manual.cjs prepare` lagi mengenali state existing tanpa mengganti password/konten. Jika singleton sudah berisi konten milik test lain tanpa manifest manual, persiapan menolak overwrite. State parsial dipertahankan untuk diagnosis, bukan dihapus otomatis. Tidak ada command cleanup dalam launcher ini.

Launcher memeriksa target test, ketersediaan port, health API/login dan respons SSR sebelum menyatakan siap. Kontrol status/stop menggunakan named pipe lokal milik repository; stop hanya menghentikan child process yang dibuat launcher tersebut. Port terpakai menyebabkan startup ditolak, bukan membunuh server lain. Tidak memasang service/startup Windows. Jika Codex mengakhiri proses setelah percakapan, jalankan kembali launcher di terminal sendiri; status terakhir bukan jaminan proses bertahan setelah sesi tool berakhir. Gunakan 127.0.0.1 secara konsisten, bukan localhost, untuk menjaga origin/cookie yang dikonfigurasi.

Log teknis berada di `backend/storage/logs/stage10-manual-api.log` dan `stage10-manual-frontend.log`. Contoh pemeriksaan: `Get-Content backend/storage/logs/stage10-manual-frontend.log -Tail 40 -Wait`. Jika Next menghentikan startup untuk advisory keamanan, baca peringatan lalu ulangi command start; launcher tidak menonaktifkan pemeriksaan atau memperbarui dependency. Advisory existing tetap berlaku.

Hasil persiapan: PHP syntax/Pint helper, Node syntax, lint/typecheck frontend dan git diff --check lulus. Smoke browser nyata lulus untuk lima section, empat gambar tampil (hero dan galeri), SSR publik, login ke beranda, editor, preview blob privat, 320 px dan logout/guard. Tidak muncul gangguan SSR pada pemeriksaan manual ini. Stop dan start diuji: akun, draft versi 1, snapshot ID 72 serta tiga media tetap sama. Satu restart berhenti karena advisory Next; pengulangan berjalan tanpa mematikan pemeriksaan. Seluruh automated suite tidak diulang.

**Akun, publikasi, draft, media dan server manual sengaja dipertahankan untuk pemilik produk.** Jangan menjalankan suite database otomatis, migration/reset atau cleanup pada sorerunapp_test selama sesi manual ini; singleton landing dipakai bersama dalam database test tersebut. Tidak ada perubahan schema/data pada sorerunapp. Pembersihan fixture manual menunggu instruksi terpisah setelah percobaan selesai.

Checklist browser: buka root sebagai tamu → login admin sintetis → ubah teks/gambar/urutan/visibility → simpan draft dan periksa publik belum berubah → preview tersimpan → publish dan periksa incognito → uji mobile → logout dan periksa editor/preview ditolak. Gunakan gambar sintetis yang disediakan untuk mencoba upload.

### Penyesuaian tab akun — 20 November 2026

Sesuai permintaan pemilik produk, Kelola Landing Page dipindahkan dari area nama akun menjadi tab bersama Beranda/Kartu/Profil. Tab hanya tampil bagi admin dan aktif di editor maupun preview. AdminShell tetap memeriksa role/sesi sebelum memakai navigasi MemberShell bersama; lebar konten editor tetap memakai src-content-main. Navigasi bawah membagi ruang menurut jumlah item, dengan ruang tambahan untuk label admin pada mobile/tablet. Tidak mengubah provider, guard backend, API atau data.

Lint/typecheck dan 21 browser test fixture lulus: tiga test baru admin/member/staff (termasuk 1024/768/320 px, tab aktif, perpindahan halaman dan jarak konten dari bar) serta 18 regresi navigasi/login/logout. Screenshot desktop dan 320 px ditinjau. Selector awal diperbaiki agar hanya memeriksa halaman/navigasi aktif, karena Activity Next mempertahankan DOM halaman tersembunyi; assertion perilaku tetap. Percobaan startup yang dihentikan advisory Next diulang tanpa menonaktifkan pemeriksaan. Tidak menjalankan test database atau menghapus fixture/server manual.
