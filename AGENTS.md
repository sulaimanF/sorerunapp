# AGENTS.md — sorerunapp

Panduan kerja AI untuk pengembangan aplikasi membership Sore Running Club (SRC). Letakkan file ini di root repository sorerunapp, sejajar dengan PRD.md, ARCHITECTURE.md, SYSTEM_DESIGN.md, frontend/, dan backend/.

Versi panduan: 1.0 — 14 Oktober 2026. Panduan ini disusun dari tiga dokumen final yang telah disetujui pemilik produk. Aturan di dalamnya mengatur cara implementasi; tidak memberikan kewenangan mengubah keputusan produk secara sepihak.

Status: **Final — disetujui pemilik produk sebagai panduan kerja AI untuk pengembangan sorerunapp.**

## 1. Sumber keputusan dan penanganan konflik

| Dokumen | Kewenangan |
| --- | --- |
| PRD.md | Masalah, ruang lingkup, role, proses dan aturan bisnis, acceptance criteria |
| ARCHITECTURE.md | Stack, struktur, tanggung jawab sistem, data, API, transaksi, keamanan, operasi |
| SYSTEM_DESIGN.md | Warna, tipografi, layout, komponen, state, copy, dan aksesibilitas |
| AGENTS.md | Perilaku AI, aturan kode, penggunaan tools, pengujian, dan pelaporan |

- Instruksi sistem/platform dan batas keamanan environment tetap berlaku. Instruksi terbaru pemilik produk yang jelas dapat merevisi keputusan proyek; catat dampaknya dan selaraskan dokumen yang terkait dalam cakupan yang diizinkan.
- Dalam PRD, Bagian 4.2 dan 19 adalah keputusan terbaru dan mengungguli rumusan lama yang masih menyebut asumsi atau pilihan sebelumnya.
- PRD menentukan perilaku bisnis. Arsitektur menerjemahkannya secara teknis; design system menerjemahkannya secara visual. Jangan memakai panduan UI untuk melewati aturan bisnis.
- Kode existing adalah bukti implementasi, bukan alasan mengabaikan dokumen final. Jika menyimpang, jelaskan dan perbaiki dalam cakupan tugas.
- Jika konflik dapat diselesaikan oleh kewenangan dokumen di atas, lanjutkan tanpa pertanyaan berulang. Jika ada dua aturan substantif yang tetap bertentangan, jelaskan konflik dan tanyakan keputusan yang spesifik; pekerjaan independen dapat diteruskan.
- Jangan mengubah status final, persyaratan, scope, stack utama, atau token visual hanya agar cocok dengan implementasi yang lebih mudah. Revisi dokumen final hanya ketika diminta atau jelas diotorisasi pemilik produk.
- AGENTS.md tambahan pada subfolder boleh memberi aturan teknis lebih spesifik sesuai mekanisme agent yang dipakai, tetapi tidak diam-diam mengubah keputusan bisnis/arsitektur final.

## 2. Protokol memulai pekerjaan

1. Pahami hasil yang diminta. Permintaan dokumen/review tidak otomatis berarti diminta membuat aplikasi atau melakukan deployment.
2. Pada sesi development pertama, baca tiga dokumen final untuk memahami keseluruhan sistem. Untuk tugas berikutnya, gunakan konteks yang sudah tersedia dan baca kembali bagian terkait bila berubah atau belum cukup; jangan mengandalkan ringkasan lama jika file sudah diperbarui.
3. Baca AGENTS.md yang berlaku pada path target, README, manifest, lockfile, konfigurasi, dan kode terkait sebelum mengedit.
4. Periksa Git status/diff. Pertahankan perubahan pengguna yang belum di-commit; jangan mengembalikan atau menimpa pekerjaan di luar tugas.
5. Hubungkan pekerjaan ke requirement FR/AC/BR PRD dan bagian arsitektur/desain yang relevan. Tidak perlu menyalin seluruh dokumen ke setiap laporan.
6. Tentukan file/modul terdampak, risiko, dan verifikasi yang diperlukan. Untuk pekerjaan besar, gunakan rencana singkat dengan tahap yang dapat diverifikasi.
7. Sampaikan rencana singkat, lalu kerjakan sampai hasil yang diminta selesai atau ada hambatan nyata. Jangan berhenti hanya pada usulan ketika implementasi sudah diminta.

