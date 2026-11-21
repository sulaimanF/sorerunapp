"use client";

import { useEffect, type ReactNode } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { Alert, LoadingPanel } from "@/components/shared/feedback";
import { Button } from "@/components/ui/button";
import { MemberShell } from "@/features/auth/components/member-shell";
import { canManageAdministration, canProvisionAdmins } from "@/features/auth/permissions";
import { useSession } from "@/providers/session-provider";

export function AdminShell({ children, permission = "manage" }: { children: ReactNode; permission?: "manage" | "provision" }) {
  const auth = useSession();
  const router = useRouter();
  const pathname = usePathname();
  useEffect(() => {
    if (pathname.startsWith("/admin/") && !auth.loading && !auth.error && !auth.session && auth.transition === "idle") router.replace("/login");
  }, [pathname, auth.loading, auth.error, auth.session, auth.transition, router]);
  if (auth.error) return <main id="main-content" className="recovery-panel stack-medium"><Alert>{auth.error.message}</Alert><Button onClick={() => void auth.refresh().catch(() => undefined)}>Periksa Sesi</Button><Link className="text-link" href="/login">Ke Halaman Login</Link></main>;
  if (auth.loading || !auth.session || auth.transition !== "idle") return <LoadingPanel />;
  if (!(permission === "provision" ? canProvisionAdmins(auth.session) : canManageAdministration(auth.session))) return <main id="main-content" className="recovery-panel stack-medium"><h1 className="page-heading">Akses terbatas</h1><p>{permission === "provision" ? "Halaman ini hanya tersedia untuk Super Admin." : "Halaman ini hanya tersedia untuk admin."}</p><Link className="text-link" href="/beranda">Kembali ke Beranda</Link></main>;
  return <MemberShell mainClassName="src-content-main src-content-admin">{children}</MemberShell>;
}
