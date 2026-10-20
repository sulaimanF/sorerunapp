import { MemberShell } from "@/features/auth/components/member-shell";
import { CardScreen } from "@/features/membership/components/card-screen";
export const metadata = { title: "Kartu Anggota" };
export default function Page() {
  return (
    <MemberShell>
      <CardScreen />
    </MemberShell>
  );
}
