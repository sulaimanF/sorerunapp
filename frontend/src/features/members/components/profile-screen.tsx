"use client";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { useSession } from "@/providers/session-provider";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Alert, LoadingPanel } from "@/components/shared/feedback";
import { normalizeApiError } from "@/lib/errors";
import { MembershipStatus } from "@/features/membership/components/membership-status";
import { ResendButton } from "@/features/auth/components/resend-button";
import { getMemberProfile } from "../services";
import { ProfileEditor } from "./profile-editor";

export function ProfileScreen() {
  const { session } = useSession();
  const query = useQuery({
    queryKey: ["members", "profile", session?.user.id],
    queryFn: ({ signal }) => getMemberProfile(signal),
    enabled: !!session,
    meta: { requiresSession: true },
    // Do not replace a member's unsaved edits on focus; the session still revalidates.
    refetchOnWindowFocus: false,
  });
  if (!session) return null;
  if (query.isPending) return <LoadingPanel>Memuat profil Anda…</LoadingPanel>;
  if (query.isError || !query.data) return (
    <Card className="stack-medium">
      <h1 className="page-heading">Profil belum dapat ditampilkan</h1>
      <Alert>{normalizeApiError(query.error).message}</Alert>
      <Button disabled={query.isFetching} onClick={() => void query.refetch()}>Coba Lagi</Button>
    </Card>
  );
  return (
    <div className="profile-page stack-large">
      <header>
        <p className="eyebrow">Akun Anda</p>
        <h1 className="page-heading">Profil Anggota</h1>
        <p className="muted">Kelola biodata dan lihat status keanggotaan Anda.</p>
      </header>
      <Card><ProfileEditor key={session.user.id} profile={query.data} userId={session.user.id} /></Card>
      <Card className="stack-medium">
        <h2 className="text-heading-3">Akun dan keanggotaan</h2>
        <dl className="profile-details">
          <div><dt>Email</dt><dd>{session.user.email}</dd></div>
          <div><dt>Status email</dt><dd>{session.user.email_verified ? "Terverifikasi" : "Belum diverifikasi"}</dd></div>
          <div><dt>Keanggotaan</dt><dd>{session.membership ? <MembershipStatus status={session.membership.status} /> : "Belum tersedia"}</dd></div>
          {session.membership?.member_number && <div><dt>Nomor member</dt><dd className="member-number">{session.membership.member_number}</dd></div>}
        </dl>
        {!session.user.email_verified && session.membership?.status === "pending_verification" && <ResendButton />}
        <p className="supporting">Untuk perubahan email atau bantuan akun, hubungi pengurus melalui kanal komunitas.</p>
      </Card>
      <p className="supporting"><Link href="/privacy" className="text-link">Pemberitahuan privasi</Link>{" · "}<Link href="/terms" className="text-link">Ketentuan komunitas</Link></p>
    </div>
  );
}
