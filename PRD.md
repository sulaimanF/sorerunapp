# PRD — Aplikasi Membership Komunitas Running

| Atribut | Nilai |
| --- | --- |
| Versi | 1.0 |
| Tanggal | 13 Oktober 2026 |
| Status | Final — disetujui pemilik produk sebagai acuan pengembangan sorerunapp |
| Nama produk | sorerunapp — Sore Running Club (SRC) |
| Bahasa aplikasi | Bahasa Indonesia |
| Sasaran rilis awal | Web responsif untuk member, admin, dan petugas event |

## 1. Ringkasan produk

Aplikasi menyediakan pendataan anggota komunitas running, aktivasi keanggotaan melalui verifikasi email, kartu anggota dengan QR code, serta pencatatan kehadiran pada event internal.

Pengguna mendaftar sebagai anggota satu kali dan mengisi profil. Setelah memverifikasi email dan memenuhi data wajib, pengguna memperoleh nomor member serta kartu QR. Ketika mengikuti event rutin, anggota cukup menunjukkan QR kepada petugas. Petugas memilih event dan melakukan scan untuk mencatat kehadiran tanpa meminta anggota mengisi data diri kembali.

Akun, keanggotaan, credential QR, dan kehadiran merupakan objek yang berbeda. Pemisahan ini memungkinkan penangguhan keanggotaan atau penggantian QR tanpa menghilangkan akun dan riwayat kegiatan.

## 2. Latar belakang dan masalah yang diselesaikan

Permasalahan berikut merupakan hipotesis kebutuhan berdasarkan alur yang diminta, bukan hasil observasi operasional yang sudah diverifikasi.

| ID | Masalah | Dampak | Solusi produk |
| --- | --- | --- | --- |
| P-01 | Data anggota tersebar di formulir, spreadsheet, atau percakapan | Pengurus sulit mengetahui data dan status anggota terbaru | Basis data anggota terpusat |
| P-02 | Anggota mengisi data yang sama pada kegiatan berulang | Menambah waktu dan menghasilkan variasi atau duplikasi data | Registrasi anggota satu kali dan profil yang dapat diperbarui |
| P-03 | Pencatatan kehadiran manual memerlukan pencarian nama | Antrean check-in dan kesalahan pencatatan | Scan kartu QR pada event yang dipilih |
| P-04 | Identitas keanggotaan dan status aktif tidak jelas | Petugas sulit memeriksa kelayakan peserta | Nomor member, kartu anggota, dan validasi status oleh server |
| P-05 | Riwayat partisipasi sulit ditelusuri | Pengurus sulit mengevaluasi kegiatan dan member sulit melihat aktivitasnya | Riwayat kehadiran serta laporan event |
| P-06 | Scan atau input ulang dapat mencatat anggota lebih dari sekali | Jumlah peserta tidak akurat | Satu catatan kehadiran per anggota per event dengan constraint database |

## 3. Tujuan dan ukuran keberhasilan

### 3.1 Tujuan bisnis

1. Menyediakan satu sumber data anggota yang dapat dikelola pengurus.
2. Menghilangkan pengisian ulang data diri untuk event internal rutin.
3. Mempercepat check-in dan mengurangi pencatatan ganda.
4. Memberikan identitas keanggotaan yang dapat diperiksa petugas.
5. Menyediakan riwayat kegiatan yang dapat dipertanggungjawabkan.
6. Membatasi akses data pribadi sesuai tugas pengguna.

### 3.2 Ukuran keberhasilan

Target numerik berikut adalah usulan untuk pilot, bukan hasil pengujian atau komitmen kapasitas produksi.

| Indikator | Definisi | Target awal |
| --- | --- | --- |
| Pengisian ulang data saat event rutin | Jumlah form data diri yang harus diisi member terdaftar untuk check-in | 0 |
| Kehadiran ganda | Dua catatan efektif untuk anggota dan event yang sama | 0 |
| Validasi keanggotaan | Check-in yang memeriksa status terkini di backend | 100% |
| Koreksi terlacak | Koreksi kehadiran yang menyimpan pelaku, waktu, alasan, dan perubahan | 100% |
| Respons check-in | Waktu kirim request sampai respons backend, tidak termasuk waktu kamera membaca QR | p95 ≤ 2 detik pada kondisi uji yang disepakati |
| Keberhasilan registrasi | Registrasi valid yang tersimpan tanpa akun atau member ganda | 100% pada skenario penerimaan |
| Aktivasi pengguna | Proporsi pendaftar valid yang memverifikasi email dalam 24 jam | Diukur saat pilot; target ditentukan setelah baseline |

Event tanpa RSVP tidak memiliki daftar peserta yang berjanji hadir. Karena itu, anggota yang tidak datang tidak otomatis dikategorikan absen atau no-show.

## 4. Keputusan, asumsi, dan batasan awal

### 4.1 Kebutuhan yang sudah dinyatakan pemilik produk

- Aplikasi digunakan untuk pendataan anggota komunitas running.
- Pengguna melakukan registrasi dan mengisi data diri, termasuk nama dan tanggal lahir.
- Registrasi disertai email verifikasi.
- Anggota memperoleh QR sebagai tanda keanggotaan.
- QR dapat digunakan berulang pada event internal tanpa pendaftaran anggota ulang.
- Database menggunakan MySQL.

### 4.2 Keputusan bisnis untuk MVP

Ketentuan berikut sudah ditetapkan oleh pemilik produk dan menjadi acuan implementasi MVP. ID A-01 sampai A-11 dipertahankan untuk keterlacakan, tetapi tidak lagi menunjukkan asumsi. Keputusan pada bagian ini dan Bagian 19 berlaku sebagai acuan terbaru apabila masih terdapat rumusan sebelumnya pada bagian lain.

| ID | Keputusan yang ditetapkan |
| --- | --- |
| A-01 | Satu aplikasi melayani satu komunitas |
| A-02 | Keanggotaan gratis dan tidak mempunyai tanggal kedaluwarsa otomatis |
| A-03 | Keanggotaan diaktifkan otomatis setelah email terverifikasi dan data wajib lengkap |
| A-04 | Tidak ada persetujuan admin untuk registrasi pada MVP |
| A-05 | Event MVP bersifat internal, gratis, dengan kuota peserta yang ditentukan admin, tanpa RSVP |
| A-06 | QR tetap dapat digunakan pada banyak event; QR dapat dicabut dan diganti |
| A-07 | Check-in dilakukan petugas yang login; tidak tersedia self check-in |
| A-08 | Check-in MVP memerlukan koneksi internet |
| A-09 | Login menggunakan email dan password |
| A-10 | Zona waktu awal event adalah Asia/Jakarta; waktu sistem disimpan dalam UTC |
| A-11 | Anggota di bawah umur diperbolehkan; tidak ada batas usia minimum otomatis pada registrasi MVP |

**Ketentuan kuota event (A-05):**

