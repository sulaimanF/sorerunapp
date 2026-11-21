import { LandingPreview } from "@/features/content/components/landing-preview";
import { AdminShell } from "@/features/content/components/admin-shell";

export const metadata = { title: "Preview Draft Landing", robots: { index: false, follow: false } };

export default function Page() {
  return <AdminShell><LandingPreview /></AdminShell>;
}
