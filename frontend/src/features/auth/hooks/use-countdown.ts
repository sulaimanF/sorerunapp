"use client";
import { useEffect, useState } from "react";
export function useCountdown() {
  const [end, setEnd] = useState(0);
  const [remaining, setRemaining] = useState(0);
  useEffect(() => {
    if (!end) return;
    const interval = window.setInterval(
      () => setRemaining(Math.max(0, Math.ceil((end - Date.now()) / 1000))),
      500,
    );
    return () => window.clearInterval(interval);
  }, [end]);
  return {
    remaining,
    start(seconds: number) {
      const bounded = Math.min(3600, Math.max(1, seconds));
      setEnd(Date.now() + bounded * 1000);
      setRemaining(bounded);
    },
  };
}
