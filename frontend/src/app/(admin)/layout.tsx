import type { ReactNode } from "react";

export const metadata = { robots: { index: false, follow: false } };

export default function AdminLayout({ children }: { children: ReactNode }) {
  // Keep page segments rendered so Next can validate their loading boundaries.
  return children;
}
