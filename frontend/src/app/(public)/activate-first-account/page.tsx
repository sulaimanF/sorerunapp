import { AcceptAdminInvitationForm } from "@/features/administration/components/accept-admin-invitation";

export const metadata = { title: "Aktifkan Akun Operasional", robots: { index: false, follow: false } };
export default function Page() {
  return <AcceptAdminInvitationForm firstAccount />;
}
