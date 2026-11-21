import { LandingEditorScreen } from "@/features/content/components/landing-editor";
import { AdminShell } from "@/features/content/components/admin-shell";

export const metadata = { title: "Kelola Landing Page" };

export default function Page() {
  return <AdminShell><LandingEditorScreen /></AdminShell>;
}
