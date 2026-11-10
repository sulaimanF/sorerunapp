import { ResetPasswordForm } from "@/features/auth/components/reset-password-form";

export const metadata = { title: "Reset Password", robots: { index: false, follow: false } };
export default function Page() { return <ResetPasswordForm />; }
