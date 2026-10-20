import { MemberShell } from "@/features/auth/components/member-shell";
import { ProfileScreen } from "@/features/members/components/profile-screen";
export const metadata = { title: "Profil Anggota" };
export default function Page() {
  return (
    <MemberShell>
      <ProfileScreen />
    </MemberShell>
  );
}