- Admin wajib menentukan kuota berupa bilangan bulat positif sebelum memublikasikan event.
- Kuota merupakan batas jumlah kehadiran valid, bukan jumlah seluruh member atau reservasi peserta. Anggota diterima berdasarkan urutan konfirmasi check-in yang berhasil disimpan oleh backend.
- Check-in QR maupun manual mengonsumsi kuota yang sama. Backend memeriksa kapasitas dan menyimpan kehadiran dalam satu transaksi dengan penguncian yang sesuai agar request bersamaan tidak melampaui kuota.
- Saat kuota penuh, check-in anggota baru ditolak dengan informasi "Kuota event penuh". Scan ulang terhadap kehadiran valid tetap menampilkan "Sudah check-in" dan tidak mengonsumsi kuota tambahan.
- Pembatalan kehadiran melalui koreksi admin membebaskan satu tempat. Pemulihan kehadiran voided dan penambahan retrospektif wajib memeriksa kapasitas secara atomik; ditolak jika kuota penuh.
- Admin dapat menambah kuota. Penurunan kuota tidak boleh lebih kecil dari jumlah kehadiran valid yang sudah tercatat. Perubahan kuota dicatat dalam audit dan diselaraskan secara transaksional dengan check-in yang sedang berlangsung.
- Tidak tersedia reservasi melalui RSVP pada tahap ini; kepemilikan membership tidak menjamin tempat apabila kuota sudah penuh.

### 4.3 Batasan makna keanggotaan dan QR

- Email terverifikasi menunjukkan penguasaan alamat email, bukan validasi identitas hukum atau kebenaran semua profil.
- Satu email digunakan untuk satu akun; aturan ini tidak menjamin satu orang tidak membuat akun lain dengan email berbeda.
- QR adalah credential keanggotaan, bukan token login, tiket berbayar, atau bukti identitas mutlak.
- QR tetap dapat disalin. Pemeriksaan visual nama/foto oleh petugas membantu mengurangi titip absen, tetapi tidak menjamin eliminasi penyalahgunaan.
- Check-in membuktikan pencatatan hadir; tidak membuktikan peserta menyelesaikan rute, jarak, atau latihan.

## 5. Pengguna dan hak akses

| Aktor | Kebutuhan utama | Akses |
| --- | --- | --- |
| Pengunjung | Mengetahui cara bergabung | Halaman publik, registrasi, login |
| Pendaftar belum terverifikasi | Menyelesaikan aktivasi | Status registrasi, kirim ulang verifikasi, pengelolaan akun terbatas |
| Member | Menggunakan keanggotaan dan mengikuti kegiatan | Profil sendiri, kartu QR, event internal, riwayat sendiri |
| Petugas event | Mencatat peserta dengan cepat | Scanner, pencarian manual, dan daftar hadir pada event penugasan |
| Admin | Mengelola komunitas | Anggota, status keanggotaan, event, penugasan, laporan, koreksi, audit |

### 5.1 Matriks izin MVP

| Tindakan | Member | Petugas event | Admin |
| --- | --- | --- | --- |
| Mengubah profil sendiri | Ya | Ya, untuk profil sendiri | Ya |
| Melihat QR sendiri | Ya, jika memenuhi syarat | Ya, jika juga member | Ya, jika juga member |
| Melihat riwayat sendiri | Ya | Ya, jika juga member | Ya |
| Melihat data anggota lain | Tidak | Nama, nomor member, foto/status yang diperlukan untuk event tugas | Sesuai tugas administrasi |
| Membuat/mengubah event | Tidak | Tidak | Ya |
| Melakukan scan/manual check-in | Tidak | Event penugasan | Ya |
| Menangguhkan keanggotaan | Tidak | Tidak | Ya, dengan alasan |
| Mengganti QR | Tidak; meminta bantuan admin | Tidak | Ya, dengan alasan |
| Mengoreksi kehadiran | Tidak | Tidak; melaporkan ke admin | Ya, dengan alasan |
| Mengekspor data | Tidak | Tidak | Ya, dengan audit |
| Mengelola role/penugasan | Tidak | Tidak | Ya |

Role dapat digabung. Hak petugas hanya berlaku pada event yang ditugaskan. Status member tidak otomatis memberikan hak petugas. Pembatasan dilaksanakan pada backend untuk setiap request.

## 6. Ruang lingkup produk

### 6.1 Termasuk MVP

- Registrasi, login, logout, reset password, verifikasi email, dan kirim ulang verifikasi.
- Profil anggota, aktivasi otomatis, nomor member unik.
- Kartu QR, pencabutan dan penggantian QR oleh admin.
- Pengelolaan status akun dan keanggotaan.
- Daftar/detail event internal dan pengelolaan event oleh admin.
- Penugasan petugas, scanner, pemeriksaan identitas, check-in online.
- Pencarian anggota dan check-in manual sebagai alternatif scanner.
- Pencegahan kehadiran ganda, koreksi kehadiran dengan audit.
- Riwayat pribadi, daftar hadir, ringkasan dan ekspor CSV terbatas.
- Logging administratif, pemantauan kegagalan email, backup dan pengujian pemulihan.

## 7. Data registrasi dan profil

| Field | Ketentuan MVP | Validasi/tujuan |
| --- | --- | --- |
| Email | Wajib | Format valid; spasi luar dihapus; pencocokan case-insensitive sesuai kebijakan akun; unik |
| Password dan konfirmasi | Wajib saat registrasi | Kebijakan awal minimum 12 karakter; konfirmasi sama; tidak disimpan sebagai teks biasa |
| Nama lengkap | Wajib | 2–150 karakter; mendukung nama Unicode; tidak dieksekusi sebagai HTML |
| Tanggal lahir | Wajib | Tanggal kalender valid; tidak di masa depan; tidak menetapkan batas usia yang belum disepakati |
| Nomor telepon | Wajib menurut asumsi operasional | Format nomor dinormalisasi; bukan identitas unik atau bukti kepemilikan |
| Kota/domisili | Wajib menurut asumsi operasional | Kota cukup; alamat lengkap tidak diminta |
| Foto profil | Opsional | JPEG/PNG/WebP, maksimum 2 MB; validasi file di server |
| Jenis kelamin | Opsional | Ada pilihan tidak mengisi |
| Pengalaman/pace running | Ditunda atau opsional | Tidak menjadi syarat aktivasi |
| Pemberitahuan privasi dan ketentuan | Wajib diakui sebelum registrasi | Simpan versi dokumen dan waktu pengakuan |
| Persetujuan promosi | Tidak diwajibkan | Jika ditambahkan, dipisahkan dari syarat pendaftaran |

Kontak darurat belum termasuk data wajib MVP. NIK, foto KTP, alamat rinci, dan data kesehatan tidak dikumpulkan tanpa revisi kebutuhan yang jelas. Pilihan data wajib telepon/domisili dan kebijakan usia masih perlu ditinjau.

## 8. Model status dan aturan bisnis

### 8.1 Status objek

| Objek | Status/atribut | Makna |
| --- | --- | --- |
| Akun | enabled / blocked | Apakah akun boleh mengakses fungsi terautentikasi |
| Email | email_verified_at kosong / terisi | Apakah alamat email aktif sudah diverifikasi |
| Profil | complete / incomplete, dihitung dari data wajib | Syarat penerbitan keanggotaan |
| Keanggotaan | pending_verification / active / suspended / ended | Menunggu aktivasi, dapat digunakan, ditangguhkan, atau sudah diakhiri |
| Credential QR | active / revoked | Dapat digunakan atau sudah dicabut |
| Event | draft / published / cancelled / completed | Tahap pengelolaan event |
| Kehadiran | valid / voided | Kehadiran sah atau dibatalkan melalui koreksi |

