import { forgotPasswordSchema } from "./schemas";

export type PasswordResetIntent = { email: string; token: string };

export function passwordResetFromFragment(fragment: string): PasswordResetIntent | null {
  if (!fragment.startsWith("#") || fragment.length > 1500) return null;
  const params = new URLSearchParams(fragment.slice(1));
  const keys = [...params.keys()];
  if (keys.length !== 2 || new Set(keys).size !== 2 || !keys.every((key) => ["email", "token"].includes(key))) return null;
  const email = forgotPasswordSchema.safeParse({ email: params.get("email") });
  const token = params.get("token") ?? "";
  if (!email.success || !/^[a-f0-9]{64}$/.test(token)) return null;
  return { email: email.data.email, token };
}

// This proof is tab memory only, never a query key, browser storage value, or SSR prop.
let intent: PasswordResetIntent | null = null;
let ready = false;
let lifecycle = 0;
const listeners = new Set<() => void>();
export const passwordResetIntent = {
  get: () => intent,
  server: () => null,
  ready: () => ready,
  serverReady: () => false,
  subscribe(listener: () => void) {
    listeners.add(listener);
    return () => { listeners.delete(listener); };
  },
  set(value: PasswordResetIntent | null) {
    ready = true;
    intent = value;
    listeners.forEach((listener) => listener());
  },
  retain() {
    const currentLifecycle = ++lifecycle;
    return () => {
      // Strict Mode immediately reattaches effects. A hidden/departed route does not.
      queueMicrotask(() => {
        if (lifecycle === currentLifecycle) passwordResetIntent.set(null);
      });
    };
  },
};
