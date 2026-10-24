import type { ReactNode } from "react";
import { AlertCircle, LoaderCircle } from "lucide-react";
import { cn } from "@/lib/utils";

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
}: {
  children?: ReactNode;
}) {
  return (
    <div className="loading-panel" role="status">
      <LoaderCircle className="spinner" aria-hidden size={24} />
      <p>{children}</p>
    </div>
  );
}