Waktu buka/tutup check-in disimpan terpisah dari status event. Event published belum tentu sedang membuka check-in.

### 8.2 Transisi yang diizinkan

- Registrasi membuat akun enabled, email belum terverifikasi, dan membership pending_verification.
- pending_verification menjadi active setelah verifikasi email berhasil dan profil lengkap.
- active menjadi suspended atau ended melalui admin dengan alasan.
- suspended dapat kembali active melalui admin setelah syarat diperiksa.
- ended tidak dapat diaktifkan melalui alur biasa MVP; kasus bergabung kembali memerlukan keputusan produk.
- Pemblokiran akun tidak menghapus membership atau riwayat, tetapi menolak login dan check-in.
- Pemulihan password tidak mengaktifkan akun blocked atau membership suspended.
- Event draft dapat diterbitkan atau dibatalkan. Event published dapat dibatalkan atau diselesaikan. cancelled/completed menutup check-in.
- Kehadiran valid dapat dijadikan voided oleh admin. Pemulihan menggunakan catatan yang sama dengan audit, bukan baris duplikat.

### 8.3 Aturan bisnis utama

| ID | Aturan |
| --- | --- |
| BR-01 | Satu akun memiliki paling banyak satu membership pada komunitas MVP |
| BR-02 | Nomor member diterbitkan saat aktivasi pertama, unik, stabil, dan tidak digunakan ulang |
| BR-03 | Kartu QR diterbitkan setelah membership active dan email terverifikasi |
| BR-04 | Maksimum satu credential QR berstatus active untuk satu membership |
| BR-05 | QR tidak mengandung data pribadi, ID berurutan yang mudah ditebak, atau token login |
| BR-06 | Penggantian QR mencabut credential sebelumnya tanpa mengganti nomor member |
| BR-07 | Check-in mensyaratkan akun enabled, email terverifikasi, profil lengkap, membership active, dan credential active jika metode QR |
| BR-08 | Event harus published dan waktu server memenuhi opened_at ≤ sekarang < closed_at |
| BR-09 | Admin atau petugas yang ditugaskan pada event dapat melakukan check-in; hak diperiksa backend |
| BR-10 | Satu membership mempunyai paling banyak satu baris kehadiran per event |
| BR-11 | Scan ulang terhadap kehadiran valid menghasilkan informasi sudah hadir, bukan penambahan kehadiran |
| BR-12 | Scan ulang terhadap kehadiran voided ditolak untuk pemulihan otomatis; petugas menghubungi admin |
| BR-13 | Manual check-in mengikuti syarat akun, membership, event, dan izin yang sama; perbedaan hanya cara identifikasi |
| BR-14 | Tidak ada override petugas untuk membership suspended, akun blocked, atau event ditutup |
| BR-15 | Penangguhan/pengakhiran membership tidak menghapus kehadiran sah sebelum perubahan |
| BR-16 | Perubahan email menggunakan alamat pending; alamat lama tetap aktif sampai alamat baru diverifikasi |
| BR-17 | Setelah penggantian email berhasil, email aktif baru harus berstatus terverifikasi; QR tidak berubah karena nomor member tetap |
| BR-18 | Data profil wajib tidak boleh dikosongkan melalui update normal |
| BR-19 | Waktu hadir berasal dari server; client tidak boleh menentukan waktu hadir bebas |
| BR-20 | Koreksi kehadiran dan perubahan administratif penting memerlukan pelaku, waktu, alasan, serta nilai sebelum/sesudah |

Contoh format nomor member adalah RUN-000125; prefix final belum ditentukan. Jangan memasukkan tahun apabila tidak ada kebijakan reset nomor tahunan.

## 9. Proses bisnis end-to-end

### 9.1 BP-01 — Registrasi

**Pemicu:** pengunjung memilih Daftar Member.

1. Sistem menampilkan data wajib, ketentuan, dan pemberitahuan privasi.
2. Pengguna mengisi data dan mengirim formulir.
3. Backend memvalidasi field, aturan email unik, dan pembatasan request.
4. Dalam transaksi, backend membuat akun, profil, dan membership pending_verification.
5. Setelah commit, sistem mengantrekan email verifikasi.
6. UI menampilkan instruksi memeriksa email, mengirim ulang, atau kembali login.

**Hasil:** satu akun tersimpan; kartu aktif belum tersedia.

**Pengecualian:** input invalid ditolak tanpa data parsial. Request ulang tidak membuat akun kedua. Email gagal dikirim tidak membatalkan akun; dapat diulang melalui pengiriman ulang. Respons email sudah terdaftar tidak mengungkap status/detail akun kepada pihak yang tidak terautentikasi.

### 9.2 BP-02 — Verifikasi dan aktivasi

**Pemicu:** pengguna membuka tautan verifikasi email.

1. Backend memeriksa signature/token, masa berlaku, dan kecocokan akun/email tujuan.
2. Jika mekanisme membutuhkan sesi, pengguna login terlebih dahulu lalu kembali ke proses verifikasi.
3. Sistem menandai email terverifikasi.
4. Sistem memeriksa kelengkapan profil dan status akun.
5. Dalam transaksi, membership pending_verification diaktifkan, nomor member unik dialokasikan, dan credential QR dibuat.
6. Setelah commit, sistem mengirim pemberitahuan aktivasi.
7. Member dapat membuka kartu setelah login.

**Hasil:** satu membership active, satu nomor member, satu QR aktif.

**Pengecualian:** tautan expired menawarkan kirim ulang. Tautan invalid tidak mengubah data. Klik ulang bersifat idempotent dan tidak menerbitkan nomor/QR kedua. Tautan lama untuk alamat yang sudah berubah tidak memverifikasi alamat baru. Akun blocked tidak diaktifkan oleh tautan.

**Asumsi parameter:** masa berlaku tautan 60 menit, cooldown kirim ulang 60 detik; pembatasan tambahan per akun/IP ditetapkan saat implementasi.

### 9.3 BP-03 — Penggunaan kartu member

1. Member login dan membuka Kartu Anggota.
2. Sistem menampilkan nama, nomor member, foto jika ada, tanggal bergabung, status, dan QR jika dapat digunakan.
3. Member menunjukkan kartu dari layar atau salinan cetak yang diperbolehkan pada MVP.
4. Status pada kartu merupakan informasi tampilan; backend tetap memeriksa status terkini saat scan.

Membership suspended/ended tidak menampilkan kartu sebagai aktif. Salinan QR lama tidak bisa mengatasi penangguhan backend.

### 9.4 BP-04 — Pembuatan dan publikasi event

1. Admin membuat draft: nama, deskripsi, lokasi/titik kumpul, jadwal, zona waktu, serta waktu buka/tutup check-in.
2. Backend memvalidasi urutan waktu dan field wajib. Penutupan check-in tidak boleh melewati akhir event menurut kebijakan MVP.
3. Admin menugaskan petugas.
4. Admin memublikasikan event; event terlihat oleh member terautentikasi.
5. Admin dapat memperbarui informasi event dengan audit.
6. Admin memastikan petugas dan koneksi siap sebelum kegiatan.

Event tidak boleh dipublikasikan tanpa waktu check-in valid dan minimal satu operator yang dapat menangani event; admin dapat menjadi operator.

