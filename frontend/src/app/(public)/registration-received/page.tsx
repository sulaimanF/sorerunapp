import Link from "next/link";
import { MailCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
export const metadata = { title: "Permintaan Registrasi Diterima" };
export default function Page() {
  return (
    <div className="stack-large">
      <MailCheck size={40} aria-hidden />
      <h2 className="page-heading">Periksa email Anda</h2>
      <p>
        Permintaan registrasi diterima. Jika alamat email dapat digunakan,
        instruksi verifikasi akan diproses.
      </p>
      <p className="muted">
        Periksa kotak masuk dan folder spam. Buka tautan verifikasi, lalu tekan Verifikasi Email. Setelah email terverifikasi, Anda dapat masuk.
      </p>
      <Button asChild>
        <Link href="/login">Masuk ke Akun</Link>
      </Button>
      <p className="supporting">
        Belum menerima email? <Link className="text-link" href="/resend-verification">Kirim Ulang Email</Link>.
      </p>
    </div>
  );
}