Jika repo masih kosong, scaffold hanya ketika tugas memang mengizinkannya. Pastikan versi runtime tersedia. Jika dokumen wajib tidak ditemukan, cari pada lokasi repo yang masuk akal; jangan mengarang keputusan. Kerjakan bagian yang jelas dan laporkan sumber yang belum tersedia.

## 3. Keputusan proyek yang wajib dipertahankan

### 3.1 Struktur dan stack

| Komponen | Ketentuan |
| --- | --- |
| Repository | Satu monorepo sorerunapp dengan frontend/ dan backend/ sejajar |
| Frontend | Next.js 16.x stabil, App Router, TypeScript 5.x minimum 5.1 |
| Runtime frontend | Node.js 24.x LTS |
| Backend | Laravel 13.x stabil, PHP 8.4.x |
| Database | MySQL 8.4.x LTS, InnoDB, utf8mb4 |
| Auth web | Sanctum dengan session cookie; proteksi CSRF dan CORS sesuai topologi |
| UI/form | Tailwind CSS, shadcn/ui, React Hook Form, Zod |
| Data API | TanStack Query dan HTTP client Axios terpusat |
| Queue/session/cache awal | Driver database Laravel sesuai arsitektur |

Jangan memindahkan Laravel ke root dan menaruh Next.js di dalamnya. Jangan mengganti MySQL dengan SQLite/MariaDB/PostgreSQL untuk membuktikan perilaku produksi. Test unit murni tanpa database boleh berjalan sendiri, tetapi test schema/locking harus memakai MySQL 8.4.

Gunakan npm di frontend sebagai baseline kecuali repo telah menetapkan satu package manager lain secara eksplisit. Ikuti lockfile authoritative dan jangan membuat package-lock, pnpm-lock, dan yarn.lock bersamaan. Dependency PHP dikelola dari backend/, dependency frontend dari frontend/. Tidak membuat repository Git bersarang.

### 3.2 Aturan bisnis inti

- Satu komunitas; membership gratis tanpa kedaluwarsa otomatis. Registrasi sekali; email terverifikasi dan profil wajib lengkap sebelum aktivasi otomatis, tanpa approval admin.
- Nama, tanggal lahir, telepon, dan domisili wajib; foto opsional. Anggota di bawah umur diperbolehkan. Jangan menambah NIK/KTP, data kesehatan, atau batas usia minimum otomatis.
- Akun, profil, membership, credential QR, event, dan attendance adalah domain terpisah. Satu akun maksimum satu membership pada komunitas ini.
- Nomor member unik, stabil, tidak dipakai ulang. Reaktivasi ended oleh admin memakai membership dan nomor yang sama selama belum dianonimkan.
- QR tetap, dapat dicetak/screenshot, dapat dicabut/diganti. QR tidak berisi PII atau token login dan tidak memberikan hak login.
- Event internal gratis dengan kuota positif yang ditentukan admin, tanpa RSVP. Kuota dikonsumsi attendance valid saat commit, bukan saat preview.
- Check-in online oleh petugas berizin atau admin, dengan konfirmasi identitas. Tidak ada self check-in atau override petugas atas eligibility/kuota.
- Pencarian/manual check-in adalah alternatif identifikasi, bukan bypass aturan akun, membership, waktu event, izin, dan kuota.
- Jangan menambah pembayaran, reservasi, waitlist, native app, offline queue, poin, integrasi Strava, atau multi-komunitas sebagai bagian dari tugas rutin.
- Jangan membuat mock response atau data dummy permanen untuk menyamarkan integrasi yang belum selesai. Fixture/test factory diperbolehkan pada lingkungan uji yang jelas.

## 4. Aturan kode umum

