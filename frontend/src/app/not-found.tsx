import Link from "next/link";
export default function NotFound() {
  return (
    <main id="main-content" className="recovery-panel stack-medium">
      <h1 className="page-heading">Halaman tidak ditemukan</h1>
      <p>Periksa alamat halaman atau kembali ke akun Anda.</p>
      <Link href="/beranda" className="text-link">
        Kembali ke Beranda
      </Link>
    </main>
  );
}
