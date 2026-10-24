# SYSTEM_DESIGN — sorerunapp

| Atribut | Nilai |
| --- | --- |
| Versi dokumen | 1.1 |
| Tanggal | 21 Oktober 2026 |
| Produk | sorerunapp |
| Komunitas | Sore Running Club (SRC) |
| Jenis dokumen | Design system visual dan pedoman UI/UX |
| Acuan | PRD.md final dan ARCHITECTURE.md final |
| Status | Final — disetujui pemilik produk sebagai acuan desain visual dan UI/UX sorerunapp |
| Arah visual | Palet Sunset, netral cokelat gelap dan krem terang, tipografi Plus Jakarta Sans |

## 1. Tujuan dan batas dokumen

Dokumen ini menentukan identitas visual, warna, tipografi, ukuran, layout, komponen, interaksi, dan pola halaman sorerunapp. Istilah SYSTEM_DESIGN pada dokumen ini berarti sistem desain antarmuka; arsitektur teknis, tabel database, transaksi, dan kontrak API tetap mengacu pada ARCHITECTURE.md.

Tujuannya adalah membuat pengalaman member, petugas event, dan admin konsisten, mudah dipelajari, serta dapat digunakan di HP saat kegiatan luar ruangan. Token dan aturan komponen menjadi acuan implementasi Next.js, Tailwind CSS, dan shadcn/ui.

Palet Sunset beserta warna netral pendamping dan font pada dokumen ini telah dipilih pemilik produk sebagai acuan visual aplikasi; pilihan tersebut bukan klaim bahwa komunitas sudah mempunyai pedoman merek resmi. Logo final belum tersedia; gunakan wordmark teks SRC dan sorerunapp sampai aset resmi ditentukan. PRD dan arsitektur final tetap menjadi sumber aturan bisnis.

## 2. Karakter visual

### 2.1 Konsep: semangat lari sore dan kebersamaan

- **Aktif:** oranye senja memberi penekanan pada tindakan utama.
- **Hangat:** latar terang bernuansa alami dan bahasa yang ramah.
- **Tertib:** netral cokelat gelap, grid teratur, label jelas, dan angka yang mudah dipindai.
- **Inklusif:** tampilan tidak mengesankan aplikasi khusus atlet elite; hindari bahasa yang merendahkan pace atau tingkat pengalaman.
- **Praktis di lapangan:** tombol cukup besar, teks kontras, dan hasil check-in yang tegas.

Tampilan dominan berupa ruang terang, kartu putih, teks gelap, serta penekanan warna seperlunya. Gunakan Deep Brown pada kartu anggota dan bidang identitas komunitas, dengan teks Warm Canvas dan aksen Sunset secukupnya. Gunakan oranye pada CTA utama dan elemen merek; jangan menjadikan semua kartu atau teks berwarna oranye.

### 2.2 Prinsip visual

| Prinsip | Penerapan |
| --- | --- |
| Satu fokus utama per konteks | Kartu member menonjolkan QR; scanner menonjolkan konfirmasi; admin menonjolkan data dan tindakan relevan |
| Hierarki yang terlihat | Judul → informasi utama → status → bantuan → tindakan |
| Status bermakna | Selalu ada teks/ikon; warna menjadi penguat |
| Konsisten lintas role | Font, spacing, tombol, dan arti warna sama; navigasi sesuai tugas |
| Informasi yang cukup | Petugas hanya melihat nama, nomor, foto jika ada, dan status yang diperlukan |
| Aksi sesuai bisnis | Event tanpa RSVP tidak menampilkan tombol Daftar Event, Pesan Tempat, atau Beli Tiket |

## 3. Identitas merek dan penggunaan aset

| Elemen | Ketentuan |
| --- | --- |
| Nama aplikasi | sorerunapp, huruf kecil dalam wordmark dan penyebutan produk |
| Nama komunitas | Sore Running Club |
| Singkatan | SRC |
| Wordmark sementara | Teks SRC tebal, dengan Sore Running Club sebagai pendamping; sorerunapp tampil sebagai nama aplikasi |
| Varian terang | Teks Deep Brown pada latar White atau Warm Canvas |
| Varian gelap | Teks Warm Canvas pada Deep Brown |
| Ruang aman logo | Minimum 12 px atau setengah tinggi wordmark, ambil yang lebih besar |
| Foto | Foto kegiatan yang diizinkan penggunaannya; bukan syarat agar form/scanner berfungsi |

Jangan membuat simbol SRC seolah-olah logo resmi. Hindari efek neon, glassmorphism pada area data, gradient di belakang teks kecil, serta motif lintasan yang menutupi QR atau form. Ornamen garis lintasan dapat muncul tipis pada area dekoratif kartu, di luar panel QR.

## 4. Sistem warna

### 4.1 Palet utama dan netral pendamping

Empat warna Sunset berasal dari gambar referensi pertama yang dipilih pemilik produk. Deep Brown, Warm Canvas, White, Muted Brown, Soft Border, Input Border, dan Deep Orange melengkapi kebutuhan teks, form, serta interaksi yang dapat dibaca.

| Token | Nama | HEX | Penggunaan |
| --- | --- | --- | --- |
| brand-primary | Sunset Orange | #EA6113 | Warna utama merek dan CTA utama dengan teks Deep Brown |
| brand-warm | Warm Orange | #F88F22 | Hover/pressed CTA utama, aksen dan dekorasi |
| brand-gold | Golden Yellow | #FBB931 | Sorotan kecil, dekorasi, fokus pada bidang gelap |
| brand-cream | Sunset Cream | #FFE3B3 | Panel pendukung dan aksen lembut |
| brand-dark | Deep Brown | #2B2118 | Panel identitas SRC, kartu anggota, teks utama dan teks CTA oranye |
| canvas / brand-light | Warm Canvas | #FFF9F0 | Latar halaman dan teks pada bidang Deep Brown |
| surface | White | #FFFFFF | Form, input, tabel, dialog, panel QR |
| ink-muted | Muted Brown | #6B5B4D | Teks bantuan, deskripsi, metadata dan placeholder |
| border | Soft Border | #E8DED3 | Pemisah dan batas kartu dekoratif |
| input-border | Input Border | #8A7868 | Batas input dan kontrol form |
| brand-deep | Deep Orange | #B9470B | Tautan, teks penekanan dan fokus pada White/Warm Canvas |

CTA Sunset Orange dan Warm Orange memakai teks Deep Brown, bukan teks putih. Golden Yellow dan Warm Orange tidak digunakan sebagai teks kecil atau ikon fungsional pada latar putih. Tautan pada White/Warm Canvas memakai Deep Orange dengan underline; tautan pada panel Sunset Cream memakai Deep Brown dengan underline agar kontras tetap cukup.

Gradasi Sunset Orange → Warm Orange → Golden Yellow → Sunset Cream boleh digunakan sebagai dekorasi pada panel merek, di luar area form dan QR. Teks penting memakai bidang solid yang kontras; gradasi bukan latar tombol utama, teks kecil, atau panel QR. Warna status tetap mengikuti makna pada bagian 4.3.

