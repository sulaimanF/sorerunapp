import { MemberShell } from "@/features/auth/components/member-shell";
import { DashboardScreen } from "@/features/membership/components/dashboard-screen";
export const metadata = { title: "Beranda" };
export default function Page() {
  return (
    <MemberShell>
      <DashboardScreen />
    </MemberShell>
  );
}
