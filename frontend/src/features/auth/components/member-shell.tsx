"use client";
import { useEffect, type ReactNode } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { CreditCard, Home, UserRound } from "lucide-react";
import { Brand } from "@/components/shared/brand";
import { Alert, LoadingPanel } from "@/components/shared/feedback";
import { Button } from "@/components/ui/button";
import { useSession } from "@/providers/session-provider";
import { AUTH_NAVIGATION } from "../navigation";
import { LogoutConfirmation } from "./logout-confirmation";

const nav = [
  { href: "/beranda", label: "Beranda", Icon: Home },
  { href: "/kartu", label: "Kartu", Icon: CreditCard },
  { href: "/profil", label: "Profil", Icon: UserRound },
];
export function MemberShell({ children }: { children: ReactNode }) {
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
  return (
    <div className="member-shell">
      <header className="member-header">
        <div className="header-inner">
          <Brand />
          <nav aria-label="Navigasi utama" className="desktop-nav">
            {nav.map(({ href, label, Icon }) => (
              <Link
                key={href}
                href={href}
                aria-current={path === href ? "page" : undefined}
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
      <main className="member-main" id="main-content">
        {children}
      </main>
      <nav aria-label="Navigasi anggota" className="mobile-nav">
        {nav.map(({ href, label, Icon }) => (
          <Link
            key={href}
            href={href}
            aria-current={path === href ? "page" : undefined}
          >
            <Icon size={20} aria-hidden />
            {label}
          </Link>
        ))}
      </nav>
    </div>
  );
}
