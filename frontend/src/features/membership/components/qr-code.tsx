"use client";
import { useMemo } from "react";
import { createMembershipQr } from "../qr";
export function QrCode({ payload }: { payload: string }) {
  const qr = useMemo(() => createMembershipQr(payload), [payload]);
  return (
    <svg
      className="membership-qr"
      viewBox={`0 0 ${qr.total} ${qr.total}`}
      role="img"
      aria-label="QR keanggotaan untuk dipindai petugas"
      shapeRendering="crispEdges"
    >
      <rect width={qr.total} height={qr.total} className="qr-background" />
      <path d={qr.path} className="qr-foreground" />
    </svg>
  );
}
