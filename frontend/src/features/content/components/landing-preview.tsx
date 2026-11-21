"use client";
import { canManageAdministration } from "@/features/auth/permissions";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { useSession } from "@/providers/session-provider";
import { Alert, LoadingPanel } from "@/components/shared/feedback";
import { Button } from "@/components/ui/button";
import { normalizeApiError } from "@/lib/errors";
import { getLandingDraft } from "../services";
import { LandingView } from "./landing-view";

export function LandingPreview() {
  const { session } = useSession();
  const query = useQuery({ queryKey: ["content", "preview", session?.user.id], queryFn: ({ signal }) => getLandingDraft(signal, true), meta: { requiresSession: true }, enabled: canManageAdministration(session) });
  if (query.isPending) return <LoadingPanel />;
  if (query.error) return <div className="stack-medium"><Alert>{normalizeApiError(query.error).message}{Object.entries(normalizeApiError(query.error).fields).map(([field, messages]) => <p key={field}>{messages.join(" ")}</p>)}</Alert><Button onClick={() => void query.refetch()}>Periksa Preview Lagi</Button><Link className="text-link" href="/admin/landing-page">Kembali ke Editor</Link></div>;
  return <div className="stack-large"><aside className="card stack-small"><h1 className="text-heading-2">Preview privat — draft versi {query.data.version}</h1><p>Belum mengubah halaman publik. Data berasal dari draft tersimpan.</p><Link className="text-link" href="/admin/landing-page">Kembali ke Editor</Link></aside><LandingView content={query.data.content} media={query.data.media} privateMedia /></div>;
}
