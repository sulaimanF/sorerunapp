"use client";

import { Button } from "@/components/ui/button";

export function ReloadLanding() {
  return <Button variant="outline" onClick={() => window.location.reload()}>Muat ulang halaman</Button>;
}
