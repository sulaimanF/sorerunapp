import type { ReactNode } from "react";
import { AuthShell } from "@/features/auth/components/auth-shell";
export default function PublicLayout({ children }: { children: ReactNode }) {
  return <AuthShell>{children}</AuthShell>;
}
