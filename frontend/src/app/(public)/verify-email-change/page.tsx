import type { Metadata } from "next";
import { EmailChangeConfirmation } from "@/features/auth/components/email-change-confirmation";

export const metadata: Metadata = { title: "Konfirmasi Email Baru", robots: { index: false, follow: false }, referrer: "no-referrer" };
export default function Page() { return <EmailChangeConfirmation />; }
