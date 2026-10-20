import Link from "next/link";
export const metadata = { title: "Pemberitahuan Privasi" };
export default function Page() {
  return (
    <article className="legal-copy stack-medium">
      <h2 className="page-heading">Pemberitahuan privasi</h2>
      <p>
        Sore Running Club menggunakan data anggota untuk mendata keanggotaan,
        memverifikasi akun, menyediakan kartu, dan mencatat kehadiran kegiatan
        internal.
      </p>
      <h3>Data yang digunakan</h3>
      <p>
        Nama, tanggal lahir, telepon, domisili, email, dan password diperlukan
        untuk akun. Password disimpan dalam bentuk hash. Jenis kelamin dan foto
        bersifat opsional; foto tidak diminta pada form ini.
      </p>
      <h3>Akses dan penggunaan</h3>
      <p>
        Anggota mengakses data sendiri. Pengurus dan petugas yang berwenang
        mengakses data sesuai tugasnya. QR tidak memuat nama, email, telepon,
        atau tanggal lahir. QR perlu dijaga dan hanya ditunjukkan kepada petugas
        yang berwenang.
      </p>
      <h3>Penyimpanan data</h3>
      <p>
        Kebijakan komunitas menetapkan akun pending yang belum terverifikasi
        dibersihkan setelah 30 hari. Data keanggotaan aktif atau ditangguhkan
        disimpan selama hubungan keanggotaan berjalan. Data keanggotaan berakhir
        disimpan hingga 24 bulan untuk dukungan dan kemungkinan aktivasi
        kembali.
      </p>
      <p>
        Audit dan log operasional mengikuti retensi 12 bulan. Backup mengikuti
        retensi bergulir 30 hari. Penghapusan pada sistem utama tidak langsung
        menghapus salinan backup; penanda penghapusan diterapkan saat pemulihan.
      </p>
      <h3>Permintaan dan bantuan</h3>
      <p>
        Anda dapat meminta koreksi, salinan data, penutupan akun, atau
        penghapusan melalui pengurus SRC pada kanal komunitas. Identitas
        diperiksa terlebih dahulu. Target penyelesaian permintaan adalah 30 hari
        kalender setelah identitas terverifikasi, dengan penjelasan apabila ada
        alasan retensi khusus.
      </p>
      <p>
        Data anggota di bawah umur dibatasi pada kebutuhan profil dan tidak
        otomatis digunakan untuk promosi.
      </p>
      <Link href="/register" className="text-link">
        Kembali ke registrasi
      </Link>
    </article>
  );
}