- Gunakan perubahan sekecil yang memadai untuk hasil lengkap; hindari refactor lintas modul, penggantian struktur, dan dependency baru yang tidak diperlukan.
- Nama kode, file domain, enum, dan kontrak teknis menggunakan bahasa Inggris; label/copy produk menggunakan Bahasa Indonesia sesuai design system. Ikuti konvensi framework dan repo.
- Pilih nama yang menjelaskan tujuan. Komentar menjelaskan alasan/invariant, bukan mengulang baris kode. Jangan menaruh seluruh logika pada satu page/controller.
- Pisahkan transport, validasi, policy, aturan bisnis, dan rendering. Jangan menggandakan aturan bisnis pada frontend dan backend sebagai dua sumber kebenaran.
- Hilangkan kode mati/debug yang dibuat dalam tugas. Jangan membiarkan console.log data pribadi, dump request, dd(), stack trace, atau credential pada hasil akhir.
- Jangan menonaktifkan lint, typecheck, test, CSRF, atau policy untuk membuat pemeriksaan lulus. Pengecualian lokal harus mempunyai alasan teknis yang jelas dan tidak menutupi kesalahan.
- Dependency hanya ditambahkan bila ada kebutuhan nyata dan kompatibel dengan cabang versi final; perbarui manifest/lockfile yang relevan. Jangan memakai latest secara membabi buta atau melakukan upgrade major tanpa otorisasi perubahan stack.
- Tangani error secara eksplisit. Jangan menangkap semua exception lalu mengembalikan sukses, array kosong, atau 500 untuk setiap konflik bisnis.

## 5. Aturan frontend

### 5.1 Penempatan

| Path | Tanggung jawab |
| --- | --- |
| frontend/src/app/ | Route, layout, metadata, komposisi halaman |
| frontend/src/features/<feature>/ | Komponen, hook, service, tipe, schema per fitur |
| frontend/src/components/ui/ | Primitive shadcn sesuai token dan ukuran produk |
| frontend/src/components/shared/ | Komponen generik lintas fitur yang benar-benar dipakai bersama |
| frontend/src/lib/ | HTTP client, query configuration, utilitas umum |
| frontend/src/providers/ | Provider query dan konteks sesi/UI |

- Gunakan fitur auth, members, membership, events, attendance, reports sesuai arsitektur. Jangan membuat services/hooks duplikat di root dan fitur.
- TypeScript strict. Hindari any, type assertion paksa, atau ts-ignore untuk menutupi kontrak yang salah; gunakan tipe tepat atau unknown yang divalidasi. Cast yang benar-benar diperlukan dibatasi dan dijelaskan.
- Server Component menjadi default untuk komposisi yang tidak memerlukan browser. Tambahkan use client hanya pada batas interaktif; kamera, hooks browser, dan query client berada di client.
- Baseline data privat dimuat browser melalui Laravel API. Jangan menambah SSR data privat/BFF dengan penerusan cookie yang belum dirancang.
- React Hook Form + Zod untuk UX; FormRequest backend tetap validator final. Pertahankan input saat error dan hubungkan label/helper/error secara aksesibel.
- TanStack Query mengelola server state. Gunakan query key yang mencakup identitas/scope/event/filter yang relevan; invalidasi setelah mutation. Jangan menambah Redux tanpa kebutuhan yang disetujui.
- Jangan menyimpan session/bearer token, QR, atau query privat di localStorage/persisted cache. Logout/pergantian akun membersihkan cache privat dan stream kamera.
- Mutation check-in/kuota/koreksi tidak memakai optimistic success. Hasil final berasal dari backend; timeout dapat berarti hasil belum diketahui.
- HTTP client terpusat mengelola credentials, XSRF, timeout, dan error envelope. Jangan mengulang mutation otomatis secara buta setelah 419/timeout.
- Guard/menu frontend untuk UX; API tetap melindungi ownership, role, dan scope. Menyembunyikan tombol bukan implementasi otorisasi.

### 5.2 Desain yang wajib diikuti