Perubahan event setelah kehadiran tercatat tidak memindahkan atau menghapus kehadiran. Penyempitan jendela waktu tidak membatalkan catatan sebelumnya. Pembatalan event menghentikan check-in baru; catatan lama tetap tersedia dengan konteks bahwa event dibatalkan.

### 9.5 BP-05 — Check-in QR

1. Petugas login, memilih event penugasan, dan melihat nama/tanggal/lokasi serta keadaan check-in.
2. Petugas mengizinkan akses kamera dan memindai QR.
3. Aplikasi mengirim credential dan konteks event kepada backend untuk validasi awal.
4. Sistem menampilkan informasi minimum anggota untuk dicocokkan dengan orang yang hadir.
5. Petugas memilih Konfirmasi Hadir. Jika identitas tidak cocok, petugas membatalkan tanpa membuat kehadiran.
6. Backend memvalidasi ulang seluruh syarat agar perubahan setelah preview tetap diperiksa.
7. Backend menyimpan kehadiran secara atomik; waktu berasal dari server.
8. UI menampilkan berhasil hanya setelah penyimpanan terkonfirmasi.

**Data hasil:** event, membership, waktu hadir, petugas, metode qr, dan status valid.

**Respons utama:** berhasil; sudah hadir beserta waktu sebelumnya; QR tidak berlaku; membership tidak aktif; akun tidak memenuhi syarat; event belum dibuka/ditutup; tidak berwenang; atau koneksi gagal dan status belum terkonfirmasi.

Pemindaian saja tidak otomatis menyimpan sebelum petugas mengonfirmasi pemeriksaan identitas. Dua petugas yang mengonfirmasi bersamaan tetap menghasilkan satu kehadiran.

### 9.6 BP-06 — Check-in manual

**Pemicu:** QR tidak tersedia, kamera bermasalah, atau anggota lupa kartu.

1. Petugas mencari nomor member atau nama pada halaman event tugas.
2. Sistem membatasi hasil dan hanya menampilkan data minimum untuk identifikasi.
3. Petugas mencocokkan identitas dan memilih alasan check-in manual.
4. Backend memeriksa aturan yang sama dengan check-in QR, tanpa membutuhkan credential QR aktif.
5. Sistem menyimpan metode manual, alasan, pelaku, dan waktu.

Check-in manual tetap memerlukan koneksi. Petugas tidak boleh menggunakan metode ini untuk melewati status akun atau membership.

### 9.7 BP-07 — Retry, scan ganda, dan kegagalan koneksi

- Jika server telah menyimpan data tetapi respons terputus, retry mengembalikan kehadiran yang sudah ada.
- Constraint unik pada event dan membership melindungi dari request paralel, baik QR maupun manual.
- UI menahan pengiriman berulang selama request berjalan, tetapi perlindungan utama tetap di backend/database.
- Tanpa respons server, UI tidak boleh menambah jumlah hadir final atau menampilkan sukses.
- MVP tidak menyimpan antrean kehadiran offline. Fallback lapangan berupa hotspot atau catatan sementara yang direkonsiliasi admin dengan audit setelah koneksi pulih.
- Jika admin memasukkan kehadiran retrospektif, simpan waktu hadir yang dilaporkan terpisah dari waktu pencatatan server, alasan, dan sumber catatan. Fitur ini hanya dipakai untuk rekonsiliasi yang disetujui admin, bukan override scanner.

### 9.8 BP-08 — Penggantian QR

1. Member melaporkan QR tersebar atau membutuhkan penggantian.
2. Admin memeriksa pemilik melalui prosedur dukungan, lalu mengisi alasan.
3. Dalam satu transaksi, backend mencabut credential lama dan menerbitkan credential baru.
4. Member melihat kartu baru; QR lama ditolak pada scan berikutnya.
5. Audit dan pemberitahuan mencatat perubahan.

Nomor member serta riwayat kehadiran tetap sama. Permintaan paralel tidak boleh menghasilkan dua QR aktif.

### 9.9 BP-09 — Penangguhan dan pemulihan

1. Admin memilih anggota dan alasan penangguhan/pengakhiran.
2. Sistem menyimpan perubahan dan audit.
3. Check-in berikutnya ditolak segera setelah perubahan tersimpan.
4. Member masih dapat melihat informasi status dan riwayat jika akunnya tidak blocked.
5. Pemulihan suspended menjadi active memerlukan alasan dan pemeriksaan syarat.

Penangguhan tidak otomatis mencabut credential; QR yang sama dapat dipakai kembali setelah pemulihan. QR yang dicabut tetap tidak berlaku meskipun membership dipulihkan.

### 9.10 BP-10 — Koreksi dan penyelesaian event

1. Admin memeriksa daftar hadir dan laporan masalah petugas.
2. Koreksi dapat membatalkan atau memulihkan catatan; semuanya membutuhkan alasan.
3. Sistem menyimpan jejak perubahan; tidak melakukan hard delete kehadiran melalui UI.
4. Event diselesaikan dan check-in ditutup. Jendela waktu juga menutup check-in meskipun admin belum menandai completed.
5. Laporan menghitung kehadiran valid saja dan memberi konteks event cancelled jika relevan.

Koreksi setelah event selesai hanya dilakukan admin. Riwayat member mencerminkan hasil terkini; pembatalan tidak dihitung sebagai kehadiran sah.

## 10. Requirement fungsional dan kriteria penerimaan

Semua requirement P0 wajib untuk rilis MVP. P1 dapat ditambahkan setelah alur inti stabil. Kriteria pada tabel dilengkapi skenario lintas fitur pada Bagian 16.

| ID | Prioritas | Requirement | Kriteria penerimaan |
| --- | --- | --- | --- |
| FR-01 | P0 | Registrasi dan validasi profil | Input valid membuat satu akun/profil/membership pending; invalid tidak membuat data parsial |
| FR-02 | P0 | Verifikasi email dan resend | Tautan valid mengaktifkan satu kali; expired/invalid ditolak; resend mempunyai cooldown |
| FR-03 | P0 | Login/logout/reset password | Login valid membuka sesi; logout mengakhiri sesi; reset tidak mengubah status membership dan tidak membuka akun blocked |
| FR-04 | P0 | Profil dan perubahan email | Member hanya mengubah profil sendiri; email baru aktif setelah diverifikasi; data wajib tidak dapat dikosongkan |
| FR-05 | P0 | Nomor member dan QR | Nomor unik/stabil; hanya satu QR aktif; QR tidak mengandung data pribadi atau token login |
| FR-06 | P0 | Status akun/membership | Admin dapat melakukan perubahan yang diizinkan dengan alasan; backend menolak check-in yang tidak memenuhi status |
| FR-07 | P0 | Event dan publikasi | Validasi field/waktu; draft tidak terlihat oleh member; cancelled/completed tidak menerima check-in |
| FR-08 | P0 | Penugasan petugas | Petugas hanya melihat/menangani event penugasan; manipulasi event ID tetap ditolak |
| FR-09 | P0 | Scanner dan konfirmasi identitas | Preview tidak mencatat hadir; konfirmasi memvalidasi ulang; berhasil ditampilkan setelah commit |
| FR-10 | P0 | Check-in manual | Memerlukan pemeriksaan, alasan, dan izin; aturan kelayakan tetap berlaku |
| FR-11 | P0 | Idempotensi dan concurrency | Retry/request paralel QR/manual menghasilkan maksimal satu baris kehadiran |
| FR-12 | P0 | Riwayat dan daftar hadir | Member melihat miliknya; petugas melihat event tugas; catatan voided tidak masuk total hadir valid |
| FR-13 | P0 | Koreksi dan rekonsiliasi | Hanya admin; alasan wajib; nilai sebelum/sesudah dan waktu pencatatan dapat ditelusuri |
| FR-14 | P0 | Penggantian credential | QR lama ditolak; QR baru tersedia; nomor member/riwayat tetap; tidak ada dua QR aktif |
| FR-15 | P0 | Laporan dan ekspor CSV | Filter event/status/rentang tanggal; hak admin; total sesuai data valid; ekspor diaudit |
| FR-16 | P0 | Pengiriman dan monitoring email | Email dikirim setelah commit; kegagalan bisa dipantau dan diulang tanpa duplikasi keanggotaan |
| FR-17 | P0 | Audit dan kontrol data | Perubahan penting mencatat pelaku/waktu/alasan; member/petugas tidak dapat mengubah audit |
| FR-18 | P1 | Ringkasan partisipasi lanjutan | Pertumbuhan anggota dan frekuensi hadir mempunyai definisi/filter yang jelas |

