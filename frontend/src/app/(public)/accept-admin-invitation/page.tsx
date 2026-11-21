import { AcceptAdminInvitationForm } from "@/features/administration/components/accept-admin-invitation";

export const metadata = { title: "Aktifkan Akun Admin", robots: { index: false, follow: false } };
export default function Page() {
  return <AcceptAdminInvitationForm />;
}

