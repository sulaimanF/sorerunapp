"use client";

import { useRef, useState } from "react";
import * as Dialog from "@radix-ui/react-dialog";
import { LogOut } from "lucide-react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { useSession } from "@/providers/session-provider";
import { AUTH_NAVIGATION } from "../navigation";

export function LogoutConfirmation() {
  const auth = useSession();
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState(false);
  const submitting = useRef(false);
  const cancelButton = useRef<HTMLButtonElement>(null);

  async function confirmLogout() {
    if (submitting.current) return;
    submitting.current = true;
    setPending(true);
    setOpen(false);
    try {
      await auth.logout();
      router.replace(AUTH_NAVIGATION.login);
    } catch {
      // The existing session boundary handles an unknown logout outcome.
    } finally {
      submitting.current = false;
      setPending(false);
    }
  }

  return (
    <Dialog.Root
      open={open}
      onOpenChange={(nextOpen) => {
        if (!submitting.current) setOpen(nextOpen);
      }}
    >
      <Dialog.Trigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          aria-label="Keluar dari akun"
          disabled={pending}
        >
          <LogOut size={20} aria-hidden />
        </Button>
      </Dialog.Trigger>
      <Dialog.Portal>
        <Dialog.Overlay className="dialog-overlay" />
        <Dialog.Content
          className="dialog-panel"
          onOpenAutoFocus={(event) => {
            event.preventDefault();
            cancelButton.current?.focus();
          }}
        >
          <Dialog.Title className="text-heading-3">
            Keluar dari akun?
          </Dialog.Title>
          <Dialog.Description className="supporting">
            Apakah Anda ingin logout dari sorerunapp?
          </Dialog.Description>
          <div className="logout-confirmation-actions">
            <Dialog.Close asChild>
              <Button
                ref={cancelButton}
                type="button"
                variant="outline"
                disabled={pending}
              >
                Tidak
              </Button>
            </Dialog.Close>
            <Button
              type="button"
              disabled={pending}
              onClick={() => void confirmLogout()}
            >
              {pending ? "Mengakhiri sesi…" : "Ya, Keluar"}
            </Button>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
