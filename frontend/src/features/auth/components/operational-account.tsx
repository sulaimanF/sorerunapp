"use client";

import { useQuery } from "@tanstack/react-query";
import { useSession } from "@/providers/session-provider";
import { getOwnAccount } from "../account";
import { roleLabels } from "../permissions";
import { Alert, LoadingPanel } from "@/components/shared/feedback";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { normalizeApiError } from "@/lib/errors";
import { EmailChangeForm } from "./email-change-form";
import { OwnPasswordControl } from "@/features/administration/components/administrative-password-form";

export function OperationalAccount() {
  const { session } = useSession();
  const query = useQuery({
    queryKey: ["auth", "account", session?.user.id], queryFn: ({ signal }) => getOwnAccount(signal),
    enabled: !!session, meta: { requiresSession: true }, refetchOnWindowFocus: false,
  });
  if (!session) return null;
  if (query.isPending) return <LoadingPanel>Memuat informasi akun…</LoadingPanel>;
  if (!query.data) return <Card className="stack-medium"><Alert>{normalizeApiError(query.error).message}</Alert><Button onClick={() => void query.refetch()}>Coba Lagi</Button></Card>;
  return <div className="stack-large">
    <header><p className="eyebrow text-brand">Akun operasional</p><h1 className="page-heading">Profil Akun</h1><p>Akun ini tidak mempunyai keanggotaan atau kartu QR.</p></header>
    {query.error && <Alert>{normalizeApiError(query.error).message}</Alert>}
    <Card className="stack-medium">
      <dl className="profile-details">
        <div><dt>Nama lengkap</dt><dd>{query.data.full_name}</dd></div>
        <div><dt>Email aktif</dt><dd>{query.data.email}</dd></div>
        <div><dt>Status email</dt><dd>{query.data.email_verified ? "Terverifikasi" : "Belum diverifikasi"}</dd></div>
        <div><dt>Role</dt><dd>{session.roles.map(role => roleLabels[role]).join(", ")}</dd></div>
      </dl>
      <EmailChangeForm key={session.user.id} userId={session.user.id} status={query.data.email_change} scope="account" />
    </Card>
    <OwnPasswordControl key={session.user.id} />
  </div>;
}

