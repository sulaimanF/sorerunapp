"use client";
import { useEffect, type ReactNode } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { CreditCard, Home, PanelsTopLeft, UserRound, UserPlus } from "lucide-react";
import { Brand } from "@/components/shared/brand";
import { Alert, LoadingPanel } from "@/components/shared/feedback";
import { Button } from "@/components/ui/button";
import { useSession } from "@/providers/session-provider";
import { AUTH_NAVIGATION } from "../navigation";
import { LogoutConfirmation } from "./logout-confirmation";
import { canManageAdministration, canProvisionAdmins } from "../permissions";

const nav = [
  { href: "/beranda", label: "Beranda", Icon: Home },
  { href: "/kartu", label: "Kartu", Icon: CreditCard },
  { href: "/profil", label: "Profil", Icon: UserRound },
];
export function MemberShell({ children, mainClassName = "member-main" }: { children: ReactNode; mainClassName?: string }) {
  const auth = useSession();
  const router = useRouter();
  const path = usePathname();
  const completeLogin = auth.completeLogin;
  useEffect(() => {
    if (path === AUTH_NAVIGATION.afterLogin && auth.transition === "login-redirect") completeLogin();
  }, [path, auth.transition, completeLogin]);
  useEffect(() => {
    if (
      nav.some(item => item.href === path) &&
      !auth.loading &&
      !auth.error &&
      !auth.session &&
      auth.transition === "idle"
    )
      router.replace(AUTH_NAVIGATION.login);
  }, [path, auth.loading, auth.error, auth.session, auth.transition, router]);
  if (auth.error)
    return (
      <main className="recovery-panel stack-medium">
        <h1 className="page-heading">Akun belum dapat dibuka</h1>
        <Alert>{auth.error.message}</Alert>
        <Button
          onClick={() => {
            void auth.refresh().catch(() => undefined);
          }}
        >
          Coba Lagi
        </Button>
        <Button variant="outline" asChild>
          <Link href={AUTH_NAVIGATION.login}>Ke Halaman Login</Link>
        </Button>
      </main>
    );
  if (auth.loading || !auth.session || auth.transition !== "idle")
    return <LoadingPanel />;
  const isAdmin = canManageAdministration(auth.session);
  const isSuperAdmin = canProvisionAdmins(auth.session);
  const hasMembership = auth.session.membership !== null;
  const navigation = nav.filter(item => item.href !== "/kartu" || hasMembership);
  if (isAdmin) navigation.push({ href: "/admin/landing-page", label: "Kelola Landing Page", Icon: PanelsTopLeft });
  if (isSuperAdmin) navigation.push({ href: "/admin/accounts/new", label: "Buat Admin", Icon: UserPlus });
  const isCurrent = (href: string) => path === href || (href === "/admin/accounts/new" && (path === "/admin/accounts" || path.startsWith("/admin/accounts/"))) || (href.startsWith("/admin/") && path.startsWith(`${href}/`));
  return (
    <div className="member-shell" data-admin={isAdmin ? "true" : undefined} data-super-admin={isSuperAdmin ? "true" : undefined}>
      <header className="member-header">
        <div className="header-inner">
          <Brand />
          <nav aria-label="Navigasi utama" className="desktop-nav">
            {navigation.map(({ href, label, Icon }) => (
              <Link
                key={href}
                href={href}
                aria-current={isCurrent(href) ? "page" : undefined}
              >
                <Icon size={18} aria-hidden />
                {label}
              </Link>
            ))}
          </nav>
          <div className="account-menu">
            <span>{auth.session.user.full_name}</span>
            <LogoutConfirmation />
          </div>
        </div>
      </header>
      <main className={mainClassName} id="main-content">
        {children}
      </main>
      <nav aria-label="Navigasi anggota" className="mobile-nav">
        {navigation.map(({ href, label, Icon }) => (
          <Link
            key={href}
            href={href}
            aria-current={isCurrent(href) ? "page" : undefined}
          >
            <Icon size={20} aria-hidden />
            {label}
          </Link>
        ))}
      </nav>
    </div>
  );
}