- Baca SYSTEM_DESIGN.md untuk nilai lengkap; jangan membuat tema baru karena template starter bawaan berbeda.
- Primary #C2410C, hover #9A3412, forest #163E2B, canvas #F8FAF7, surface #FFFFFF, ink #17211B. Gunakan semantic token di globals.css, bukan hardcode warna per halaman.
- Font Plus Jakarta Sans melalui next/font; body/input 16 px; tinggi kontrol/hit area utama minimum 44 px, CTA scanner minimum 52 px.
- Kartu anggota memakai bidang forest dan panel QR hitam-putih; quiet zone minimal empat modul. Tidak membuat QR dekoratif, cropping, atau logo yang menutupi modul.
- Fokus, error, loading, empty, disabled, dan status wajib dirancang. Status tidak dibedakan melalui warna saja.
- Preview QR bukan sukses hadir; sukses hanya sesudah commit. Nama event/tanggal/lokasi selalu jelas di scanner.
- Uji mobile 320 px, reflow/zoom, keyboard dan safe area pada perubahan layout relevan. Jangan mengaktifkan dark mode parsial tanpa desain lengkap.
- Jangan menambah CTA Daftar Event, Pesan Tempat, atau Beli Tiket pada event tanpa RSVP/pembayaran.

## 6. Aturan backend dan API

- Controller tipis; FormRequest untuk validasi, Policy untuk izin objek, Action untuk transaksi/aturan bisnis, API Resource untuk response whitelist.
- Gunakan app/Actions/<Domain>, Models, Policies, Enums, Jobs, Notifications, Services sesuai arsitektur. Jangan menambah repository abstraction atau microservice tanpa kebutuhan nyata.
- Model binding harus scoped: attendance harus cocok dengan event pada URL. ID yang valid tidak otomatis berarti user berhak mengaksesnya.
- Jangan menerima role, status, actor_id, atau waktu server dari client melalui mass assignment bebas. Actor berasal dari session dan transisi melalui action eksplisit.
- Base API /api/v1; /sanctum/csrf-cookie mengikuti Sanctum. Ikuti error envelope dan status HTTP pada ARCHITECTURE.md; dokumentasikan payload dalam docs/API_CONTRACT.md ketika endpoint dibuat/diubah.
- Sanctum stateful auth, akun enabled, email verified jika diperlukan, dan policy diperiksa pada backend. Perubahan role/penugasan/status setelah preview diperiksa lagi saat confirm.
- Sesi browser memakai cookie aman dan CSRF. Jangan mematikan proteksi atau memakai CORS wildcard bersama credentials untuk mempercepat integrasi.
- QR: crypto-random 32 byte, payload src-member:v1:<token>, SHA-256 lookup, token asli terenkripsi bila ditampilkan ulang. Jangan menggantinya dengan member ID berurutan atau session token.
- Rotasi QR, aktivasi, status, kuota, attendance, dan audit terkait memakai transaksi. Jangan memanggil layanan email/storage/network di dalam row lock.
- Notification delivery dibuat sebagai outbox dalam transaksi; job setelah commit dan sweep pending menutup gap dispatch. Retry tidak menjanjikan exactly-once provider eksternal.
- Perubahan password/block memakai auth_version dan pencabutan session sesuai arsitektur. Reaktivasi membership tidak membuka blokir akun atau menghidupkan credential revoked.
- Upload foto divalidasi isi/ukuran/tipe pada server dan disimpan privat. File storage bukan bagian transaksi atomik MySQL; cleanup/orphan ditangani eksplisit.

## 7. Aturan database, transaksi, dan concurrency