## 11. Halaman dan pengalaman pengguna

| Area | Halaman MVP |
| --- | --- |
| Publik | Informasi komunitas, registrasi, login, lupa/reset password, hasil verifikasi |
| Member | Beranda/status aktivasi, profil, kartu QR, daftar/detail event, riwayat hadir, informasi status membership |
| Petugas | Daftar event tugas, scanner event, preview anggota, hasil check-in, pencarian manual, daftar hadir |
| Admin | Ringkasan, daftar/detail anggota, status/QR, daftar/form/detail event, penugasan, kehadiran/koreksi, laporan/ekspor, audit |

Ketentuan UX:

- Mobile-first untuk kartu dan scanner; dashboard admin tetap dapat digunakan di desktop.
- Nama dan tanggal event selalu terlihat pada scanner untuk mengurangi salah konteks.
- Status berhasil/gagal menggunakan teks dan ikon, tidak mengandalkan warna saja.
- Ada petunjuk izin kamera, pencahayaan, koneksi, dan alternatif manual.
- Preview menampilkan nama, nomor member, foto jika ada, serta status yang relevan; tidak menampilkan tanggal lahir lengkap atau telepon tanpa kebutuhan.
- Foto yang tidak tersedia ditandai jelas; petugas melakukan pemeriksaan manual yang proporsional.
- Loading, empty state, validation error, session expired, dan retry tersedia pada alur utama.
- Kartu serta endpoint yang memuat credential menggunakan kebijakan cache privat; aplikasi tidak menyimpan profil/QR anggota pada cache publik atau log analitik.

## 12. Model data konseptual dan integritas MySQL

Bagian ini merupakan acuan domain, bukan desain migration final.

| Entitas/tabel usulan | Informasi utama | Integritas penting |
| --- | --- | --- |
| users | Email aktif, pending email bila ada, password hash, verified_at, status akun | Email akun unik dengan normalisasi konsisten |
| member_profiles | user_id, nama, tanggal lahir, telepon, domisili, foto | user_id unik; satu profil per akun |
| memberships | user_id, nomor member nullable sebelum aktivasi, status, joined_at | user_id unik; nomor member unik setelah diterbitkan |
| member_credentials | membership_id, token_hash, token terenkripsi, issued_at, revoked_at | Hash unik; maksimum satu credential aktif per membership |
| events | Nama, deskripsi, lokasi, zona waktu, jadwal, check-in window, status | Urutan waktu valid |
| event_staff | event_id, user_id | Kombinasi event_id dan user_id unik |
| event_attendances | event_id, membership_id, checked_in_at, recorded_at, recorded_by, metode, status, alasan | UNIQUE(event_id, membership_id), termasuk catatan voided |
| roles dan relasi user-role | Role member/petugas/admin | Penambahan role hanya oleh admin berwenang |
| audit_logs | Pelaku, tindakan, objek, waktu, alasan, perubahan | Tidak dapat diubah melalui UI biasa; tanpa token/password |
| notification_logs | Jenis notifikasi, penerima terbatas, status, percobaan, waktu | Tidak menyimpan token lengkap/credential dalam log |
| policy_acknowledgements | user_id, jenis/versi dokumen, waktu | Menjaga jejak dokumen yang diakui pengguna |

Relasi inti: satu user mempunyai satu profil dan satu membership; membership memiliki riwayat credential dan banyak kehadiran; event memiliki banyak petugas dan kehadiran.

Ketentuan implementasi MySQL:

- Gunakan InnoDB, foreign key, transaksi, dan utf8mb4.
- Pastikan aturan collation email sesuai normalisasi akun; hash/token memakai representasi dan perbandingan yang tepat.
- Nomor member dibuat dengan mekanisme alokasi aman, bukan MAX(nomor) + 1 tanpa perlindungan.
- Aktivasi, penggantian QR, dan perubahan attendance menggunakan transaksi.
- Untuk maksimum satu QR aktif, implementasi memakai locking pada membership dan guard database yang sesuai kemampuan MySQL, misalnya generated column nullable dengan unique index. Desain final diuji terhadap concurrency; jangan mengasumsikan partial unique index PostgreSQL tersedia di MySQL.
- UNIQUE(event_id, membership_id) berlaku pada semua status attendance; pemulihan memperbarui baris yang sama.
- Hindari cascade delete yang menghilangkan sejarah hadir/audit secara tidak sengaja.
- Tambahkan indeks berdasarkan query daftar anggota, daftar event, kehadiran, dan rentang waktu.
- Simpan tanggal lahir sebagai DATE; timestamp sistem dalam UTC; tampilan mengikuti zona event.

## 13. Arah teknologi

Stack utama yang digunakan adalah Next.js 16.x, Laravel 13.x, PHP 8.4.x, Node.js 24.x LTS, dan MySQL 8.4.x LTS. Versi ini menjadi acuan environment pengembangan dan produksi. Library pendukung tetap mengikuti pilihan pada tabel; versi paketnya ditetapkan saat scaffold berdasarkan kompatibilitas stack utama.

