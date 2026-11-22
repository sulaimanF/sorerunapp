import type { ReactNode } from "react";
import { AlertCircle } from "lucide-react";
import { cn } from "@/lib/utils";
import { Spinner } from "@/components/ui/spinner";

export function Alert({
  children,
  tone = "error",
}: {
  children: ReactNode;
  tone?: "error" | "info" | "success" | "warning";
}) {
  return (
    <div
      className={cn("alert", `alert-${tone}`)}
      role={tone === "error" ? "alert" : "status"}
    >
      <AlertCircle aria-hidden size={20} />
      <div>{children}</div>
    </div>
  );
}
export function LoadingPanel({
  children = "Memeriksa akun…",
  compact = false,
}: {
  children?: ReactNode;
  compact?: boolean;
}) {
  return (
    <div className={compact ? "loading-inline" : "loading-panel"} role="status" aria-busy="true">
      <Spinner size={compact ? 18 : 24} />
      <p>{children}</p>
    </div>
  );
}
