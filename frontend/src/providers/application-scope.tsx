"use client";

import { Suspense, type ReactNode } from "react";
import { usePathname } from "next/navigation";
import { AppProviders } from "./app-providers";
import { LoadingPanel } from "@/components/shared/feedback";

export function ApplicationScope({ children }: { children: ReactNode }) {
  // Dynamic admin URLs have no pathname during the static shell prerender.
  return <Suspense fallback={<LoadingPanel>Memuat halaman…</LoadingPanel>}>
    <RouteScope>{children}</RouteScope>
  </Suspense>;
}

function RouteScope({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  // One provider across auth/member/admin keeps transitions consistent under Activity.
  // The public landing mounts no session observer or private recovery boundary.
  return pathname === "/" ? children : <AppProviders>{children}</AppProviders>;
}
