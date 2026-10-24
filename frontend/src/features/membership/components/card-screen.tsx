"use client";
import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { Expand, Printer, RefreshCw, ScanLine } from "lucide-react";
import { useSession } from "@/providers/session-provider";
import { normalizeApiError } from "@/lib/errors";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { Alert, LoadingPanel } from "@/components/shared/feedback";
import { Card } from "@/components/ui/card";
import { ResendButton } from "@/features/auth/components/resend-button";
import { MembershipStatus } from "./membership-status";
import { QrCode } from "./qr-code";
import { getMembershipCard } from "../services";

export function CardScreen() {
  const { session } = useSession();
  const membership = session?.membership;
  const eligible =
    !!session?.user.email_verified &&
    session.profile_complete &&
    membership?.status === "active";
  const query = useQuery({
    queryKey: ["membership", "card", session?.user.id],
    queryFn: ({ signal }) => getMembershipCard(signal),
    enabled: eligible,
    meta: { requiresSession: true },
    refetchInterval: 30_000,
  });
  if (!session) return <LoadingPanel />;
  if (!eligible)
    return (
      <div className="stack-large">
        <header>
          <p className="eyebrow text-brand">Keanggotaan SRC</p>
          <h1 className="page-heading">Kartu Anggota</h1>
        </header>
        <Card className="stack-medium">
          {membership && <MembershipStatus status={membership.status} />}
          <Alert tone="warning">
            {membership?.status === "suspended"
              ? "Keanggotaan Anda sedang ditangguhkan. Hubungi pengurus untuk bantuan."
              : membership?.status === "ended"
                ? "Keanggotaan Anda telah berakhir. Hubungi pengurus untuk informasi aktivasi kembali."
                : !session.user.email_verified
                  ? "Verifikasi email Anda untuk mengaktifkan keanggotaan dan membuka kartu."
                  : "Kartu belum tersedia. Hubungi pengurus untuk melengkapi atau memeriksa data keanggotaan Anda."}
          </Alert>
          {!session.user.email_verified &&
            membership?.status === "pending_verification" && <ResendButton />}
        </Card>
      </div>
    );
  if (query.isPending)
    return <LoadingPanel>Menyiapkan kartu anggota…</LoadingPanel>;
  if (query.isError || !query.data)
    return (
      <Card className="stack-medium">
        <h1 className="page-heading">Kartu belum dapat ditampilkan</h1>
        <Alert>{normalizeApiError(query.error).message}</Alert>
        <Button
          onClick={() => {
            void query.refetch();
          }}
          disabled={query.isFetching}
        >
          Coba Lagi
        </Button>
        <Button variant="outline" asChild>
          <Link href="/beranda">Kembali ke Beranda</Link>
        </Button>
      </Card>
    );
  const card = query.data;
  const joined = membership?.joined_at
    ? new Intl.DateTimeFormat("id-ID", {
        day: "numeric",
        month: "long",
        year: "numeric",
        timeZone: "Asia/Jakarta",
      }).format(new Date(membership.joined_at))
    : null;
  return (
    <div className="stack-large">
      <header className="page-header">
        <div>
          <p className="eyebrow text-brand">Selalu siap di setiap langkah</p>
          <h1 className="page-heading">Kartu Anggota</h1>
          <p className="muted">
            Identitas Anda sebagai bagian dari Sore Running Club.
          </p>
        </div>
        <Button
          variant="outline"
          onClick={() => {
            void query.refetch();
          }}
          disabled={query.isFetching}
        >
          <RefreshCw size={18} aria-hidden />
          {query.isFetching ? "Memperbarui…" : "Perbarui Kartu"}
        </Button>
      </header>
      <div className="card-layout">
        <section className="member-card" aria-label="Kartu keanggotaan SRC">
          <div className="member-card-brand">
            <span className="card-wordmark">
              SRC<span aria-hidden>.</span>
            </span>
            <span>
              Sore Running Club
              <br />
              <small>sorerunapp</small>
            </span>
          </div>
          <div className="member-card-identity">
            <MembershipStatus status="active" />
            <h2>{card.full_name}</h2>
            <p className="member-number">{card.member_number}</p>
            {joined && <p className="card-joined">Bergabung {joined}</p>}
          </div>
          <div className="qr-panel">
            <QrCode payload={card.qr_payload} />
          </div>
          <p className="card-instruction">
            Tunjukkan QR ini kepada petugas
            <br />
            saat mengikuti kegiatan.
          </p>
          <div className="card-track" aria-hidden />
        </section>
        <div className="stack-medium card-support">
          <Card className="stack-medium">
            <ScanLine size={28} className="text-brand" aria-hidden />
            <h2 className="text-heading-3">Datang, tunjukkan, lalu berlari.</h2>
            <ol className="steps-list">
              <li>Buka kartu saat tiba di kegiatan SRC.</li>
              <li>Tunjukkan QR kepada petugas.</li>
              <li>
                Petugas mencocokkan identitas dan mengonfirmasi kehadiran.
              </li>
            </ol>
            <p className="supporting">
              Kehadiran mengikuti kuota dan ketentuan kegiatan saat check-in. QR
              bukan bukti hadir sebelum dikonfirmasi petugas.
            </p>
          </Card>
          <Dialog
            title="QR keanggotaan"
            description="Tunjukkan kepada petugas untuk dipindai. Cocokkan identitas Anda saat check-in."
            trigger={
              <Button variant="secondary">
                <Expand size={18} aria-hidden />
                Lihat QR Lebih Besar
              </Button>
            }
          >
            <div className="qr-dialog">
              <QrCode payload={card.qr_payload} />
            </div>
            <p className="center">
              <strong>{card.full_name}</strong>
              <br />
              <span className="member-number">{card.member_number}</span>
            </p>
          </Dialog>
          <Button variant="outline" onClick={() => window.print()}>
            <Printer size={18} aria-hidden />
            Cetak Kartu
          </Button>
          <p className="supporting">
            Kartu boleh dicetak atau disimpan sebagai screenshot. Bagikan hanya
            kepada petugas yang berwenang.
          </p>
        </div>
      </div>
    </div>
  );
}
