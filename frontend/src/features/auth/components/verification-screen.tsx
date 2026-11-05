"use client";
import { useEffect, useSyncExternalStore } from "react";
import Link from "next/link";
import { useMutation } from "@tanstack/react-query";
import { BadgeCheck, MailCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Alert } from "@/components/shared/feedback";
import { ApiError, normalizeApiError } from "@/lib/errors";
import { apiOrigin } from "@/lib/config";
import { intentFromFragment, verificationIntent } from "../verification-intent";
import { verifyMemberEmail } from "../services";
import { AUTH_NAVIGATION } from "../navigation";

export function VerificationScreen() {
  const intent = useSyncExternalStore(
    verificationIntent.subscribe, verificationIntent.get, verificationIntent.server,
  );
  const mutation = useMutation({
    mutationFn: async () => {
      const path = verificationIntent.get();
      if (!path) throw new ApiError("Buka kembali tautan verifikasi dari email.", 403, "INVALID_VERIFICATION_LINK");
      const result = await verifyMemberEmail(path);
      verificationIntent.set(null);
      return result;
    },
  });
  const resetVerification = mutation.reset;
  useEffect(() => {
    function readLink() {
      if (!window.location.hash) return;
      resetVerification();
      try {
        verificationIntent.set(intentFromFragment(window.location.hash, apiOrigin()));
      } catch {
        verificationIntent.set(null);
      } finally {
        window.history.replaceState(window.history.state, "", window.location.pathname);
      }
    }
    readLink();
    window.addEventListener("hashchange", readLink);
    return () => window.removeEventListener("hashchange", readLink);
  }, [resetVerification]);
  if (mutation.data) return (
    <div className="stack-medium">
      <BadgeCheck className="success-icon" size={40} aria-hidden />
      <h2 className="page-heading">Email terverifikasi</h2>
      <p>{mutation.data.result === "email_verified_profile_incomplete"
        ? "Email Anda terverifikasi. Masuk dan lengkapi profil untuk mengaktifkan keanggotaan."
        : "Verifikasi selesai. Silakan masuk untuk membuka akun dan kartu anggota Anda."}</p>
      <Button asChild><Link href={AUTH_NAVIGATION.login}>Masuk ke Akun</Link></Button>
    </div>
  );
  return (
    <div className="stack-large src-auth-root">
      <header>
        <MailCheck size={36} aria-hidden />
        <h2 className="page-heading">Verifikasi email Anda</h2>
        <p className="muted">Konfirmasi email sebelum masuk ke sorerunapp.</p>
      </header>
      <p>Tekan tombol di bawah untuk mengonfirmasi alamat email Anda. Verifikasi ini tidak membuat Anda masuk ke akun secara otomatis.</p>
      {!intent && <Alert tone="warning">Tautan belum tersedia atau tidak valid. Buka kembali tautan dari email verifikasi Anda.</Alert>}
      {mutation.error && <Alert>{normalizeApiError(mutation.error).message}</Alert>}
      {intent && <Button onClick={() => mutation.mutate()} disabled={mutation.isPending}>
        {mutation.isPending ? "Memverifikasi…" : "Verifikasi Email"}
      </Button>}
      <nav className="src-auth-secondary-links" aria-label="Bantuan verifikasi email">
        <Link href="/resend-verification" className="text-link">Kirim Ulang Email</Link>
        <Link href={AUTH_NAVIGATION.login} className="text-link">Kembali ke Login</Link>
      </nav>
    </div>
  );
}
