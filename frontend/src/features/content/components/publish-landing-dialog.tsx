"use client";

import { useEffect, useRef, useState } from "react";
import * as Dialog from "@radix-ui/react-dialog";
import { AlertCircle, BadgeCheck } from "lucide-react";
import { Button } from "@/components/ui/button";

export type PublishResult = {
  status: "success" | "error" | "unknown";
  message: string;
  fields: Record<string, string[]>;
};

export function PublishLandingDialog({ available, disabled, onPublish, onResultClose }: {
  available: boolean;
  disabled: boolean;
  onPublish: () => Promise<PublishResult | null>;
  onResultClose: (result: PublishResult) => boolean;
}) {
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState(false);
  const [result, setResult] = useState<PublishResult | null>(null);
  const running = useRef(false);
  const cancel = useRef<HTMLButtonElement>(null);
  const close = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (open && result) close.current?.focus();
  }, [open, result]);

  async function publish() {
    if (running.current || disabled) return;
    running.current = true;
    setPending(true);
    try {
      const outcome = await onPublish();
      if (outcome) setResult(outcome);
      else setOpen(false);
    } finally {
      running.current = false;
      setPending(false);
    }
  }

  const title = result?.status === "success" ? "Publikasi berhasil"
    : result?.status === "unknown" ? "Status publikasi belum pasti" : "Publikasi gagal";

  return <Dialog.Root open={open} onOpenChange={value => {
    if (running.current) return;
    if (value) setResult(null);
    setOpen(value);
  }}>
    {available && <Dialog.Trigger asChild><Button type="button" disabled={disabled}>Publikasikan</Button></Dialog.Trigger>}
    <Dialog.Portal>
      <Dialog.Overlay className="dialog-overlay" />
      <Dialog.Content className="dialog-panel"
        onOpenAutoFocus={event => { event.preventDefault(); cancel.current?.focus(); }}
        onCloseAutoFocus={event => { if (result && onResultClose(result)) event.preventDefault(); }}
        onEscapeKeyDown={event => { if (running.current) event.preventDefault(); }}
        onInteractOutside={event => { if (running.current) event.preventDefault(); }}>
        {result ? <>
          {result.status === "success" ? <BadgeCheck className="success-icon" aria-hidden size={32} /> : <AlertCircle className="text-destructive" aria-hidden size={32} />}
          <Dialog.Title className="text-heading-3">{title}</Dialog.Title>
          <Dialog.Description>{result.message}</Dialog.Description>
          {Object.keys(result.fields).length > 0 && <ul className="stack-small">
            {Object.entries(result.fields).map(([field, messages]) => <li key={field}>{messages.join(" ")}</li>)}
          </ul>}
          <div className="src-landing-actions"><Dialog.Close asChild><Button ref={close} type="button">Tutup</Button></Dialog.Close></div>
        </> : <>
          <Dialog.Title className="text-heading-3">Publikasikan landing page?</Dialog.Title>
          <Dialog.Description>Draft tersimpan akan terlihat oleh pengunjung. Periksa kembali isi dan hak penggunaan gambar.</Dialog.Description>
          <div className="src-landing-actions">
            <Button type="button" loading={pending} disabled={pending || disabled} onClick={() => void publish()}>{pending ? "Menerbitkan…" : "Ya, Publikasikan"}</Button>
            <Dialog.Close asChild><Button ref={cancel} type="button" variant="outline" disabled={pending}>Batal</Button></Dialog.Close>
          </div>
        </>}
      </Dialog.Content>
    </Dialog.Portal>
  </Dialog.Root>;
}