1. Gunakan migration versioned dengan FK, unique/check constraint, dan indeks relevan. Jangan mengubah schema produksi langsung lewat GUI sebagai solusi utama.
2. Pertahankan UNIQUE(event_id, membership_id) pada semua status attendance. Void/restore memperbarui record yang sama; tidak menghapus lalu memasukkan duplikat.
3. Pertahankan satu QR aktif melalui membership row lock dan unique generated column sebagaimana arsitektur. Jangan meniru partial unique index PostgreSQL di MySQL.
4. Alokasi nomor memakai sequence row lock dan unique member_number. Jangan memakai MAX(number) + 1 tanpa perlindungan atau reset counter pada seeder.
5. Semua penulisan attendance, void/restore, rekonsiliasi, dan perubahan capacity mengunci event yang sama. Counter dan audit diperbarui dalam transaksi yang sama.
6. Urutan lock global: events menurut ID → event_staff → memberships menurut ID → users menurut ID → credentials → attendance. Jangan mengambil lock kelompok yang lebih awal setelah kelompok lebih akhir.
7. Cek attendance existing sebelum menolak karena kuota penuh. Valid menghasilkan already_checked_in; voided tidak dipulihkan otomatis. Counter tidak bertambah saat retry.
8. Counter valid mencakup detail valid + agregat anonim. Jangan menghitung kapasitas dari cache frontend atau COUNT snapshot yang tidak diselaraskan dengan lock.
9. Preview tidak mereservasi kuota. Dua petugas memperebutkan satu tempat menghasilkan paling banyak satu check-in baru.
10. Deadlock retry terbatas sesuai arsitektur; kegagalan akhir dilaporkan, bukan disembunyikan dengan INSERT IGNORE atau sukses palsu.
11. Waktu sistem UTC; tanggal lahir DATE; tampilan mengikuti timezone event. Jangan menerima recorded_at bebas dari client.
12. Migration additive dan kompatibel diutamakan. Jangan mengubah migration yang sudah diterapkan pada environment bersama untuk menggantikan migration baru.
13. Dilarang menjalankan migrate:fresh, db:wipe, truncate, atau seeder destruktif pada database yang belum dipastikan disposable. Penggunaan otomatis diperbolehkan hanya pada database test terisolasi yang memang dibuat untuk di-reset.

## 8. Tools dan perintah kerja

Gunakan tools yang benar-benar tersedia. Jangan mengaku mempunyai akses IDE/browser/database/MCP yang belum tersedia atau menganggap contoh command sudah dijalankan. Jalankan command dari working directory yang tepat.

| Kebutuhan | Tool/acuan |
| --- | --- |
| Pencarian kode | rg dan rg --files; fallback jika tidak tersedia |
| Inspeksi perubahan | git status --short, git diff, git diff --check |
| Dependency backend | Composer, berdasarkan composer.json/composer.lock |
| Dependency frontend | npm, berdasarkan package.json/package-lock.json baseline |
| Framework backend | php artisan dari backend/ |
| Format PHP | Laravel Pint jika tersedia pada dependency project |
| Lint/tipe frontend | ESLint dan TypeScript yang terpasang lokal |
| Test backend | PHPUnit melalui php artisan test; Pest jika memang dikonfigurasi repo |
| Test frontend | Test runner yang sudah dikonfigurasi; Vitest/Testing Library bila dipilih untuk component test |
| Browser/E2E | Playwright atau browser automation yang tersedia untuk lokal/staging berizin |
| Database | MySQL 8.4 CLI/client; query read-only sebagai default inspeksi |
| Dokumentasi framework | Dokumentasi resmi Laravel, Next.js, MySQL, dan library versi yang digunakan |

Vitest/Playwright bukan klaim bahwa paket sudah terpasang. Tambahkan hanya ketika kebutuhan pengujian tugas memerlukannya dan selaraskan konfigurasi repo. Jangan menginstal seluruh daftar tool sekaligus untuk perubahan kecil.

Contoh command setelah project terkait tersedia:

| Direktori | Command | Tujuan |
| --- | --- | --- |
| Root | git status --short; git diff --check | Kondisi repo dan whitespace; jalankan sebagai command terpisah bila perlu |
| backend/ | composer install | Instal dependency sesuai lockfile |
| backend/ | php artisan --version | Verifikasi Laravel |
| backend/ | php artisan route:list --path=api | Periksa route |
| backend/ | php artisan test | Jalankan test backend dengan environment test yang benar |
| backend/ | php vendor/bin/pint --test | Periksa format bila Pint tersedia |
| frontend/ | npm ci | Instal sesuai lockfile; gunakan npm install saat setup awal/belum ada lockfile |
| frontend/ | npm run lint | Lint jika script sudah tersedia |
| frontend/ | npx --no-install tsc --noEmit | Typecheck memakai compiler lokal yang terpasang |
| frontend/ | npm run build | Verifikasi build Next.js |