### 4.2 Token semantik dan batas komponen

Token semantik berikut memetakan palet yang sama ke kebutuhan komponen; tidak menambahkan warna merek baru.

| Token | HEX | Penggunaan |
| --- | --- | --- |
| primary | #EA6113 | Background CTA utama |
| primary-foreground | #2B2118 | Teks/ikon CTA utama |
| primary-hover | #F88F22 | Hover/pressed CTA; foreground tetap Deep Brown |
| secondary | #FFE3B3 | Background secondary button dan selected navigation ringan |
| secondary-foreground | #2B2118 | Teks/ikon pada secondary |
| muted | #E8DED3 | Section ringan, skeleton base, disabled background |
| muted-foreground | #6B5B4D | Bantuan, metadata, placeholder dan teks disabled |
| accent | #FFE3B3 | Penekanan brand ringan |
| accent-foreground | #2B2118 | Teks pada accent |
| link | #B9470B | Tautan pada White/Warm Canvas dengan underline |
| link-hover | #2B2118 | Hover tautan pada White/Warm Canvas; underline tetap terlihat |
| border | #E8DED3 | Pemisah dekoratif, batas kartu noninteraktif |
| input-border | #8A7868 | Batas field dan kontrol yang diperlukan untuk mengenali input |
| focus-ring | #B9470B | Fokus pada White/Warm Canvas; gunakan offset agar tidak menyatu dengan tombol |
| focus-ring-on-dark | #FBB931 | Fokus pada bidang Deep Brown |
| overlay | rgba(43,33,24,0.56) | Scrim dialog/sheet di belakang panel |
| qr-foreground | #000000 | Modul QR |
| qr-background | #FFFFFF | Panel dan quiet zone QR |

Border dekoratif yang tipis bukan pengganti batas field yang dapat dikenali. Jangan memakai warna border dekoratif sebagai satu-satunya penanda input atau checkbox yang belum dipilih.

### 4.3 Warna status

| Makna | Foreground | Background | Contoh label |
| --- | --- | --- | --- |
| Success | #166534 | #F0FDF4 | Aktif, Check-in berhasil |
| Warning | #92400E | #FFFBEB | Menunggu verifikasi, Kuota penuh, Ditangguhkan |
| Danger | #B91C1C | #FEF2F2 | Akun diblokir, QR tidak berlaku, Kehadiran dibatalkan |
| Info | #1D4ED8 | #EFF6FF | Sudah check-in, Event dipublikasikan |
| Neutral | #6B5B4D | #E8DED3 | Draft, Selesai, Keanggotaan berakhir |

Primary orange berarti tindakan/merek, bukan otomatis peringatan. Hijau berarti hasil sah/aktif, bukan sekadar QR terbaca. Status cancelled dan voided mempunyai label berbeda meskipun memakai warna danger yang sama.

### 4.4 Kontras yang diperiksa

Rasio berikut dihitung dari nilai HEX solid memakai relative luminance sRGB. Angka ditampilkan sampai dua desimal untuk ringkasan; pemeriksaan kelulusan memakai nilai sebelum pembulatan. Rasio token bukan bukti bahwa seluruh aplikasi sudah memenuhi aksesibilitas.

| Pasangan foreground / background | Rasio sekitar | Penggunaan |
| --- | --- | --- |
| #2B2118 / #FFFFFF | 15.75:1 | Teks utama pada form/kartu terang |
| #2B2118 / #FFF9F0 | 15.04:1 | Teks utama halaman |
| #6B5B4D / #FFF9F0 | 6.21:1 | Teks pendamping pada canvas |
| #6B5B4D / #FFFFFF | 6.50:1 | Placeholder/bantuan pada input putih |
| #2B2118 / #EA6113 | 4.66:1 | Teks primary button |
| #2B2118 / #F88F22 | 6.71:1 | Teks primary hover/pressed |
| #FFF9F0 / #2B2118 | 15.04:1 | Teks kartu anggota/panel identitas gelap |
| #2B2118 / #FFE3B3 | 12.67:1 | Teks secondary button/panel cream |
| #B9470B / #FFFFFF | 5.29:1 | Tautan pada surface |
| #B9470B / #FFF9F0 | 5.05:1 | Tautan/focus ring pada canvas |
| #166534 / #F0FDF4 | 6.81:1 | Success |
| #92400E / #FFFBEB | 6.84:1 | Warning |
| #B91C1C / #FEF2F2 | 5.91:1 | Error |
| #1D4ED8 / #EFF6FF | 6.16:1 | Info |
| #6B5B4D / #E8DED3 | 4.90:1 | Neutral/disabled |
| #8A7868 / #FFFFFF | 4.23:1 | Batas input pada surface |
| #8A7868 / #FFF9F0 | 4.04:1 | Batas kontrol pada canvas |
| #FBB931 / #2B2118 | 9.06:1 | Focus ring pada bidang gelap |

