"use client";
import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { Mail } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Alert } from "@/components/shared/feedback";
import { normalizeApiError } from "@/lib/errors";
import { useSession } from "@/providers/session-provider";
import { resendVerification } from "../services";
import { useCountdown } from "../hooks/use-countdown";

export function ResendButton() {
  const [message, setMessage] = useState("");
  const countdown = useCountdown();
  const { refresh } = useSession();
  const mutation = useMutation({
    mutationFn: resendVerification,
    onSuccess() {
      setMessage(
        "Permintaan pengiriman ulang diproses. Periksa kotak masuk dan folder spam.",
      );
      countdown.start(60);
    },
    onError(error) {
      const problem = normalizeApiError(error);
      setMessage("");
      if (problem.status === 429) countdown.start(problem.retryAfter || 60);
      if (problem.status === 401 || problem.code === "ACCOUNT_BLOCKED")
        void refresh().catch(() => undefined);
    },
  });
  return (
    <div className="stack-small">
      <Button
        type="button"
        variant="outline"
        disabled={mutation.isPending || countdown.remaining > 0}
        onClick={() => mutation.mutate()}
      >
        <Mail size={18} aria-hidden />
        {mutation.isPending
          ? "Memproses…"
          : countdown.remaining
            ? `Kirim ulang dalam ${countdown.remaining} detik`
            : "Kirim Ulang Email"}
      </Button>
      {message && <Alert tone="info">{message}</Alert>}
      {mutation.error && (
        <Alert>{normalizeApiError(mutation.error).message}</Alert>
      )}
      {countdown.remaining > 0 && (
        <p className="supporting">
          Tunggu hingga jeda selesai sebelum mengirim ulang.
        </p>
      )}
    </div>
  );
}