- Periksa scripts/config sebelum menjalankan; jangan mengarang npm run test/lint yang belum dibuat. Saat scaffold, buat script standar yang diperlukan dan jelaskan perubahan manifest.
- next build tidak dianggap pengganti lint, test perilaku, atau uji browser. Jangan memakai next lint untuk Next.js 16.
- Sebelum composer install/npm ci/test/migrate, identifikasi environment serta efek script lifecycle. Test harus memakai database dan layanan terisolasi, bukan kredensial produksi dari .env biasa.
- Jangan melakukan upgrade dependency luas untuk memperbaiki satu masalah tanpa analisis. Jangan menjalankan script eksternal/curl-to-shell dari sumber tidak dipercaya.
- Untuk API/library yang meragukan atau berubah, periksa dokumentasi resmi sesuai versi. Contoh dari blog tidak mengalahkan dokumen final atau kontrak repository.
- Tool eksternal/MCP hanya digunakan jika tersedia dan relevan; tidak wajib memasang plugin baru. Jangan mengirim data member ke layanan luar atau masuk akun pribadi tanpa otorisasi yang relevan.

## 9. Pengujian sesuai risiko

| Perubahan | Verifikasi minimum yang relevan |
| --- | --- |
| Copy/token visual sederhana | Tinjau diff dan tampilan/kontras; tidak perlu test yang hanya mengulang konstanta |
| Komponen/form/layout | Lint/tipe, validation states, keyboard/mobile/overflow; component test bila perilaku perlu dijaga |
| Auth/permission | Feature test login/session/CSRF/ownership/role dan skenario ditolak |
| Endpoint/domain | Feature test sukses, validasi, error bisnis, dan akses negatif |
| Aktivasi/credential/kuota | Test MySQL nyata, unique constraint, rollback, request paralel, idempotensi |
| Notification/upload | Commit-dispatch gap, retry, file cleanup, dan kegagalan layanan yang relevan |
| Privacy/backup | Agregat/counter, anonimisasi, replay marker, FK, restore dan nomor sesuai arsitektur |

Kasus kritis wajib ketika modul terkait diimplementasikan: verifikasi paralel hanya satu aktivasi; rotasi paralel hanya satu QR aktif; dua operator pada tempat terakhir; QR/manual bersamaan; timeout setelah commit; void/restore saat penuh; revoke assignment/suspend setelah preview; reaktivasi nomor sama; anonymized tidak dapat direaktivasi.

Uji concurrency memakai koneksi/transaksi paralel nyata pada MySQL 8.4, bukan hanya dua pemanggilan serial atau database in-memory. Test menerima error harus memastikan tidak ada write/counter yang tertinggal.

Gunakan user/fixture sintetis dan email fake/local inbox. Jangan memicu email nyata, scan credential member nyata, atau menguji operasi destruktif pada data produksi tanpa otorisasi. UI test memakai origin lokal/staging yang diperbolehkan.

Jalankan pemeriksaan yang relevan; setelah lulus, jangan mengulang seluruh suite tanpa perubahan/risiko baru. Jika tool/runtime/test DB belum tersedia, laporkan apa yang belum diuji dan dampaknya. Jangan menulis 'semua lulus' untuk pemeriksaan yang tidak dijalankan.

## 10. Keamanan, privasi, dan data

- Jangan commit .env, APP_KEY, password, session, QR token, signed URL penuh, database dump, upload pribadi, atau log request berisi PII. Baca nilai rahasia hanya jika perlu untuk tugas berizin; jangan menampilkannya dalam laporan/tool output.
- NEXT_PUBLIC_* adalah konfigurasi publik; hanya boleh berisi nilai aman seperti API origin. Database tidak diakses langsung oleh Next.js.
- API Resource, upload private, cache no-store untuk credential, output encoding, CSRF, dan policy tidak boleh dilonggarkan demi kemudahan development.
- Email publik existing/reset mengikuti respons generik. Error tidak membocorkan SQL, stack trace, path server, atau data pengguna lain.
- Audit memakai whitelist perubahan tanpa QR/password/session; ekspor CSV terlindungi dari formula input dan hanya role berhak.
- Retensi: pending 30 hari; ended 24 bulan; audit/log 12 bulan; backup 30 hari; target permintaan data ≤ 30 hari setelah identitas diverifikasi. Ikuti detail PRD/arsitektur, jangan membuat hard delete UI yang melewati privacy action.
- Backup harian, RPO 24 jam, RTO 8 jam adalah target disepakati yang harus diuji. Jangan mengklaim pemulihan berhasil tanpa uji; replay erasure marker dan high-water mark nomor sebelum penerbitan ulang setelah restore.

