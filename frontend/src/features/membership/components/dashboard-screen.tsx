"use client";
import Link from "next/link";
import { ArrowUpRight, CreditCard, Footprints } from "lucide-react";
import { useSession } from "@/providers/session-provider";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Alert } from "@/components/shared/feedback";
import { MembershipStatus } from "./membership-status";
import { ResendButton } from "@/features/auth/components/resend-button";

export function DashboardScreen() {
  const { session } = useSession();
  if (!session) return null;
  const eligible =
    session.user.email_verified &&
    session.profile_complete &&
    session.membership?.status === "active";
  return (
    <div className="stack-large">
      <header>
        <p className="eyebrow text-primary">Ruang anggota SRC</p>
        <h1 className="page-heading">Halo, {session.user.full_name}.</h1>
        <p className="muted">Senang bisa melangkah bersama Anda.</p>
      </header>
      <div className="dashboard-grid">
        <Card className="dashboard-main stack-large">
          <div className="section-heading">
            <span className="icon-tile">
              <CreditCard size={24} aria-hidden />
            </span>
            {session.membership && (
              <MembershipStatus status={session.membership.status} />
            )}
          </div>
          <div>
            <h2 className="text-heading-2">Satu kartu untuk kebersamaan.</h2>
            <p className="muted">
              Akses identitas keanggotaan Anda dan tunjukkan kepada petugas saat
              mengikuti kegiatan internal SRC.
            </p>
          </div>
          {!eligible && (
            <Alert tone="warning">
              {!session.user.email_verified
                ? "Email Anda belum diverifikasi. Periksa email untuk melanjutkan aktivasi."
                : session.membership?.status === "suspended"
                  ? "Keanggotaan Anda sedang ditangguhkan. Hubungi pengurus untuk bantuan."
                  : session.membership?.status === "ended"
                    ? "Keanggotaan Anda telah berakhir. Hubungi pengurus untuk informasi aktivasi kembali."
                    : "Data keanggotaan perlu dilengkapi atau diperiksa pengurus."}
            </Alert>
          )}
          <Button asChild>
            <Link href="/kartu">
              {eligible ? "Lihat Kartu Anggota" : "Lihat Status Kartu"}
              <ArrowUpRight size={18} aria-hidden />
            </Link>
          </Button>
          {!session.user.email_verified &&
            session.membership?.status === "pending_verification" && (
              <ResendButton />
            )}
        </Card>
        <Card className="community-panel stack-medium">
          <Footprints size={30} aria-hidden />
          <h2 className="text-heading-2">
            Berlari bersama,
            <br />
            bertumbuh bersama.
          </h2>
          <p>
            Tidak perlu mendaftar ulang untuk setiap kegiatan internal. Petugas
            mencatat kehadiran Anda saat acara berlangsung.
          </p>
          <p className="supporting">
            Keanggotaan gratis dan tidak memiliki tanggal kedaluwarsa otomatis.
          </p>
        </Card>
      </div>
    </div>
  );
}
