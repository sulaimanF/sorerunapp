import { notFound } from "next/navigation";
import { Suspense } from "react";
import { LoadingPanel } from "@/components/shared/feedback";
import { AdminShell } from "@/features/content/components/admin-shell";
import { AdminInvitationScreen } from "@/features/administration/components/create-admin-form";

export const metadata = { title: "Status Undangan Admin", robots: { index: false, follow: false } };
export default function Page({ params }: { params: Promise<{ userId: string }> }) {
  return <AdminShell permission="provision"><Suspense fallback={<LoadingPanel>Memuat status undangan…</LoadingPanel>}>
    <InvitationRoute params={params} />
  </Suspense></AdminShell>;
}

async function InvitationRoute({ params }: { params: Promise<{ userId: string }> }) {
  const { userId } = await params;
  const id = Number(userId);
  if (!/^[1-9][0-9]*$/.test(userId) || !Number.isSafeInteger(id)) notFound();
  return <AdminInvitationScreen userId={id} />;
}
