"use client";

import Image from "next/image";
import { LoadingPanel } from "@/components/shared/feedback";
import { useEffect, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { api } from "@/lib/api";
import { isSessionRejection, normalizeApiError } from "@/lib/errors";
import { clearPrivateQueries, sessionKey } from "@/lib/query-client";
import { useSession } from "@/providers/session-provider";
import { landingMediaUrl } from "../services";

export function PrivateLandingImage({ id, alt, width, height }: { id: string; alt: string; width: number; height: number }) {
  const { session } = useSession();
  const client = useQueryClient();
  const actor = session?.user.id;
  const [result, setResult] = useState<{ id: string; actor: number; url: string } | null>(null);
  const [failed, setFailed] = useState("");
  useEffect(() => {
    if (!actor) return;
    const controller = new AbortController();
    let url: string | undefined;
    void api.get<Blob>(landingMediaUrl(id, true), { responseType: "blob", signal: controller.signal }).then(response => {
      if (controller.signal.aborted) return;
      url = URL.createObjectURL(response.data);
      setResult({ id, actor, url });
    }).catch(async error => {
      if (controller.signal.aborted) return;
      const parsed = normalizeApiError(error);
      if (isSessionRejection(parsed)) { await clearPrivateQueries(client, actor); await client.invalidateQueries({ queryKey: sessionKey }); }
      else if (parsed.code !== "REQUEST_CANCELLED") setFailed(`${actor}:${id}`);
    });
    return () => { controller.abort(); if (url) URL.revokeObjectURL(url); };
  }, [id, actor, client]);
  if (!result || result.id !== id || result.actor !== actor) return failed === `${actor}:${id}`
    ? <p className="supporting">Gambar belum dapat dimuat.</p>
    : <LoadingPanel compact>Memuat gambar privat…</LoadingPanel>;
  return <Image src={result.url} alt={alt} width={width} height={height} unoptimized />;
}
