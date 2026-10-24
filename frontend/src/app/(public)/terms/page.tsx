import Link from "next/link";
export const metadata = { title: "Ketentuan Komunitas" };
export default function Page() {
  return (
    <article className="legal-copy stack-medium">
      <h2 className="page-heading">Ketentuan komunitas</h2>
      <p>
        sorerunapp digunakan untuk keanggotaan dan kegiatan internal Sore
        Running Club.
      </p>
      <h3>Keanggotaan</h3>
      <p>
        Keanggotaan gratis dan tidak memiliki tanggal kedaluwarsa otomatis. Anda
        mendaftar sekali, memverifikasi email, dan melengkapi data wajib agar
        keanggotaan aktif tanpa approval admin.
      </p>
      <h3>Penggunaan akun dan kartu</h3>
      <p>
        Gunakan data yang benar dan jaga password Anda. QR keanggotaan boleh
        dicetak atau disimpan sebagai screenshot. QR bukan token login dan dapat
        dicabut atau diganti oleh pengelola sesuai prosedur.
      </p>
      <h3>Kehadiran kegiatan</h3>
      <p>
        Kegiatan internal tidak menggunakan pendaftaran ulang atau reservasi.
        Kehadiran dicatat petugas setelah identitas dicocokkan, mengikuti izin,
        jadwal, kuota, dan status keanggotaan yang berlaku saat check-in.
      </p>
      <h3>Status dan bantuan</h3>
      <p>
        Keanggotaan dapat ditangguhkan atau diakhiri melalui pengelolaan
        komunitas. Aktivasi kembali dilakukan admin setelah syarat dipenuhi.
        Nomor anggota yang sudah diterbitkan tidak digunakan untuk orang lain.
      </p>
      <p>
        Untuk bantuan atau penjelasan aturan kegiatan, hubungi pengurus SRC
        melalui kanal komunitas.
      </p>
      <Link href="/register" className="text-link">
        Kembali ke registrasi
      </Link>
    </article>
  );
}