| Lapisan | Teknologi | Versi/acuan | Tujuan |
| --- | --- | --- | --- |
| Frontend | Next.js + TypeScript | Next.js 16.x stabil; TypeScript 5.x, minimum 5.1 | Portal member/admin/petugas dalam web responsif |
| Runtime frontend | Node.js | 24.x LTS | Menjalankan tooling, build, dan server Next.js |
| UI/form | Tailwind CSS, shadcn/ui, React Hook Form, Zod | Versi kompatibel dipilih saat scaffold; komponen shadcn/ui menjadi bagian kode aplikasi | Form dan antarmuka konsisten |
| Data API | TanStack Query | Versi kompatibel dipilih saat scaffold | Pengambilan data, cache privat, mutasi, invalidasi |
| Backend | Laravel | 13.x stabil | Aturan bisnis, validasi, permission, email, transaksi |
| Runtime backend | PHP | 8.4.x | Menjalankan Laravel API, queue worker, dan scheduler |
| Authentication | Sanctum dengan session cookie untuk web milik sendiri | Versi paket kompatibel dengan Laravel 13.x | Sesi browser dan proteksi CSRF sesuai deployment |
| Database | MySQL | 8.4.x LTS | Penyimpanan utama relasional |
| Queue | Laravel database queue pada awal | Fitur bawaan Laravel 13.x | Pengiriman email dan pekerjaan latar belakang |
| Scanner/generator | Library QR yang sesuai runtime dan terpelihara | Paket dan versi kompatibel dipilih saat implementasi scanner/generator | Pembuatan kartu dan pembacaan kamera |
| Email | Layanan email transaksional | Penyedia ditentukan saat persiapan go-live | Verifikasi, reset password, pemberitahuan |
| File | Penyimpanan privat/object storage | Penyedia ditentukan saat persiapan go-live | Foto profil dengan akses terkontrol |
| Infrastruktur | Frontend, Laravel API, queue worker, scheduler, MySQL, backup | Mengikuti versi stack utama dan environment deployment | Pengoperasian seluruh alur produk |

Backend dibangun sebagai satu aplikasi dengan modul Auth, Member, Membership, Credential, Event, Attendance, Reporting, dan Audit. Microservices tidak diperlukan pada MVP.

Ketentuan versi:

- Gunakan rilis stabil terbaru dalam cabang versi yang ditetapkan ketika proyek dibuat; hindari prerelease seperti canary, alpha, beta, atau RC untuk dependency langsung produksi.
- Simpan composer.lock dan lockfile package manager frontend di repository agar instalasi menggunakan versi paket yang konsisten. Pin juga versi runtime pada konfigurasi environment/deployment.
- Terapkan pembaruan keamanan dan patch setelah pengujian yang relevan. Perubahan cabang versi utama stack inti dicatat sebagai revisi keputusan teknologi.
- Laravel dan Next.js berkomunikasi melalui HTTP API/JSON; tidak ada keharusan menyamakan nomor versi keduanya. Kontrak API, autentikasi, cookie, CSRF, dan CORS harus diuji bersama.

