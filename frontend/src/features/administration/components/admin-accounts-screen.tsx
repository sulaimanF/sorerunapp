"use client";

import { useState } from "react";
import Link from "next/link";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Alert, LoadingPanel } from "@/components/shared/feedback";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { canProvisionAdmins } from "@/features/auth/permissions";
import { normalizeApiError } from "@/lib/errors";
import { useSession } from "@/providers/session-provider";
import { getAdminAccounts } from "../services";
import { DeleteAdminDialog } from "./delete-admin-dialog";
import { AdminPasswordDialog } from "./administrative-password-form";

export function AdminAccountsScreen() {
  const { session } = useSession();
  if (!session || !canProvisionAdmins(session)) return null;
  return <AdminAccounts key={session.user.id} actorId={session.user.id} />;
}

function AdminAccounts({ actorId }: { actorId: number }) {
  const client = useQueryClient();
  const [page, setPage] = useState(1);
  const [deleted, setDeleted] = useState(false);
  const [passwordChanged, setPasswordChanged] = useState(false);
  const query = useQuery({
    queryKey: ["administration", "accounts", actorId, page],
    queryFn: ({ signal }) => getAdminAccounts(page, signal),
    meta: { requiresSession: true },
  });
  return <div className="stack-large">
    <header className="stack-small">
      <p className="eyebrow text-brand">Super Admin</p>
      <h1 className="page-heading">Daftar Akun Admin</h1>
      <p>Akun yang memiliki role Admin, termasuk akun yang belum diaktifkan. Keanggotaan terpisah dari role administratif.</p>
      <div className="flex flex-wrap gap-3">
        <Button asChild><Link href="/admin/accounts/new">Buat Akun Admin</Link></Button>
        <Button variant="outline" loading={query.isFetching} disabled={query.isFetching} aria-busy={query.isFetching} onClick={() => void query.refetch()}>
          {query.isFetching ? "Memuat daftar…" : "Perbarui Daftar"}
        </Button>
      </div>
    </header>
    {deleted && <Alert tone="success">Akun Admin telah dihapus dari daftar. Akses, sesi, dan undangan dicabut; audit dan email tetap disimpan.</Alert>}
    {passwordChanged && <Alert tone="success">Password Admin berhasil diubah tanpa konfirmasi email. Seluruh sesi Admin tersebut telah diakhiri.</Alert>}
    {query.isPending ? <LoadingPanel>Memuat daftar akun Admin…</LoadingPanel>
      : query.isError ? <Alert>{normalizeApiError(query.error).message}</Alert>
      : <>
        <p role="status">{query.data.pagination.total} akun Admin · Halaman {page} dari {query.data.pagination.last_page}</p>
        {query.data.items.length === 0 ? <Card><p>{page === 1 ? "Belum ada akun Admin." : "Tidak ada akun pada halaman ini. Kembali ke halaman sebelumnya."}</p></Card>
          : <ul aria-label="Daftar akun Admin" className="grid gap-5">
            {query.data.items.map(account => <li key={account.user_id}>
              <Card className="stack-medium">
                <h2 className="text-heading-3 break-words">{account.full_name}</h2>
                <dl className="profile-details">
                  <div><dt>Email</dt><dd className="break-all">{account.email}</dd></div>
                  <div><dt>Role</dt><dd>Admin</dd></div>
                  <div><dt>Status akun</dt><dd>{account.account_status === "enabled" ? "Aktif" : "Diblokir"}</dd></div>
                  <div><dt>Verifikasi email</dt><dd>{account.email_verified ? "Sudah terverifikasi" : "Belum terverifikasi"}</dd></div>
                </dl>
                {!account.has_invitation && <p className="supporting">Akun ini tidak dibuat melalui undangan Admin.</p>}
                <div className="flex flex-wrap gap-3">
                  {account.can_change_password && <AdminPasswordDialog userId={account.user_id} fullName={account.full_name} email={account.email} onChanged={async () => {
                    setPasswordChanged(true);
                    await client.invalidateQueries({ queryKey: ["administration", "accounts", actorId] });
                  }} />}
                  {account.has_invitation && <Button variant="outline" asChild>
                    <Link href={`/admin/accounts/${account.user_id}/invitation`} aria-label={`Status Undangan ${account.full_name}`}>Status Undangan</Link>
                  </Button>}
                  {account.can_delete && <DeleteAdminDialog userId={account.user_id} fullName={account.full_name} email={account.email} actorId={actorId} onDeleted={async () => {
                    setDeleted(true);
                    if (page > 1 && query.data.items.length === 1) setPage(current => current - 1);
                    await client.invalidateQueries({ queryKey: ["administration", "accounts", actorId] });
                  }} />}
                </div>
                {!account.can_delete && <p className="supporting">Penghapusan hanya tersedia untuk Admin operasional tanpa role lain, profil anggota, atau membership.</p>}
              </Card>
            </li>)}
          </ul>}
        <nav aria-label="Halaman daftar Admin" className="flex flex-wrap items-center gap-3">
          <Button variant="outline" disabled={page <= 1 || query.isFetching} onClick={() => setPage(current => current - 1)}>Sebelumnya</Button>
          <Button variant="outline" disabled={page >= query.data.pagination.last_page || query.isFetching} onClick={() => setPage(current => current + 1)}>Berikutnya</Button>
        </nav>
      </>}
  </div>;
}
