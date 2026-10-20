"use client";
import { useEffect, useSyncExternalStore } from "react";
import Link from "next/link";
import { useMutation } from "@tanstack/react-query";
import { BadgeCheck, MailCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Alert, LoadingPanel } from "@/components/shared/feedback";
import { ApiError, normalizeApiError } from "@/lib/errors";
import { apiOrigin } from "@/lib/config";
import { useSession } from "@/providers/session-provider";
import { intentFromFragment, verificationIntent } from "../verification-intent";
import { verifyMemberEmail } from "../services";
import { ResendButton } from "./resend-button";

export function VerificationScreen() {
  const intent = useSyncExternalStore(
    verificationIntent.subscribe,
    verificationIntent.get,
    verificationIntent.server,
  );
  const auth = useSession();
  useEffect(() => {
    if (!window.location.hash) return;
    try {
      verificationIntent.set(
        intentFromFragment(window.location.hash, apiOrigin()),
      );
    } catch {
      verificationIntent.set(null);
    } finally {
      window.history.replaceState(
        window.history.state,
        "",
        window.location.pathname,
      );
    }
  }, []);
  const mutation = useMutation({
    mutationFn: async () => {
      const path = verificationIntent.get();
      if (!path)
        throw new ApiError(
          "Buka kembali tautan verifikasi dari email.",
          403,
          "INVALID_VERIFICATION_LINK",
        );
      const result = await verifyMemberEmail(path);
      verificationIntent.set(null);
      await auth.refresh();
      return result;
    },
    onError(error) {
      if (
        error instanceof ApiError &&
        (error.status === 401 || error.code === "ACCOUNT_BLOCKED")
      )
        void auth.refresh().catch(() => undefined);
    },
  });
  if (auth.loading || auth.transition === "refresh") return <LoadingPanel />;
  if (auth.error) return <Alert>{auth.error.message}</Alert>;
  if (mutation.data)
    return (
      <div className="stack-medium">
        <BadgeCheck className="success-icon" size={40} aria-hidden />
        <h2 className="page-heading">
          {mutation.data.result === "email_verified_profile_incomplete"
            ? "Email terverifikasi"
            : "Selamat bergabung di SRC!"}
        </h2>
        <p>
          {mutation.data.result === "email_verified_profile_incomplete"
            ? "Aktivasi menunggu data profil wajib lengkap. Hubungi pengurus untuk melengkapi data Anda."
            : "Keanggotaan Anda aktif. Kartu anggota siap digunakan saat kegiatan komunitas."}
        </p>
        <Button asChild>
          <Link
            href={
              mutation.data.result === "email_verified_profile_incomplete"
                ? "/profil"
                : "/kartu"
            }
          >
            {mutation.data.result === "email_verified_profile_incomplete"
              ? "Lihat Profil"
              : "Lihat Kartu Anggota"}
          </Link>
        </Button>
      </div>
    );
  return (
    <div className="stack-large">
      <header>
        <MailCheck className="text-primary" size={36} aria-hidden />
        <h2 className="page-heading">Verifikasi email Anda</h2>
        <p className="muted">
          Satu langkah lagi untuk mengaktifkan keanggotaan.
        </p>
      </header>
      {!intent && (
        <Alert tone="warning">
          Tautan belum tersedia atau tidak valid. Buka kembali tautan dari email
          verifikasi Anda. Jika halaman dimuat ulang setelah login, buka kembali
          emailnya.
        </Alert>
      )}
      {!auth.session ? (
        <>
          <p>
            Masuk menggunakan akun pemilik alamat email agar kami dapat
            memverifikasi keanggotaan Anda.
          </p>
          <Button asChild>
            <Link href="/login?returnTo=%2Fverify-email" prefetch={false}>
              Masuk untuk Verifikasi
            </Link>
          </Button>
        </>
      ) : (
        <>
          <p>
            Anda masuk sebagai <strong>{auth.session.user.email}</strong>.
          </p>
          {mutation.error && (
            <Alert>{normalizeApiError(mutation.error).message}</Alert>
          )}
          {intent && (
            <Button
              onClick={() => mutation.mutate()}
              disabled={mutation.isPending}
            >
              {mutation.isPending
                ? "Memverifikasi…"
                : "Verifikasi dan Aktifkan Keanggotaan"}
            </Button>
          )}
          {auth.session.user.email_verified &&
            auth.session.membership?.status === "active" && (
              <Button variant="secondary" asChild>
                <Link href="/kartu">Keanggotaan aktif — Lihat Kartu</Link>
              </Button>
            )}
          {!auth.session.user.email_verified &&
            auth.session.membership?.status === "pending_verification" && (
              <ResendButton />
            )}
          <Button
            variant="ghost"
            disabled={mutation.isPending}
            onClick={() => {
              void auth.logout().catch(() => undefined);
            }}
          >
            Keluar untuk mengganti akun
          </Button>
        </>
      )}
    </div>
  );
}
