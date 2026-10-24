import { CheckCircle2, Clock3, CirclePause, CircleMinus } from "lucide-react";
import type { MembershipStatus as Status } from "@/features/auth/types";
const variants = {
  active: { label: "Aktif", tone: "success", Icon: CheckCircle2 },
  pending_verification: {
    label: "Menunggu verifikasi",
    tone: "warning",
    Icon: Clock3,
  },
  suspended: { label: "Ditangguhkan", tone: "warning", Icon: CirclePause },
  ended: { label: "Keanggotaan berakhir", tone: "neutral", Icon: CircleMinus },
} as const;
export function MembershipStatus({ status }: { status: Status }) {
  const { label, tone, Icon } = variants[status];
  return (
    <span className={`status-badge status-${tone}`}>
      <Icon size={15} aria-hidden />
      {label}
    </span>
  );
}
