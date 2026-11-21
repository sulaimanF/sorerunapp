import { z } from "zod";
import { emailChangeProofSchema } from "./schemas";

export type EmailChangeProof = z.infer<typeof emailChangeProofSchema>;

export function emailChangeFromFragment(fragment: string): EmailChangeProof | null {
  if (!fragment.startsWith("#") || fragment.length > 1600) return null;
  const params = new URLSearchParams(fragment.slice(1));
  const keys = [...params.keys()];
  if (keys.length !== 3 || new Set(keys).size !== 3 || !keys.every(key => ["request_id", "email", "token"].includes(key))) return null;
  const result = emailChangeProofSchema.safeParse(Object.fromEntries(params));
  return result.success ? result.data : null;
}

// Tab memory only. Effect cleanup also clears proof when Next preserves a hidden route.
let intent: EmailChangeProof | null = null;
let ready = false;
let lifecycle = 0;
const listeners = new Set<() => void>();
export const emailChangeIntent = {
  get: () => intent,
  server: () => null,
  ready: () => ready,
  serverReady: () => false,
  subscribe(listener: () => void) {
    listeners.add(listener);
    return () => { listeners.delete(listener); };
  },
  set(value: EmailChangeProof | null) {
    ready = true;
    intent = value;
    listeners.forEach(listener => listener());
  },
  retain() {
    const currentLifecycle = ++lifecycle;
    return () => {
      queueMicrotask(() => {
        if (lifecycle === currentLifecycle) emailChangeIntent.set(null);
      });
    };
  },
};
