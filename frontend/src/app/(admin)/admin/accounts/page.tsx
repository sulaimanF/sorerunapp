import { AdminShell } from "@/features/content/components/admin-shell";
import { AdminAccountsScreen } from "@/features/administration/components/admin-accounts-screen";

export const metadata = { title: "Daftar Akun Admin", robots: { index: false, follow: false } };

export default function Page() {
  return <AdminShell permission="provision"><AdminAccountsScreen /></AdminShell>;
}
