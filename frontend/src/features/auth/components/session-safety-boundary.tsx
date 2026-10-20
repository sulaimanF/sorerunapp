"use client";
import type { ReactNode } from "react";
import { useSession } from "@/providers/session-provider";
import { Button } from "@/components/ui/button";
import { Alert, LoadingPanel } from "@/components/shared/feedback";

export function SessionSafetyBoundary({ children }: { children: ReactNode }) {
  const auth = useSession();
  if (auth.transition === "logout")
    return <LoadingPanel>Mengakhiri sesi…</LoadingPanel>;
  if (auth.transition === "logout-unknown")
    return (
      <main className="recovery-panel stack-medium">
        <h1 className="page-heading">Status keluar belum terkonfirmasi</h1>
        <Alert>
          Data privat sudah disembunyikan. Periksa koneksi, lalu coba keluar
          kembali atau periksa sesi.
        </Alert>
        <Button
          onClick={() => {
            void auth.logout().catch(() => undefined);
          }}
        >
          Coba Keluar Lagi
        </Button>
        <Button
          variant="outline"
          onClick={() => {
            void auth.refresh().catch(() => undefined);
          }}
        >
          Periksa Sesi
        </Button>
      </main>
    );
  return children;
}
