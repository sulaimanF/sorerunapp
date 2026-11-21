import { AdminShell } from "@/features/content/components/admin-shell";
import { CreateAdminScreen } from "@/features/administration/components/create-admin-form";

export const metadata = { title: "Buat Akun Admin", robots: { index: false, follow: false } };
export default function Page() {
  return <AdminShell permission="provision"><CreateAdminScreen /></AdminShell>;
}