## 11. Otonomi, batas tindakan, dan Git

AI boleh melanjutkan inspeksi, edit lokal yang dapat dipulihkan, perbaikan terkait, penambahan test bermakna, dan verifikasi dalam ruang lingkup tugas tanpa meminta konfirmasi tiap file atau tiap command. Otorisasi yang sudah jelas dalam sesi berlaku untuk langkah yang sama; jangan meminta ulang hanya karena pindah tahap.

Minta keputusan hanya jika informasi yang hilang mengubah perilaku produk, kontrak, atau tindakan berisiko dan belum diotorisasi. Jelaskan masalah yang konkret serta opsi yang direkomendasikan. Jangan menjadikan preferensi internal sebagai alasan menghentikan pekerjaan rutin.

Tanpa otorisasi yang mencakup tindakan tersebut, jangan:

- Mengubah keputusan final, menambah fitur di luar scope, mengganti stack, atau melakukan refactor besar yang tidak terkait.
- Deploy/publish ke produksi, migrasi data nyata, purge massal, atau menjalankan uji beban pada layanan aktif.
- Menghapus/mengembalikan pekerjaan pengguna, force push, reset --hard, atau membersihkan file tidak terlacak secara massal.
- Push ke remote, merge ke branch utama, mengubah pengaturan hosting/akun, membeli layanan, atau mengirim pesan kepada pihak lain.

Jika tindakan sudah diminta secara eksplisit dan target/environment jelas, kerjakan dalam otorisasi tersebut tanpa konfirmasi berulang. Siapkan perubahan dan hasil pemeriksaan sebelum meminta persetujuan akhir yang memang diperlukan. Jangan menganggap izin edit lokal sebagai izin deploy atau kirim email nyata.

Perubahan Git hanya mencakup file tugas. Jangan commit/push otomatis bila tidak termasuk permintaan. Jika diminta commit, jangan memasukkan file tidak terkait; jelaskan perubahan, pengujian, dan batas yang tersisa pada commit/PR sesuai skala tugas.

## 12. Definisi selesai dan laporan hasil

Pekerjaan dinyatakan selesai ketika:

1. Perilaku sesuai requirement tugas, PRD final, arsitektur, dan design system.
2. Jalur sukses, validasi, izin, serta kegagalan relevan tertangani; tidak ada mock sukses yang menyembunyikan integrasi.
3. Schema/API/frontend yang terdampak selaras; kontrak teknis diperbarui bila memang berubah dalam cakupan tugas.
4. Pemeriksaan yang relevan telah dijalankan dan hasilnya dicatat; hambatan dinyatakan jujur.
5. Diff tidak memuat perubahan tidak terkait, rahasia, kode debug, atau hasil build yang seharusnya diabaikan.
6. Tidak ada klaim bahwa seluruh aplikasi selesai hanya karena satu modul berjalan.

Laporan akhir ringkas mencantumkan hasil konkret, file/modul utama yang berubah, pemeriksaan yang dijalankan beserta hasil, dan keterbatasan penting. Pisahkan pekerjaan selesai dari yang belum diuji. Gunakan Bahasa Indonesia dan jangan membanjiri pengguna dengan log mentah.

Jika pekerjaan berlanjut pada sesi lain, gunakan status nyata repo/dokumen dan catatan tugas yang ada. Jangan mengarang progress, menggandakan implementasi, atau mengubah dokumen final menjadi draft kembali.
