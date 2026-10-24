export function verificationPath(
  rawUrl: string,
  origin: string,
): string | null {
  try {
    if (rawUrl.length > 1200) return null;
    const url = new URL(rawUrl);
    if (
      url.origin !== origin ||
      url.username ||
      url.password ||
      url.hash ||
      !/^\/api\/v1\/auth\/email-verification\/[1-9][0-9]*\/[a-f0-9]{40}$/.test(
        url.pathname,
      )
    )
      return null;
    const keys = [...url.searchParams.keys()];
    if (
      keys.length !== 3 ||
      new Set(keys).size !== 3 ||
      !keys.every((key) => ["expires", "v", "signature"].includes(key))
    )
      return null;
    if (
      !/^[0-9]{1,12}$/.test(url.searchParams.get("expires") ?? "") ||
      !/^[1-9][0-9]{0,9}$/.test(url.searchParams.get("v") ?? "") ||
      !/^[a-f0-9]{64}$/.test(url.searchParams.get("signature") ?? "")
    )
      return null;
    // Keep the original query order and encoding; changing them can invalidate the signature.
    return url.pathname + url.search;
  } catch {
    return null;
  }
}

export function intentFromFragment(
  fragment: string,
  origin: string,
): string | null {
  if (fragment.length > 1800 || !fragment.startsWith("#verification="))
    return null;
  try {
    return verificationPath(
      decodeURIComponent(fragment.slice("#verification=".length)),
      origin,
    );
  } catch {
    return null;
  }
}

// Per-tab memory only. No secret in storage, router query, Query keys, or server rendering.
let intent: string | null = null;
const listeners = new Set<() => void>();
export const verificationIntent = {
  get: () => intent,
  server: () => null,
  subscribe: (listener: () => void) => {
    listeners.add(listener);
    return () => {
      listeners.delete(listener);
    };
  },
  set(value: string | null) {
    intent = value;
    listeners.forEach((listener) => listener());
  },
};