Teks putih (#FFFFFF) pada Sunset Orange (#EA6113) hanya sekitar 3.38:1 dan tidak dipakai untuk label tombol ukuran normal. Deep Orange pada Sunset Cream hanya sekitar 4.26:1; gunakan Deep Brown untuk teks/tautan pada panel cream.

Target normal text minimum 4.5:1. Elemen visual kontrol yang diperlukan minimum 3:1 terhadap warna yang bersebelahan. Produk menargetkan teks besar tetap 4.5:1 bila memungkinkan. Opacity, gambar, gradient, dan overlay mengubah hasil aktual dan perlu diperiksa kembali.

## 5. Tipografi

### 5.1 Font

Font utama adalah **Plus Jakarta Sans** untuk heading, body, label, dan tombol. Pilihan ini memberikan karakter kontemporer tanpa menambah banyak keluarga font.

- Weight yang digunakan: 400, 500, 600, 700, 800.
- Font angka memakai keluarga yang sama dengan font-variant-numeric: tabular-nums untuk kuota, statistik, timer, dan tabel angka.
- Nomor member dapat memakai ui-monospace bila perlu membedakan karakter, tetapi nama orang tetap font utama.
- Fallback: ui-sans-serif, system-ui, sans-serif.
- Font disajikan melalui next/font; pilih file lokal resmi berlisensi yang disimpan di frontend/src/assets/fonts/ dan sertakan lisensinya.
- Gunakan display swap dan fallback yang stabil; font gagal dimuat tidak boleh menghalangi QR, scanner, atau form.

Plus Jakarta Sans tersedia dengan SIL Open Font License. Lisensi/font yang benar tetap disertakan ketika aset diambil; tidak perlu meminta browser memuat font dari domain pihak ketiga saat penggunaan aplikasi.

### 5.2 Skala teks

Ukuran pada tabel adalah CSS px pada root 16 px; implementasi memakai rem agar mengikuti preferensi ukuran teks pengguna.

| Token | Mobile | Desktop | Line height | Weight | Penggunaan |
| --- | --- | --- | --- | --- | --- |
| display | 32 px | 48 px | 1.15 | 800 | Hero publik seperlunya |
| heading-1 | 28 px | 36 px | 1.25 | 700 | Judul halaman |
| heading-2 | 24 px | 28 px | 1.30 | 700 | Judul section |
| heading-3 | 20 px | 20 px | 1.40 | 600 | Judul kartu/dialog |
| body | 16 px | 16 px | 1.60 | 400 | Isi dan input |
| body-strong | 16 px | 16 px | 1.50 | 600 | Informasi utama/hasil |
| label | 14 px | 14 px | 1.45 | 600 | Label form, tombol, navigasi |
| supporting | 14 px | 14 px | 1.50 | 400 | Bantuan, metadata, tabel |
| caption | 12 px | 12 px | 1.50 | 500 | Informasi nonkritis yang singkat |
| stat | 28 px | 32 px | 1.20 | 700 | Ringkasan statistik |
| scanner-result | 24 px | 28 px | 1.30 | 700 | Hasil check-in |

Jangan menggunakan caption untuk pesan error penting, hasil scan, nominal kuota, atau label field. Input menggunakan 16 px termasuk di mobile. Nama panjang dapat wrap; jangan membuat heading seluruhnya uppercase. Tracking heading sekitar -0.02em; body normal. Paragraf panjang maksimum sekitar 65–75 karakter per baris.

## 6. Spacing, ukuran, bentuk, dan elevation

### 6.1 Skala spacing

| Token | Nilai | Contoh |
| --- | --- | --- |
| space-1 | 4 px | Ikon ke teks yang sangat rapat |
| space-2 | 8 px | Jarak antar label/helper; item kecil |
| space-3 | 12 px | Isi badge besar, grup kontrol |
| space-4 | 16 px | Gap field dan padding mobile |
| space-5 | 20 px | Padding panel ringkas |
| space-6 | 24 px | Padding kartu desktop, antar kelompok form |
| space-8 | 32 px | Antar section |
| space-10 | 40 px | Section utama |
| space-12 | 48 px | Ritme halaman publik |
| space-16 | 64 px | Ruang hero pada layar besar |

Label ke input 8 px; input ke helper 8 px; antar-field 16–20 px; antar-section form 24–32 px. Gunakan token, bukan nilai acak per halaman.

### 6.2 Bentuk dan ukuran kontrol

| Elemen | Ketentuan |
| --- | --- |
| Button/input/select | Tinggi minimum 44 px; radius 12 px |
| Tombol utama scanner | Tinggi minimum 52 px; lebar penuh pada mobile |
| Icon button | Hit area minimum 44 × 44 px; ikon 20 px |
| Badge | Tinggi visual sekitar 24–28 px; bukan tombol |
| Card | Radius 20 px; padding 16 px mobile, 24 px desktop |
| Kartu member | Radius 24 px, QR panel tidak terpotong |
| Dialog/sheet panel | Radius 20 px; padding 20–24 px |
| Avatar | 40 px navigasi, 64 px profil, 80 px preview identitas |
| Table row | Minimum 52 px; teks dapat wrap |
| Divider | 1 px dekoratif |

Checkbox/radio dapat mempunyai indikator 20 px dengan label/hit area gabungan setidaknya 44 px. Jangan mengecilkan kontrol hanya agar semua kolom muat. Mobile menggunakan form/card list, bukan memampatkan desktop table.

### 6.3 Shadow, border, dan lapisan

- Kartu biasa: border 1 px dekoratif, tanpa shadow besar.
- Kartu menonjol: shadow 0 4px 16px rgba(43,33,24,0.06).
- Dialog/popover: shadow 0 16px 48px rgba(43,33,24,0.16).
- Focus memakai ring/outline, tidak hanya shadow tipis.
- Skala z-index: konten 0, sticky header 20, mobile navigation 30, popover 40, modal 50, toast 60. Primitive mengelola nesting/focus dengan benar.

Latar dimmed bukan alasan mengurangi kontras teks pada dialog. Hindari banyak kartu bertumpuk yang menghasilkan shadow berlapis.

## 7. Grid dan layout responsif

| Lebar viewport | Grid/konten | Perilaku |
| --- | --- | --- |
| < 640 px | 4 kolom; gutter/padding 16 px | Satu kolom utama; CTA lebar penuh jika sesuai |
| 640–767 px | 8 kolom; padding 24 px | Kartu dapat dua kolom jika isi tetap nyaman |
| 768–1023 px | 8 kolom; padding 24 px | Navigasi member atas; admin memakai drawer |
| ≥ 1024 px | 12 kolom; padding 32 px | Sidebar admin/staff sesuai konteks; form dapat dua kolom |
| ≥ 1280 px | Konten max 1280 px; admin max 1440 px | Konten terpusat; jangan melebarkan paragraf tanpa batas |

Form auth max 440 px; form profil max 760 px; kartu member max 420 px. Data table boleh menggunakan ruang admin lebih lebar. Grid adalah pedoman komposisi, bukan kewajiban setiap section harus merender semua kolom.

### 7.1 Shell member

- Header setinggi minimum 64 px: wordmark, identitas pengguna, menu akun.
- Pada mobile, bottom navigation: Beranda, Kartu, Event, Profil; riwayat diakses dari Beranda dan menu akun.
- Pada ≥ 768 px, navigasi utama pindah ke header; tidak menggandakan bottom navigation.
- Konten mempunyai ruang bawah untuk tinggi navigation dan env(safe-area-inset-bottom).
- Menu untuk berpindah area hanya muncul jika pengguna mempunyai role terkait.

### 7.2 Shell petugas

Daftar event tugas menjadi titik masuk. Scanner memakai layout khusus: header konteks event, area kamera, panel hasil/konfirmasi, dan akses pencarian manual. Kurangi navigasi yang tidak diperlukan saat scanning; tombol kembali tetap tersedia. Petugas tidak melihat menu status anggota, ekspor seluruh database, atau pengelolaan role.

### 7.3 Shell admin

Desktop memakai sidebar sekitar 248 px dan topbar 64 px. Menu: Ringkasan, Anggota, Event, Laporan, Audit. Penugasan dan kehadiran tersedia dari detail event. Mobile memakai drawer dengan focus trap dan tombol tutup yang jelas.

Tidak ada horizontal scroll pada body pada lebar 320 CSS px. Tabel yang benar-benar membutuhkan dua dimensi boleh scroll horizontal dalam region yang berlabel, dengan kolom identitas/tindakan tetap dapat dijangkau. Sticky header/action tidak menutupi fokus, error, atau input saat keyboard mobile terbuka.

## 8. Inventaris dan kontrak komponen UI

### 8.1 Komponen dasar

| Komponen | Varian/perilaku wajib |
| --- | --- |
| Button | Primary, secondary, outline, ghost, destructive, loading, disabled |
| Input | Text, email, tel, password; default/focus/error/disabled/read-only |
| Textarea | Alasan koreksi/status; label, batas input, helper |
| Select/Combobox | Pilihan yang valid; keyboard dan empty search |
| Date field | Input tanggal yang dapat diketik + date picker sebagai bantuan |
| Checkbox/Radio | Label klik, keyboard, error group |
| FormField | Label, required marker, input, helper, error terhubung |
| Card | Header/body/footer konsisten |
| Badge | Status semantik, ikon dan teks |
| Alert | Info, warning, error, success yang menetap di konteks |
| Toast | Umpan balik singkat; bukan satu-satunya hasil check-in |
| Dialog/AlertDialog | Konfirmasi, focus trap, return focus, loading/error |
| Sheet/Drawer | Navigasi mobile dan filter; dapat ditutup keyboard |
| Table/Pagination | Sort/filter berizin, loading/empty/error, teks wrap |
| Tabs | Subbagian halaman, keyboard navigation dan state aktif |
| Skeleton/Spinner | Skeleton untuk initial load, spinner untuk request aktif |
| Avatar | Foto atau inisial; foto tidak tersedia ditandai |
| Tooltip | Bantuan tambahan; bukan satu-satunya label tombol |
| Progress/Quota | Angka hadir/kuota dan keterangan; visual pelengkap |

### 8.2 Komponen domain

| Komponen | Tanggung jawab tampilan |
| --- | --- |
| MemberCard | Identitas, nomor, status, tanggal bergabung, QR eligible |
| MembershipStatus | Pemetaan status membership terpisah dari akun/email |
| EventCard | Nama, waktu, lokasi, status, kuota server, detail |
| EventContextHeader | Event aktif scanner, tanggal/lokasi, keadaan check-in |
| ScannerViewport | Video, panduan posisi, izin, pesan kamera |
| MemberIdentityPreview | Nama, nomor, foto/fallback, konfirmasi identitas |
| CheckInResult | Hasil backend, waktu yang sah, tindakan berikutnya |
| ManualCheckInForm | Pencarian, identifikasi, alasan, konfirmasi |
| AttendanceList | Catatan valid/voided sesuai scope pengguna |
| AttendanceCorrectionDialog | Alasan dan dampak void/restore |
| QuotaIndicator | Hadir valid/kapasitas, tersisa, last updated |
| AuditChangeSummary | Pelaku, waktu, tindakan, perubahan yang boleh dilihat |
| EmptyState/ErrorState | Penjelasan dan tindakan yang sesuai konteks |

Komponen domain berada pada src/features terkait sesuai arsitektur. Komponen lintas fitur yang benar-benar generik dapat dipindah ke src/components/shared/. Komponen tidak membuat keputusan kuota, eligibility, atau permission final sendiri.

## 9. Tombol, form, dan validasi

### 9.1 Tombol dan interaksi

| Varian | Warna/perilaku | Contoh |
| --- | --- | --- |
| Primary | Sunset Orange, teks Deep Brown; hover/pressed Warm Orange dengan teks Deep Brown | Daftar Member, Simpan Perubahan, Konfirmasi Hadir |
| Secondary | Sunset Cream dengan teks Deep Brown | Lihat Kartu, Kembali |
| Outline | Putih, border input-border, teks ink | Batal, Filter |
| Ghost | Tanpa bidang solid; teks kontras dan hover surface | Menu sekunder, kembali dari detail |
| Destructive | Danger solid dengan teks terang yang diperiksa kontrasnya | Batalkan Kehadiran setelah konfirmasi |
| Loading | Spinner + kata kerja; ukuran tombol tetap | Menyimpan…, Mengonfirmasi… |
| Disabled | Bidang muted, teks muted; native disabled bila sesuai | Kirim Ulang Email selama cooldown |

Satu primary action dominan per panel/dialog. Disabled harus mempunyai alasan dekat kontrol jika tidak jelas. Jangan hanya menurunkan opacity seluruh komponen sehingga teks sukar dibaca. Hindari tombol ikon tanpa accessible name.

Fokus keyboard berupa outline minimum 2 px, offset 2 px pada latar yang kontras. Gunakan ring-on-dark untuk kartu gelap. Hover tidak menjadi satu-satunya petunjuk; semua tindakan dapat digunakan lewat sentuh dan keyboard.

### 9.2 Form registrasi dan profil

Urutan registrasi: identitas utama → akun → pengakuan kebijakan → Daftar Member. Data wajib adalah nama, tanggal lahir, telepon, domisili, email, password, dan konfirmasi password; foto serta jenis kelamin opsional. Tidak meminta NIK, KTP, alamat lengkap, atau data kesehatan.

- Label selalu terlihat di atas field; placeholder hanya contoh.
- Tulis '(opsional)' pada field opsional dan jelaskan arti tanda wajib sekali di awal form.
- Password mempunyai tombol Tampilkan/Sembunyikan dengan label yang berubah; izinkan paste dan password manager.
- Tanggal lahir dapat diketik; jangan mewajibkan pengguna mengklik kalender mundur puluhan tahun. Format tampilan dd/mm/yyyy dengan label/contoh, nilai API mengikuti kontrak.
- Telepon memakai inputmode tel, bukan number yang menghilangkan tanda + atau nol awal.
- Error muncul setelah interaksi/submit; jangan menampilkan semua field merah ketika halaman baru dibuka.
- Submit invalid menampilkan ringkasan error dan mengarahkan fokus ke error/field pertama tanpa menghapus input.
- Error server ditampilkan dekat field yang relevan; error umum ditampilkan sebagai alert form.
- Pengakuan ketentuan/privasi tidak dicentang otomatis dan tidak digabung dengan promosi.

Contoh helper password: 'Gunakan minimal 12 karakter.' Contoh error tanggal: 'Masukkan tanggal lahir yang valid dan tidak di masa depan.' Jangan menolak anggota hanya karena usia di bawah umur ketika backend tidak mempunyai aturan tersebut.

## 10. Status domain dan presentasi

| Objek/status | Label UI | Gaya | Catatan |
| --- | --- | --- | --- |
| Email unverified | Email belum diverifikasi | Warning | Aksi Kirim Ulang Email |
| Membership pending_verification | Menunggu verifikasi | Warning | Kartu aktif belum tersedia |
| Membership active | Aktif | Success | Kartu tampil jika syarat lain terpenuhi |
| Membership suspended | Ditangguhkan | Warning | Jelaskan bantuan pengurus; check-in ditolak |
| Membership ended | Keanggotaan berakhir | Neutral | Reaktivasi melalui admin |
| Account blocked | Akun diblokir | Danger | Berbeda dari membership ended |
| Credential revoked | QR tidak berlaku | Danger | Tidak menampilkan QR sebagai credential aktif |
| Event draft | Draft | Neutral | Admin saja |
| Event published | Dipublikasikan | Info | Waktu check-in adalah status terpisah |
| Event cancelled | Event dibatalkan | Danger | Catatan lama tetap diberi konteks |
| Event completed | Selesai | Neutral | Check-in ditutup |
| Attendance valid | Hadir | Success | Mempengaruhi kuota dan laporan |
| Attendance voided | Kehadiran dibatalkan | Danger | Tidak dihitung hadir valid |

Tampilkan status event dan jendela check-in secara terpisah, misalnya 'Dipublikasikan · Check-in belum dibuka'. Kuota penuh bukan berarti event dibatalkan. QR terbaca bukan berarti anggota sudah hadir.

## 11. Pola halaman member dan akun

### 11.1 Registrasi, login, dan verifikasi

Desktop auth dapat menggunakan dua kolom: panel brand singkat dan form 440 px. Pada mobile, prioritaskan form; panel dekoratif dipersingkat. Nama komunitas jelas agar pengguna tahu tempat mendaftar.

Komposisi warna auth: latar halaman Warm Canvas, input/panel form White, teks utama Deep Brown, serta tombol Masuk/Daftar Member Sunset Orange dengan teks Deep Brown. Panel identitas desktop atau header merek mobile memakai Deep Brown dengan teks Warm Canvas dan aksen Sunset. Tautan pada latar terang memakai Deep Orange; dekorasi gradasi dibatasi sesuai bagian 4.1.

Halaman hasil registrasi menampilkan instruksi verifikasi dan alamat tujuan yang dimasking jika diperlukan, bukan kartu aktif. Pesan publik email existing mengikuti kontrak generik agar tidak membocorkan akun. Resend menampilkan cooldown dalam detik dan pesan bahwa email sedang diproses, bukan klaim sudah terkirim.

Hasil verifikasi memisahkan berhasil, sudah diverifikasi, kedaluwarsa, invalid, perlu login, dan profil belum lengkap. Setiap keadaan mempunyai langkah lanjut yang sesuai. Tidak menawarkan approval admin karena aktivasi otomatis.

### 11.2 Beranda member

Urutan konten: sapaan/nama → status keanggotaan → akses Kartu Anggota → event terdekat → riwayat singkat. Tanggal kegiatan dan lokasi lebih menonjol daripada dekorasi. Ringkasan aktivitas menunjukkan jumlah event yang benar-benar dihadiri, bukan jarak/pace yang belum dicatat sistem.

Event berkuota menampilkan 'Hadir 80 dari 100' dan 'Tersisa 20 tempat' dari server. Hindari '20 tiket tersisa' atau tombol Daftar karena tidak ada reservasi. Beri helper: 'Kehadiran dicatat petugas saat acara. Tempat tersedia mengikuti kuota saat check-in.'

### 11.3 Kartu anggota

| Area | Spesifikasi |
| --- | --- |
| Bidang utama | Deep Brown; wordmark Warm Canvas; aksen Sunset terbatas di luar panel QR |
| Identitas | Nama dapat wrap, nomor member tabular/monospace, foto jika ada |
| Status | Badge Aktif terpisah, tanggal bergabung |
| Panel QR | Putih penuh, QR hitam, terpusat; jauh dari ornamen |
| Ukuran | Card max 420 px; panel QR sasaran sekitar 240–288 px termasuk quiet zone, dibatasi lebar container |
| Aksi | Lihat QR lebih besar melalui dialog; petunjuk menunjukkan kepada petugas |

Quiet zone minimal empat modul pada seluruh sisi QR standar; padding tidak ditentukan hanya sebagai jumlah px tetap. Hindari logo di tengah, gradient, QR terbalik putih pada gelap, cropping sudut, modul berbentuk dekoratif, atau stiker yang menutupi kode. Generate dari credential asli melalui library, bukan membuat gambar QR dekoratif.

QR harus tetap terlihat tajam, proporsional, dan tidak overflow pada viewport 320 px. Gunakan keluaran library yang menjaga geometri; jangan stretch. Kartu cetak/screenshot diperbolehkan, tetapi validitas selalu diperiksa server. Tampilan cetak menyembunyikan navigasi dan menjaga panel QR tetap hitam-putih; tidak menambahkan data pribadi di dalam QR.

Saat eligibility tidak terpenuhi, ganti panel credential dengan status dan bantuan yang relevan; jangan sekadar memberi overlay transparan di atas QR yang masih bisa dibaca. Nama lengkap/email/telepon tidak ditaruh sebagai alt text QR. Gunakan deskripsi 'QR keanggotaan untuk dipindai petugas' dan identitas tekstual di area lain.

### 11.4 Profil dan riwayat

Profil memakai section Identitas, Akun, dan informasi opsional. Status perubahan email pending terlihat jelas: alamat lama tetap aktif sampai verifikasi baru. Jangan menampilkan email baru sebagai aktif sebelum konfirmasi server.

Riwayat menampilkan nama event, tanggal lokal, waktu hadir, status catatan, dan konteks pembatalan event bila ada. Tidak menyediakan tombol member untuk memulihkan attendance voided atau mengaktifkan kembali membership.

## 12. Scanner dan operasi lapangan

### 12.1 Komposisi halaman

| Urutan | Elemen |
| --- | --- |
| 1 | Header event: nama, tanggal, lokasi, label jendela check-in |
| 2 | Kuota terkini: hadir/kapasitas dan tersisa; last updated bila refresh tertunda |
| 3 | Area kamera dengan instruksi 'Arahkan kamera ke QR anggota' |
| 4 | Preview identitas atau panel hasil; tidak menutupi konteks event |
| 5 | Konfirmasi Hadir atau Scan Berikutnya sesuai state |
| 6 | Cari Anggota untuk check-in manual; Kembali ke Event |

Kamera pada latar gelap diperbolehkan sebagai kebutuhan viewport, sementara informasi dan tombol tetap mempunyai panel kontras. Frame scanner sederhana dengan label; hindari garis laser berkedip. Layout responsif menumpuk pada mobile; tablet dapat menaruh kamera dan hasil berdampingan jika identitas tetap terbaca.

### 12.2 State scanner

| State | Presentasi | Tindakan |
| --- | --- | --- |
| Belum meminta kamera | Instruksi singkat | Aktifkan Kamera |
| Meminta izin | Spinner dan penjelasan | Tunggu; tidak klaim kamera rusak |
| Izin ditolak | Alert dan petunjuk pengaturan browser | Coba Lagi atau Cari Anggota |
| Kamera tidak tersedia | Pesan kamera tidak ditemukan/tidak dapat dipakai | Cari Anggota |
| Siap scan | Video dan panduan posisi | Scan QR |
| Memvalidasi QR | Pause penerimaan scan baru; loading | Tunggu |
| Preview identitas | Foto/inisial, nama, nomor, status yang diperlukan | Konfirmasi Hadir atau Batal |
| Mengonfirmasi | Tombol disabled, label Mengonfirmasi… | Jangan menerima scan baru |
| Tersimpan | Panel success, nama dan waktu hadir dari backend | Scan Berikutnya |
| Sudah hadir | Panel info dan waktu hadir sebelumnya | Scan Berikutnya; tanpa hitungan tambahan |
| QR invalid/revoked | Panel danger tanpa membocorkan data pemilik | Scan Ulang atau Cari Anggota |
| Tidak eligible | Alasan bisnis yang boleh ditampilkan | Arahkan ke pengurus; tanpa bypass |
| Kuota penuh | Panel warning; tidak membuat kehadiran | Kembali/Lihat Daftar Hadir |
| Event ditutup | Panel warning/info sesuai konteks | Kembali ke Event |
| Hasil belum terkonfirmasi | Pesan koneksi dan status penyimpanan belum diketahui | Periksa Status atau retry aman |

**Aturan penting:** centang hijau dan teks Check-in berhasil hanya muncul setelah backend mengonfirmasi commit. Preview yang valid menggunakan label 'QR dikenali — cocokkan identitas', bukan 'Berhasil hadir'. Jangan menambah angka kuota secara optimistis.

Saat timeout, gunakan 'Hasil belum terkonfirmasi. Periksa status sebelum mencoba kembali.' Jangan menyatakan pasti gagal karena server mungkin sudah menyimpan. Hasil diperiksa melalui endpoint berizin; retry tidak membuat catatan baru. Perubahan status setelah preview harus mengikuti respons backend saat konfirmasi.

Hasil bertahan sampai petugas memilih Scan Berikutnya atau Batal, bukan menghilang otomatis dalam satu detik. Bunyi/getaran dapat menjadi penguat opsional jika perangkat mendukung dan pengguna mengizinkan; tidak menjadi satu-satunya pemberitahuan. Hentikan kamera ketika keluar halaman atau session berakhir.

### 12.3 Manual check-in

Cari berdasarkan nomor member/nama, tampilkan hasil terbatas, pilih identitas, lalu isi alasan dan Konfirmasi Hadir. Foto yang tidak tersedia ditandai. Tidak menampilkan daftar telepon/tanggal lahir untuk semua hasil pencarian. Label manual menjelaskan bahwa syarat membership dan kuota tetap berlaku; tidak menawarkan opsi 'paksa masuk'.

## 13. Admin, tabel, kuota, dan tindakan sensitif

### 13.1 Tabel dan filter

| Area | Kolom utama |
| --- | --- |
| Anggota | Nama/nomor, status membership, status email/akun yang relevan, tanggal bergabung, tindakan |
| Event | Nama, jadwal, status event, keadaan check-in, hadir/kuota, tindakan |
| Kehadiran | Nama/nomor, waktu hadir, metode, valid/voided, petugas, tindakan berizin |
| Audit | Waktu, pelaku, tindakan, objek, ringkasan perubahan |

Search mempunyai label dan tombol clear. Filter aktif terlihat sebagai chip yang dapat dihapus; Reset Filter tersedia. Sort aktif mempunyai ikon/label; pagination mempertahankan filter. Jangan memakai placeholder kosong untuk nama yang panjang: izinkan wrap atau detail yang dapat diakses keyboard.

Pada mobile, prioritaskan identitas dan status; gunakan card list atau region tabel yang dapat digeser dengan label. Tindakan berada pada menu dengan accessible name, misalnya 'Tindakan untuk anggota ini'. Jangan mengandalkan hover untuk membuka tombol edit/koreksi.

### 13.2 Kuota

Tampilkan angka valid/kapasitas lebih dahulu, bar progres sesudahnya. Tampilan 80/100 berarti 80 kehadiran valid tersimpan, bukan 80 reservasi. Label penuh muncul hanya berdasarkan respons server terbaru; tidak menandakan event batal.

Form capacity menunjukkan nilai sekarang, jumlah hadir, dan batas minimum yang diperbolehkan. Error penurunan di bawah jumlah hadir ditampilkan di field dengan nilai server terbaru. Jangan membolehkan UI override batas. Saat restore/reconciliation ditolak karena penuh, tampilkan alasan tanpa mengubah daftar secara optimistis.

### 13.3 Dialog tindakan sensitif

| Tindakan | Konteks yang wajib terlihat | CTA |
| --- | --- | --- |
| Menangguhkan membership | Nama/nomor, status awal, dampak check-in, alasan | Tangguhkan Keanggotaan |
| Mengakhiri membership | Identitas dan dampak; riwayat tetap tersimpan sesuai kebijakan | Akhiri Keanggotaan |
| Reaktivasi | Identitas, status awal, syarat dari server, alasan | Aktifkan Kembali |
| Mengganti QR | Identitas dan informasi QR lama tidak berlaku | Ganti QR |
| Void attendance | Event, anggota, waktu hadir, alasan, dampak jumlah hadir | Batalkan Kehadiran |
| Restore attendance | Catatan yang sama, alasan, kebutuhan kapasitas | Pulihkan Kehadiran |
| Membatalkan event | Nama/tanggal event, dampak check-in, alasan | Batalkan Event |

Batal tersedia sebagai tindakan aman. Fokus awal tidak langsung pada tombol destruktif untuk tindakan berdampak besar. Ketika request berlangsung, cegah submit ulang dan tampilkan proses. Setelah sukses, kembali ke konteks asal dan refresh data. Error tetap berada pada dialog agar pengguna dapat memperbaiki alasan atau membaca konflik.

## 14. Loading, empty, error, dan umpan balik

| Keadaan | Pola tampilan |
| --- | --- |
| Initial load | Skeleton mengikuti bentuk konten; jangan menampilkan angka 0 palsu |
| Background refresh | Konten lama tetap terlihat dengan indikator kecil; label stale jika penting |
| Daftar kosong | Judul, alasan kontekstual, tindakan relevan |
| Filter tanpa hasil | 'Tidak ada hasil untuk filter ini' dan Reset Filter |
| Gagal mengambil data | Alert dengan Coba Lagi; konten privat tidak ditampilkan untuk akun lain |
| Session expired | Hentikan aksi/kamera, bersihkan data privat, arahkan login dengan return path aman |
| Tidak berwenang | Halaman akses terbatas dan navigasi kembali yang sesuai |
| 404 | Informasi tidak ditemukan tanpa detail internal |
| Konflik bisnis | Pesan spesifik kuota/status; jangan memakai pesan server error umum |
| Sukses nonkritis | Toast sekitar 4–6 detik ditambah perubahan konteks yang terlihat |

Scanner dan tindakan sensitif memakai panel/alert menetap untuk hasil penting, bukan toast saja. Error inline tidak hilang karena toast ditutup. Spinner mempunyai label pembaca layar; status asynchronous menggunakan live region yang tidak membaca frame kamera terus-menerus.

Contoh empty state member: 'Belum ada riwayat kegiatan. Kehadiran akan muncul setelah QR Anda dikonfirmasi petugas.' Tidak mengarahkan member mendaftar ulang atau melakukan self check-in.

## 15. Bahasa, format data, ikon, dan gerakan

### 15.1 Bahasa dan format

- Bahasa utama Indonesia; gunakan istilah Anggota/Keanggotaan pada UI, kecuali nomor member sebagai istilah yang dikenal.
- Gunakan 'Anda' secara konsisten untuk member; instruksi petugas singkat dan langsung.
- Istilah check-in digunakan konsisten untuk pencatatan hadir. Hindari 'absen' yang dapat berarti hadir atau tidak hadir.
- Tanggal event: 'Minggu, 18 Oktober 2026'; waktu: '06.00 WIB' jika zona Asia/Jakarta. Zona lain memakai label yang benar dari timezone event.
- Nilai API UTC tidak ditampilkan mentah sebagai waktu lokal tanpa konversi.
- Contoh kuota: '80 dari 100 hadir', 'Tersisa 20 tempat'. Gunakan tabular numbers.
- Alasan admin tidak otomatis dibuka kepada semua member; tampilkan informasi yang diizinkan policy.
- Error menyebut tindakan pemulihan, tanpa SQL, token, stack trace, atau nama layanan internal.

| Konteks | Copy acuan |
| --- | --- |
| CTA registrasi | Daftar Member |
| Setelah registrasi | Periksa email Anda untuk memverifikasi akun. |
| Resend diterima | Permintaan pengiriman ulang diproses. Periksa kotak masuk dan folder spam. |
| Kartu aktif | Tunjukkan QR ini kepada petugas saat mengikuti kegiatan. |
| Preview scan | Cocokkan nama dan foto dengan anggota yang hadir. |
| Sukses commit | Check-in berhasil. |
| Scan ganda | Anggota sudah check-in pada 06.12 WIB. |
| Kuota penuh | Kuota event penuh. Kehadiran baru belum dapat dicatat. |
| QR revoked | QR tidak berlaku. Gunakan QR terbaru atau hubungi pengurus. |
| Suspended | Keanggotaan Anda sedang ditangguhkan. Hubungi pengurus untuk bantuan. |

### 15.2 Ikon

Gunakan lucide-react dengan gaya outline konsisten. Ukuran umum 20 px, navigasi 22–24 px, status besar 28–32 px, stroke sekitar 2. Ikon tombol diletakkan kiri dengan gap 8 px; tombol kembali memakai ikon arah dan label yang jelas.

Contoh: QrCode untuk kartu, ScanLine untuk scanner, CalendarDays untuk event, Users untuk anggota, CircleCheck untuk success, CircleAlert untuk warning, CircleX untuk error. Nama ekspor final mengikuti paket yang dipasang. Ikon dekoratif aria-hidden; ikon fungsional tanpa teks wajib mempunyai accessible name.

### 15.3 Motion

Transisi hover/focus sekitar 120–160 ms; dialog/sheet sekitar 180–220 ms dengan easing yang tenang. Hindari parallax, confetti, animasi kartu berputar, dan flashing pada scanner. Respect prefers-reduced-motion; animasi dapat dihilangkan tanpa kehilangan informasi. Skeleton boleh statis ketika reduced motion aktif.

## 16. Aksesibilitas dan penggunaan luar ruangan

Target produk adalah WCAG 2.2 level AA pada alur inti, dengan target sentuh produk minimal 44 × 44 CSS px. Angka 44 px adalah keputusan kenyamanan produk; jangan menyebutnya sebagai minimum universal WCAG 2.2 AA yang mempunyai ketentuan 24 px beserta pengecualiannya.

- Kontras normal text ≥ 4.5:1; kontrol penting dan focus indicator yang disyaratkan ≥ 3:1 terhadap warna relevan.
- Semua kontrol mempunyai nama/label yang dapat diakses, urutan tab masuk akal, dan fokus terlihat.
- Dialog/sheet mengelola focus trap, Escape, judul, deskripsi, dan pengembalian fokus.
- Error field terhubung melalui aria-describedby; status invalid diekspos; required tidak hanya tanda warna.
- Status tidak hanya hijau/merah; sertakan teks dan ikon.
- Zoom teks 200% tetap dapat digunakan; uji reflow pada 320 CSS px dengan pengecualian region tabel dua dimensi yang beralasan.
- Sticky navigation/action tidak menutupi elemen yang mendapat fokus atau pesan penting.
- Uji layar HP di luar ruangan dengan nama panjang dan jaringan lambat; hindari teks abu-abu tipis di atas video/foto.
- Default menggunakan light theme. Kartu Deep Brown dan viewport kamera gelap bukan dark mode seluruh aplikasi. Dark mode baru ditambahkan setelah seluruh pasangan warna/state diaudit.

Kelulusan dihitung pada implementasi nyata, bukan dari pilihan shadcn/ui atau token saja. Kamera memerlukan alternatif pencarian manual yang sesuai izin; alternatif ini tetap memakai koneksi dan validasi backend.

## 17. Token implementasi dan integrasi frontend

Gunakan semantic CSS variables pada frontend/src/app/globals.css dan integrasikan ke theme Tailwind sesuai versi yang dipasang. Komponen menggunakan semantic token, bukan HEX hardcoded per halaman. Contoh berikut adalah acuan nilai, bukan klaim kode sudah terpasang.

```css
:root {
  --background: #fff9f0;
  --foreground: #2b2118;
  --card: #ffffff;
  --card-foreground: #2b2118;
  --popover: #ffffff;
  --popover-foreground: #2b2118;
  --primary: #ea6113;
  --primary-foreground: #2b2118;
  --primary-hover: #f88f22;
  --secondary: #ffe3b3;
  --secondary-foreground: #2b2118;
  --muted: #e8ded3;
  --muted-foreground: #6b5b4d;
  --accent: #ffe3b3;
  --accent-foreground: #2b2118;
  --link: #b9470b;
  --link-hover: #2b2118;
  --destructive: #b91c1c;
  --destructive-foreground: #ffffff;
  --border: #e8ded3;
  --input: #8a7868;
  --ring: #b9470b;
  --ring-on-dark: #fbb931;
  --overlay: rgba(43, 33, 24, 0.56);
  --brand-dark: #2b2118;
  --brand-light: #fff9f0;
  --brand-warm: #f88f22;
  --brand-gold: #fbb931;
  --brand-cream: #ffe3b3;
  --brand-deep: #b9470b;
  --success: #166534;
  --success-surface: #f0fdf4;
  --warning: #92400e;
  --warning-surface: #fffbeb;
  --info: #1d4ed8;
  --info-surface: #eff6ff;
  --danger-surface: #fef2f2;
  --qr-foreground: #000000;
  --qr-background: #ffffff;
  --radius: 0.75rem;
  --radius-card: 1.25rem;
  --radius-member-card: 1.5rem;
  --font-sans: var(--font-plus-jakarta, ui-sans-serif), system-ui, sans-serif;
}
```

Variabel --font-plus-jakarta diisi oleh next/font pada layout. Apabila font gagal, fallback tetap berlaku. Semantic color HEX di atas jangan dibungkus dengan hsl(...) yang mengharapkan tuple HSL; sesuaikan pemetaan theme dengan format nilai dan versi Tailwind/shadcn saat scaffold.

| Lokasi | Tanggung jawab |
| --- | --- |
| src/app/globals.css | Token dasar, typography default, focus, reduced motion |
| src/app/layout.tsx | Font dan struktur dokumen bahasa Indonesia |
| src/components/ui/ | Primitive dengan varian sesuai token dan ukuran 44 px |
| src/components/shared/ | PageHeader, EmptyState, ErrorState, komponen generik lintas fitur |
| src/features/membership/ | MemberCard dan status membership |
| src/features/attendance/ | ScannerViewport, preview, hasil, manual, koreksi |
| src/features/events/ | EventCard, kuota, konteks event |

Definisikan pemetaan status ke label/warna pada utilitas bertipe per domain; jangan membuat warna dari string status bebas. Protected UI tetap mengikuti API. Hapus data privat dari cache saat logout/pergantian akun; tidak membuat screenshot QR tersimpan pada analytics atau service-worker cache publik.

## 18. Komposisi layar acuan

Tabel berikut menetapkan susunan visual, bukan pixel-perfect mockup. Seluruh contoh memakai data fiktif dan tidak menunjukkan credential nyata.

| Layar | Bagian atas | Fokus utama | Bagian pendukung | Aksi utama |
| --- | --- | --- | --- | --- |
| Registrasi mobile | Wordmark dan ajakan bergabung singkat | Form berlabel | Kebijakan, link login | Daftar Member |
| Beranda member | Sapaan dan status | Akses kartu anggota | Event terdekat, riwayat | Lihat Kartu |
| Kartu anggota | Judul halaman | Card Deep Brown dengan panel QR putih | Identitas, helper penggunaan | Lihat QR Lebih Besar |
| Detail event | Nama, status, jadwal | Lokasi dan informasi kegiatan | Hadir/kuota, syarat operasional | Lihat Kartu; tanpa reservasi |
| Scanner siap | Header event dan kuota | Video kamera | Panduan, akses manual | Scan melalui kamera aktif |
| Preview scanner | Konteks event tetap terlihat | Foto/inisial, nama, nomor | Status dan instruksi identitas | Konfirmasi Hadir |
| Hasil scanner | Konteks event | Judul hasil 24–28 px, ikon dan waktu | Jumlah server terkini | Scan Berikutnya |
| Admin anggota | Judul dan filter | Tabel/card list anggota | Pagination/status | Tindakan per anggota |
| Admin event | Judul, status, jadwal | Detail, kuota, penugasan | Tabs Kehadiran dan informasi | Sesuai state event |
| Koreksi kehadiran | Dialog dengan konteks | Identitas dan alasan | Dampak/capacity error | Batalkan/Pulihkan Kehadiran |

Desktop dapat melebarkan grid tetapi mempertahankan urutan prioritas yang sama. Jangan menambahkan dashboard grafik dekoratif atau statistik jarak yang belum didukung data.

## 19. Pemeriksaan sebelum implementasi disetujui

| ID | Kriteria pemeriksaan visual/UX |
| --- | --- |
| VD-01 | Semua halaman memakai token warna, spacing, radius, dan font yang konsisten |
| VD-02 | Kontras teks/kontrol/fokus diperiksa pada state default, hover, error, selected, dan modal |
| VD-03 | Body tidak overflow pada 320 px; zoom 200% dan nama panjang tetap dapat digunakan |
| VD-04 | Form wajib sesuai PRD; foto opsional; validasi tidak melarang anggota anak |
| VD-05 | Event berkuota tidak mempunyai CTA reservasi, pembayaran, atau pendaftaran ulang |
| VD-06 | Preview QR tidak memakai pesan sukses hadir; konfirmasi petugas wajib |
| VD-07 | Sukses dan jumlah hadir berubah hanya setelah backend memastikan data tersimpan |
| VD-08 | Timeout, duplicate, revoked, suspended, full, dan closed memiliki pesan/tindakan yang berbeda |
| VD-09 | QR hitam-putih mempunyai quiet zone yang utuh dan dapat dipindai pada perangkat nyata |
| VD-10 | Petugas tidak melihat PII yang tidak diperlukan; role tidak membuka menu di luar tugas |
| VD-11 | Keyboard, pembaca layar, label, live region, dialog, dan return focus diuji |
| VD-12 | Mobile navigation, safe area, sticky CTA, dan keyboard virtual tidak menutupi field/fokus |
| VD-13 | Manual check-in tidak mengesankan bypass status atau kuota |
| VD-14 | Koreksi/status/rotasi QR mempunyai alasan, konfirmasi, loading, dan error yang jelas |
| VD-15 | Foto/font gagal dimuat tetap menghasilkan antarmuka yang dapat dipakai |
| VD-16 | Dark mode tidak diaktifkan parsial tanpa audit pasangan warna/state |

Pengujian token dilakukan pada dokumen ini untuk pasangan warna yang dicantumkan. Mockup layar, uji pembaca layar, pemindaian QR, keterbacaan luar ruangan, dan uji browser tetap harus dilakukan pada implementasi; belum dinyatakan lulus oleh dokumen ini.

## 20. Referensi dan pengelolaan perubahan

Referensi primer:

- [WCAG 2.2 — Contrast Minimum](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html).
- [WCAG 2.2 — Non-text Contrast](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html).
- [WCAG 2.2 — Target Size Minimum](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html).
- [WCAG 2.2 — Reflow](https://www.w3.org/WAI/WCAG22/Understanding/reflow.html).
- [WCAG 2.2 — Focus Not Obscured](https://www.w3.org/WAI/WCAG22/Understanding/focus-not-obscured-minimum.html).
- [Plus Jakarta Sans — Tokotype](https://github.com/tokotype/PlusJakartaSans).
- [Next.js — Font Optimization](https://nextjs.org/docs/app/getting-started/fonts).
- [shadcn/ui — Theming](https://ui.shadcn.com/docs/theming).
- [DENSO WAVE — Area dan quiet zone QR](https://www.qrcode.com/en/howto/code.html).

Palet, ukuran, komposisi, dan copy merupakan keputusan desain sorerunapp. Perubahan token harus diperiksa pada semua komponen dan status terdampak, termasuk kartu/scanner. Perubahan visual tidak boleh diam-diam mengubah aturan bisnis; penambahan RSVP, pembayaran, data wajib, atau hak akses harus melalui revisi PRD/arsitektur yang relevan.

### 20.1 Riwayat revisi palet

| Versi | Tanggal | Perubahan |
| --- | --- | --- |
| 1.1 | 21 Oktober 2026 | Palet Sunset dari gambar referensi pertama disetujui pemilik produk: #EA6113, #F88F22, #FBB931, #FFE3B3; dilengkapi netral cokelat gelap/terang, tautan Deep Orange, pemetaan token, dan perhitungan kontras baru. |

Revisi 1.1 tetap berstatus final dan menggantikan palet merek sebelumnya. Status success/warning/danger/info serta QR hitam-putih tetap mengikuti aturan yang sama. Perubahan ini memperbarui dokumen terlebih dahulu; penerapan token pada kode aplikasi dan sinkronisasi acuan warna yang disalin ke dokumen lain dilakukan pada tahap implementasi berikutnya. Ukuran, tipografi, proses bisnis, kontrak API, dan aturan akses tidak berubah.
