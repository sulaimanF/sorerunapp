import { Suspense } from "react";
import { LoginForm } from "@/features/auth/components/login-form";
import { LoadingPanel } from "@/components/shared/feedback";
export const metadata = { title: "Masuk" };
export default function Page() {
  return (
    <Suspense fallback={<LoadingPanel />}>
      <LoginForm />
    </Suspense>
  );
}
