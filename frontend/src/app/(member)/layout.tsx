import type { ReactNode } from "react";

export default function MemberLayout({ children }: { children: ReactNode }) {
  // Keep route segments in the render tree; each page applies the session guard.
  return children;
}
