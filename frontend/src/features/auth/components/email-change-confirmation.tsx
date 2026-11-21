"use client";

import { useEffect, useRef, useState, useSyncExternalStore } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { Alert, LoadingPanel } from "@/components/shared/feedback";
import { Button } from "@/components/ui/button";
import { normalizeApiError, isSessionRejection, type ApiError } from "@/lib/errors";
import { clearPrivateQueries, sessionKey } from "@/lib/query-client";
import { useSession } from "@/providers/session-provider";
import type { BrowserSession } from "../types";
import { confirmEmailChange } from "../email-change";
import { emailChangeFromFragment, emailChangeIntent } from "../email-change-intent";
import { useCountdown } from "../hooks/use-countdown";

export function EmailChangeConfirmation() {
  const auth = useSession();
  const router = useRouter();
  const client = useQueryClient();
  const proof = useSyncExternalStore(emailChangeIntent.subscribe, emailChangeIntent.get, emailChangeIntent.server);
  const ready = useSyncExternalStore(emailChangeIntent.subscribe, emailChangeIntent.ready, emailChangeIntent.serverReady);
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);
  const [problem, setProblem] = useState<ApiError | null>(null);
  const pending = useRef<AbortController | null>(null);
  const countdown = useCountdown();
  useEffect(() => {
    const release = emailChangeIntent.retain();
    emailChangeIntent.set(window.location.hash ? emailChangeFromFragment(window.location.hash) : emailChangeIntent.get());
    window.history.replaceState(window.history.state, "", "/verify-email-change");
    function receiveLink(event: HashChangeEvent) {
      pending.current?.abort();
      emailChangeIntent.set(emailChangeFromFragment(new URL(event.newURL).hash));
      window.history.replaceState(window.history.state, "", "/verify-email-change");
      setProblem(null);
      setSaved(false);
      setBusy(false);
    }
    window.addEventListener("hashchange", receiveLink);
    return () => {
      window.removeEventListener("hashchange", receiveLink);
      pending.current?.abort();
      release();
    };
  }, []);
  async function confirm() {
    if (!proof || !auth.session || busy) return;
    const actorId = auth.session.user.id;
    const controller = new AbortController();
    pending.current = controller;
    setBusy(true);
    setProblem(null);
    try {
      await confirmEmailChange(proof, controller.signal);
      if (controller.signal.aborted) return;
      emailChangeIntent.set(null);
      setSaved(true);
      // An old response must never clear a different identity established in this tab.
      if (await auth.completeEmailChange(actorId)) {
        router.replace("/login");
      }
    } catch (error) {
      if (controller.signal.aborted) return;
      const parsed = normalizeApiError(error);
      setProblem(parsed);
      if (parsed.status === 429) countdown.start(parsed.retryAfter || 60);
      if (parsed.code === "EMAIL_CHANGE_INVALID" || parsed.code === "EMAIL_UNAVAILABLE") emailChangeIntent.set(null);
      if (isSessionRejection(parsed) && client.getQueryData<BrowserSession | null>(sessionKey)?.user.id === actorId)
        await clearPrivateQueries(client, actorId);
    } finally {
      if (!controller.signal.aborted) setBusy(false);
    }
  }
  if (!ready || auth.loading) return <LoadingPanel>Membaca tautan dan memeriksa sesi…</LoadingPanel>;
  return (
    <div className="stack-large src-auth-root src-auth-email-change">
      <header><h2 className="page-heading">Konfirmasi email baru</h2><p className="muted">Email aktif hanya berubah setelah Anda mengonfirmasi.</p></header>
      {saved ? <Alert tone="success">Email berhasil diubah. Seluruh sesi akun terkait diakhiri; masuk kembali menggunakan email baru.</Alert> : <>
        {problem && <Alert>{problem.message}
          {(problem.status === 0 || problem.status >= 500) && <p>Hasil konfirmasi belum diketahui. Jika sesi berakhir, coba masuk menggunakan email baru. Jangan mengulang konfirmasi secara otomatis.</p>}
          {problem.status === 419 && <p>Perlindungan sesi akan diperbarui saat mencoba lagi.</p>}
        </Alert>}
        {auth.error && <Alert>{auth.error.message}<p><Button variant="outline" onClick={() => void auth.refresh().catch(() => undefined)}>Periksa Sesi Lagi</Button></p></Alert>}
        {!proof ? <Alert tone="warning">Tautan tidak tersedia, tidak valid, kedaluwarsa, atau sudah digunakan. Buka email terbaru atau ajukan perubahan email baru dari Profil.</Alert>
          : !auth.session ? <Alert tone="info">Masuk menggunakan alamat email aktif lama, lalu buka ulang tautan dari email. Tautan tidak disimpan setelah Anda meninggalkan halaman ini.</Alert>
          : <form onSubmit={event => { event.preventDefault(); void confirm(); }} className="stack-medium" aria-label="Konfirmasi perubahan email">
            <p>Anda sedang menggunakan akun dengan email aktif <strong>{auth.session.user.email}</strong>.</p>
            <p>Email baru yang akan dikonfirmasi: <strong>{proof.email}</strong>.</p>
            <p className="supporting">Pastikan ini akun yang meminta perubahan. Konfirmasi akan mengakhiri seluruh sesi akun tersebut. Setelah berhasil, masuk kembali menggunakan email baru. Membership, nomor member, dan QR tetap.</p>
            <Button type="submit" disabled={busy || countdown.remaining > 0}>{busy ? "Mengonfirmasi email…" : countdown.remaining ? `Tunggu ${countdown.remaining} detik` : "Konfirmasi Email Baru"}</Button>
          </form>}
      </>}
      <nav className="src-auth-secondary-links" aria-label="Bantuan perubahan email">
        <Link href="/login" className="text-link">Kembali ke Login</Link>
        {auth.session && <Link href="/profil" className="text-link">Kembali ke Profil</Link>}
      </nav>
    </div>
  );
}
