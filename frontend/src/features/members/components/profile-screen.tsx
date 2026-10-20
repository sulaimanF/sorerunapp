"use client";
import Link from "next/link";
import { useSession } from "@/providers/session-provider";
import { Card } from "@/components/ui/card";
import { Alert } from "@/components/shared/feedback";
import { MembershipStatus } from "@/features/membership/components/membership-status";
import { ResendButton } from "@/features/auth/components/resend-button";

export function ProfileScreen() {
  const { session } = useSession();
  if (!session) return null;
  return (
    <div className="profile-page stack-large">
      <header>
        <p className="eyebrow text-primary">Akun Anda</p>
        <h1 className="page-heading">Profil Anggota</h1>
        <p className="muted">Informasi akun dan status keanggotaan Anda.</p>
      </header>
      <Card className="stack-medium">
        <h2 className="text-heading-3">Identitas akun</h2>
        <dl className="profile-details">
          <div>
            <dt>Nama lengkap</dt>
            <dd>{session.user.full_name}</dd>
          </div>
          <div>
            <dt>Email</dt>
            <dd>{session.user.email}</dd>
          </div>
          <div>
            <dt>Status email</dt>
            <dd>
              {session.user.email_verified
                ? "Terverifikasi"
                : "Belum diverifikasi"}
            </dd>
          </div>
          <div>
            <dt>Keanggotaan</dt>
            <dd>
              {session.membership ? (
                <MembershipStatus status={session.membership.status} />
              ) : (
                "Belum tersedia"
              )}
            </dd>
          </div>
          {session.membership?.member_number && (
            <div>
              <dt>Nomor member</dt>
              <dd className="member-number">
                {session.membership.member_number}
              </dd>
            </div>
          )}
        </dl>
        {!session.user.email_verified &&
          session.membership?.status === "pending_verification" && (
            <ResendButton />
          )}
      </Card>
      <Alert tone="info">
        Untuk koreksi data profil atau bantuan akun, hubungi pengurus Sore
        Running Club melalui kanal komunitas.
      </Alert>
      <p className="supporting">
        <Link href="/privacy" className="text-link">
          Pemberitahuan privasi
        </Link>{" "}
        ·{" "}
        <Link href="/terms" className="text-link">
          Ketentuan komunitas
        </Link>
      </p>
    </div>
  );
}