Acuan resmi: [Laravel release notes](https://laravel.com/framework/docs/releases), [Next.js support policy](https://nextjs.org/support-policy), [Next.js 16 requirements](https://nextjs.org/docs/app/guides/upgrading/version-16), [Node.js releases](https://nodejs.org/en/about/previous-releases), dan [MySQL 8.4 LTS](https://dev.mysql.com/doc/refman/8.4/en/mysql-releases.html).

PWA dapat menjadi peningkatan setelah web inti stabil. Instalasi PWA tidak berarti check-in offline sudah tersedia.

## 14. Requirement nonfungsional dan operasional

| ID | Area | Requirement |
| --- | --- | --- |
| NFR-01 | Keamanan akses | HTTPS, cookie aman sesuai arsitektur, CSRF, validasi backend, permission per request, rate limit |
| NFR-02 | Rahasia | Password di-hash; QR acak berentropi tinggi; hash untuk lookup; nilai asli terenkripsi jika ditampilkan ulang; rahasia tidak masuk log |
| NFR-03 | Perlindungan profil | Output encoding, validasi upload server, pembatasan ukuran/tipe, metadata foto yang tidak diperlukan dihapus bila memungkinkan |
| NFR-04 | Privasi | Data minimum sesuai role; endpoint publik tidak membeberkan profil; ekspor dibatasi dan diaudit |
| NFR-05 | Keandalan | Commit sebelum respons sukses; retry aman; queue/email dapat dipantau; kegagalan tidak merusak status domain |
| NFR-06 | Performa | Target awal p95 check-in ≤ 2 detik pada pilot 20 petugas aktif dan 5 konfirmasi/detik selama 10 menit; kondisi uji didokumentasikan |
| NFR-07 | Kompatibilitas | Uji scanner dan alur utama pada Chrome Android dan Safari iPhone serta dashboard browser desktop |
| NFR-08 | Aksesibilitas | Label form, navigasi keyboard pada form utama, status tekstual, kontras yang memadai |
| NFR-09 | Backup | Usulan backup otomatis harian terenkripsi dan uji restore sebelum go-live; target awal RPO 24 jam, RTO 8 jam perlu disepakati |
| NFR-10 | Monitoring | Pantau error API, queue gagal, pengiriman email, kegagalan check-in, dan anomali izin; tidak mencatat QR mentah |
| NFR-11 | Pemeliharaan | Konfigurasi per environment, migration terkendali, test alur kritis, dan prosedur deploy/rollback |
| NFR-12 | Ekspor aman | CSV tidak memicu formula spreadsheet dari input pengguna; hanya field yang dibutuhkan; unduhan tidak publik permanen |

RPO adalah toleransi kehilangan data; RTO adalah target pemulihan layanan. Backup harian dapat kehilangan sebagian data event jika terjadi kerusakan sebelum backup berikutnya; frekuensi/strategi backup perlu dinaikkan jika kehilangan tersebut tidak dapat diterima.

Kebijakan retensi, penghapusan/anonymisasi akun, penanganan permintaan akses data, dan peserta di bawah umur harus ditetapkan sebelum pengumpulan data produksi. PRD ini tidak menentukan kewajiban hukum tertentu atau menganggap pengakuan ketentuan sebagai dasar untuk semua pemrosesan data.

## 15. Laporan dan definisi perhitungan

| Laporan | Definisi |
| --- | --- |
| Total member aktif | Jumlah membership dengan status active; tampilkan akun blocked secara terpisah jika diperlukan |
| Member eligible check-in | Membership active dengan akun enabled, email terverifikasi, dan profil lengkap |
| Pertumbuhan member | Aktivasi pertama berdasarkan joined_at pada rentang waktu, bukan jumlah registrasi mentah |
| Kehadiran event | Jumlah attendance valid untuk event tersebut |
| Riwayat member | Attendance valid dan koreksi yang relevan, dengan konteks event |
| Belum pernah hadir | Member yang tidak memiliki attendance valid dalam seluruh riwayat atau rentang yang dipilih |
| Frekuensi partisipasi | Jumlah event yang dihadiri member pada rentang waktu |

Laporan membedakan registrasi pending, membership aktif, serta partisipasi. Kehadiran pada event cancelled diberi label dan dipisahkan dari ringkasan kegiatan terlaksana. Tidak adanya kehadiran tidak mengubah membership secara otomatis.

## 16. Skenario penerimaan lintas fitur

| ID | Skenario | Hasil yang wajib |
| --- | --- | --- |
| AC-01 | Registrasi valid lalu verifikasi | Satu akun, satu membership active, satu nomor member, satu QR aktif |
| AC-02 | Belum verifikasi mencoba check-in | Ditolak tanpa membuat attendance |
| AC-03 | Klik verifikasi berulang atau paralel | Nomor member dan QR tidak bertambah |
| AC-04 | Email verifikasi gagal terkirim | Akun tetap pending; resend/retry tersedia; tidak ada akun ganda |
| AC-05 | Tautan expired/invalid | Tidak ada aktivasi; pengguna memperoleh langkah pemulihan yang sesuai |
| AC-06 | Anggota mengikuti dua event | Dua attendance pada event berbeda memakai membership/QR yang sama |
| AC-07 | Scan dua kali pada event sama | Satu attendance; respons kedua menunjukkan waktu hadir sebelumnya |
| AC-08 | QR dan manual bersamaan oleh dua petugas | Satu baris attendance; total tidak bertambah dua |
| AC-09 | Respons terputus sesudah commit | Retry mengembalikan attendance existing; UI tidak menambah duplikasi |
| AC-10 | QR dicabut kemudian dipindai | Ditolak; QR baru tetap berfungsi jika syarat lain terpenuhi |
| AC-11 | Membership ditangguhkan sesudah preview | Konfirmasi ditolak melalui validasi ulang |
| AC-12 | Petugas memanipulasi event ID di request | Ditolak backend jika bukan penugasan |
| AC-13 | Kamera ditolak pengguna | Alternatif manual tersedia; tidak bypass permission/kelayakan |
| AC-14 | Check-in tepat sebelum buka atau tepat pada waktu tutup | Ditolak berdasarkan waktu server dan batas interval |
| AC-15 | Event dibatalkan/diselesaikan | Tidak ada check-in baru; sejarah tidak dihapus |
| AC-16 | Kehadiran dibatalkan lalu QR discan ulang | Tidak dipulihkan otomatis; admin harus melakukan koreksi dengan audit |
| AC-17 | Email aktif diganti | Alamat lama berlaku sampai verifikasi baru; nomor/QR tetap; tautan lama tidak memverifikasi email lain |
| AC-18 | Member meminta profil/riwayat member lain | Ditolak atau tidak ditemukan sesuai kebijakan API, tanpa kebocoran data |
| AC-19 | Admin mengganti QR bersamaan | Hanya satu credential akhir aktif |
| AC-20 | Koreksi dan ekspor laporan | Total hanya attendance valid; koreksi/ekspor tercatat; CSV aman dari formula input |
| AC-21 | Nama berisi karakter HTML/script | Ditampilkan sebagai teks; tidak dieksekusi pada portal, scanner, laporan |
| AC-22 | Pemulihan backup uji | Akun, membership, relasi, dan attendance pulih serta jumlahnya diverifikasi |

## 17. Risiko dan mitigasi

| Risiko | Dampak | Mitigasi MVP |
| --- | --- | --- |
| QR disalin atau titip absen | Kehadiran salah pemilik | Preview nama/foto, konfirmasi petugas, pencabutan credential, audit |
| Satu orang membuat beberapa akun | Pendataan orang unik tidak sempurna | Nyatakan batas jaminan email unik; prosedur admin meninjau duplikasi tanpa mencocokkan nama secara otomatis |
| Internet lokasi event buruk | Check-in terhenti | Uji koneksi, hotspot cadangan, prosedur rekonsiliasi; offline ditunda |
| Salah memilih event | Kehadiran masuk event lain | Nama/tanggal/lokasi menonjol dan pemilihan konteks eksplisit |
| Race condition | QR aktif ganda atau hadir ganda | Transaksi, locking, unique constraint, test paralel |
| Email gagal/spam | Aktivasi tertunda | Domain pengirim dikonfigurasi, monitoring, resend, dukungan admin |
| Data pribadi tersebar | Hilangnya kepercayaan | Minimalisasi data, akses per role, audit ekspor, penyimpanan privat |
| Ruang lingkup berkembang | Rilis inti tertunda | Pisahkan MVP dari RSVP, pembayaran, poin, dan offline |
| Kebijakan usia/retensi belum jelas | Produk belum siap digunakan publik | Selesaikan kebijakan sebelum go-live dan revisi aturan registrasi |

## 18. Tahapan delivery dan definisi selesai

| Tahap | Hasil |
| --- | --- |
| 1. Definisi produk | PRD ditinjau, asumsi penting dan pertanyaan terbuka diputuskan |
| 2. Desain | Alur halaman, model data MySQL, kontrak API, permission, dan desain credential |
| 3. Keanggotaan | Registrasi, email, login, profil, aktivasi, nomor member, kartu QR |
| 4. Operasional event | Event, penugasan, scanner, manual check-in, koreksi |
| 5. Pelaporan dan kesiapan operasi | Riwayat, CSV, audit, monitoring, backup/restore |
| 6. Pilot | Simulasi event dan penggunaan pada kegiatan internal terbatas |
| 7. Rilis | Perbaikan pilot selesai dan prosedur pengurus tersedia |

MVP selesai apabila semua P0 dan skenario penerimaan terkait lulus, concurrency tidak menghasilkan data ganda, perangkat petugas telah diuji, serta proses email, backup, pemulihan, dan dukungan anggota dapat dijalankan. Pilot juga harus memastikan proses konfirmasi identitas cukup cepat dan dipahami petugas.

PRD ini belum menetapkan estimasi waktu/biaya; estimasi dibuat setelah keputusan produk, ukuran tim, hosting, dan desain disepakati.

## 19. Keputusan produk dan tindak lanjut

Bagian ini mencatat jawaban pemilik produk atas Q-01 sampai Q-13. Keputusan berikut menjadi acuan terbaru dan menggantikan rumusan sebelumnya yang masih menyatakan pilihan tersebut sebagai asumsi, belum diterima, atau di luar alur MVP. Tindak lanjut yang belum ditentukan dibatasi pada detail yang disebutkan secara eksplisit.

| ID | Topik | Keputusan | Tindak lanjut |
| --- | --- | --- | --- |
| Q-01 | Nama produk dan komunitas | Nama aplikasi sorerunapp; komunitas Sore Running Club, disingkat SRC | Logo dan prefix nomor member final ditentukan saat desain |
| Q-02 | Persetujuan registrasi | Tidak perlu approval admin; aktif otomatis setelah email terverifikasi dan data wajib lengkap | Terapkan pada aktivasi |
| Q-03 | Biaya dan masa berlaku membership | Gratis dan tanpa tanggal kedaluwarsa otomatis | Terapkan pada model keanggotaan |
| Q-04 | Telepon dan domisili | Wajib; foto tetap opsional | Terapkan pada form dan validasi backend |
| Q-05 | Anggota di bawah umur | Diperbolehkan; tidak ada batas usia minimum otomatis pada MVP | Pengurus menjelaskan aturan pendampingan peserta pada event yang memerlukannya; akun wali tidak otomatis ditambahkan |
| Q-06 | Kartu cetak/screenshot | Diperbolehkan, dengan pemeriksaan identitas oleh petugas | Terapkan pada kartu dan SOP check-in |
| Q-07 | Kuota dan biaya event | Event menggunakan kuota sesuai A-05; tidak ada event berbayar pada tahap ini | Terapkan validasi kuota pada semua metode pencatatan |
| Q-08 | Ukuran pilot dan kapasitas awal | Gunakan target awal NFR-06: 20 petugas aktif, 5 konfirmasi/detik selama 10 menit, p95 respons ≤ 2 detik | Uji dan catat kapasitas; jumlah member/event bulanan diukur saat pilot |
| Q-09 | Retensi, penghapusan, dan dukungan akun | Gunakan kebijakan operasional pada Bagian 19.1 | Implementasikan kontrol data dan prosedur admin sebelum produksi |
| Q-10 | Aktivasi kembali membership ended | Admin boleh mengaktifkan kembali status keanggotaan setelah memeriksa syarat, dengan alasan dan audit | Gunakan membership dan nomor member yang sama; ketentuan pada Bagian 19.2 |
| Q-11 | Domain, hosting, email, operator | Ditentukan pada persiapan go-live | Tidak menghalangi pengembangan lokal |
| Q-12 | Backup dan pemulihan | Target awal NFR-09 diterima: backup harian, RPO 24 jam, RTO 8 jam | Uji restore sebelum go-live; penerimaan target bukan bukti hasil uji |
| Q-13 | Stack dan repository | Next.js, Laravel, dan MySQL diterima; frontend dan backend dapat dikelola dalam satu repository | Susunan awal yang direkomendasikan pada Bagian 19.3 |

### 19.1 Kebijakan operasional data dan dukungan akun (Q-09)

Kebijakan berikut ditetapkan sebagai baseline produk melalui kewenangan yang diberikan pemilik produk. Durasi ini adalah pilihan operasional aplikasi, bukan pernyataan bahwa semua durasi merupakan ketentuan hukum.

| Jenis data | Kebijakan |
| --- | --- |
| Akun pending yang belum memverifikasi email | Bersihkan setelah 30 hari dari registrasi jika tetap belum terverifikasi; pengguna dapat mendaftar kembali |
| Profil, membership aktif atau suspended, dan riwayat hadir | Disimpan selama hubungan keanggotaan masih berjalan; tidak otomatis dihapus hanya karena jarang hadir |
| Membership ended dan data terkait | Disimpan sampai 24 bulan sejak pengakhiran untuk dukungan dan peluang reaktivasi; reaktivasi sebelum batas tersebut menghentikan jadwal penghapusan karena status kembali aktif |
| Data pribadi setelah periode retensi berakhir | Hapus atau anonimisasi profil dan identitas; pertahankan statistik agregat tanpa identitas serta penanda teknis yang diperlukan agar nomor member tidak digunakan ulang |
| Audit dan log operasional | Retensi 12 bulan; batasi data pribadi, hindari credential mentah, dan anonimisasi identitas pada permintaan penghapusan apabila tidak ada alasan retensi khusus yang terdokumentasi |
| Backup | Retensi bergulir 30 hari, terenkripsi dan akses terbatas; penghapusan dari database utama tidak langsung mengubah backup lama |

Prosedur permintaan dan dukungan:

1. Member dapat meminta salinan data sendiri, koreksi, penutupan akun, atau penghapusan melalui pengurus/admin. Identitas pemohon diverifikasi melalui akun atau email terverifikasi sebelum tindakan.
2. Target penyelesaian permintaan adalah paling lambat 30 hari kalender setelah identitas pemohon terverifikasi. Jika ada alasan retensi khusus, admin mendokumentasikan alasan, batas waktu, dan menjelaskan bagian data yang dipertahankan kepada pemohon.
3. Permintaan penutupan tanpa penghapusan mengakhiri membership dan memblokir akses sesuai kebutuhan; riwayat mengikuti retensi ended. Permintaan penghapusan mengakhiri membership, mencabut credential, mengakhiri sesi, lalu menghapus/anonimisasi data pribadi dalam target waktu tersebut tanpa menunggu 24 bulan.
4. Nomor member yang sudah dipakai tidak dialokasikan ke pengguna lain. Setelah data dihapus/anonimisasi, reaktivasi akun lama tidak tersedia; pengguna mendaftar sebagai anggota baru jika ingin bergabung lagi.
5. Jika backup dipulihkan, daftar penghapusan yang masih relevan diterapkan kembali sebelum layanan dibuka agar data yang sudah dihapus tidak muncul kembali. Daftar ini hanya menyimpan pengenal teknis minimum sampai backup terkait kedaluwarsa.
6. Bantuan reset password dilakukan melalui mekanisme reset; admin tidak meminta atau membaca password pengguna. Perubahan email dan penggantian QR mengikuti pemeriksaan pemilik serta audit.
7. Pemberitahuan privasi menjelaskan jenis data, tujuan, pihak yang dapat mengakses, durasi penyimpanan, dan cara menghubungi pengurus. Data anggota di bawah umur dibatasi pada kebutuhan profil yang sudah ditentukan; tidak otomatis digunakan untuk promosi.

### 19.2 Reaktivasi membership ended (Q-10)

- Admin dapat mengubah ended menjadi active dengan alasan yang dicatat dalam audit.
- Backend memeriksa akun enabled, email terverifikasi, dan profil wajib lengkap. Jika akun blocked, pembukaan blokir merupakan tindakan admin terpisah; reaktivasi membership tidak otomatis melewati blokir.
- Reaktivasi menggunakan record membership dan nomor member sebelumnya serta mempertahankan riwayat. Tidak ada registrasi atau nomor member kedua.
- Credential yang sudah revoked tidak diaktifkan ulang. Jika tidak ada QR aktif, admin menerbitkan credential baru melalui mekanisme yang menjamin paling banyak satu QR aktif.
- Reaktivasi hanya tersedia selama data lama masih ada dan belum dihapus/anonimisasi berdasarkan kebijakan Q-09.

### 19.3 Satu repository untuk frontend dan backend (Q-13)

Next.js dan Laravel dapat disimpan dalam satu repository Git (monorepo). Setiap aplikasi tetap mempunyai direktori kerja, dependency, konfigurasi environment, perintah pengembangan, pengujian, dan proses runtime sendiri.

Susunan awal yang direkomendasikan:

| Path dalam repository sorerunapp | Isi |
| --- | --- |
| PRD.md | Acuan kebutuhan dan proses bisnis |
| frontend/ | Aplikasi Next.js, package.json, lockfile, dan konfigurasi frontend |
| backend/ | Aplikasi Laravel, composer.json, composer.lock, migration MySQL, queue, dan API |
| docs/ | Kontrak API, desain data, dan panduan operasional setelah dibuat |
| README.md | Cara menjalankan frontend, backend, queue worker, dan database |

Frontend berkomunikasi dengan Laravel API. Satu repository tidak mengharuskan keduanya memakai satu proses server atau satu deployment; pipeline dapat menjalankan build/deploy berdasarkan perubahan folder. Rahasia pada file .env tidak dimasukkan ke Git; contoh konfigurasi tanpa rahasia dapat disimpan sebagai .env.example pada masing-masing aplikasi. Database produksi, vendor, dan node_modules tidak disimpan sebagai isi repository.

## 20. Glosarium

| Istilah | Arti |
| --- | --- |
| Member | Anggota yang memiliki record keanggotaan; kelayakan event ditentukan statusnya |
| Membership | Hubungan keanggotaan pengguna dengan komunitas |
| Nomor member | Identitas administratif yang stabil |
| Credential QR | Token keanggotaan yang dapat dicabut/diganti tanpa mengganti nomor member |
| Check-in | Pencatatan kehadiran pada event tertentu |
| RSVP | Konfirmasi rencana ikut event; berbeda dari check-in dan registrasi anggota |
| Idempotent | Pengulangan operasi tidak menghasilkan efek bisnis ganda |
| Audit log | Catatan pelaku, waktu, tindakan, dan perubahan untuk penelusuran |
| Voided | Catatan hadir yang dibatalkan melalui koreksi, tetap disimpan untuk audit |
