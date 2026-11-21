import { invitationProofSchema, type AdminInvitationProof } from "./schemas";

export function adminInvitationFromFragment(fragment: string): AdminInvitationProof | null {
  if (!fragment.startsWith("#") || fragment.length > 1600) return null;
  const params = new URLSearchParams(fragment.slice(1));
  const keys = [...params.keys()];
  if (keys.length !== 3 || new Set(keys).size !== 3 || !keys.every(key => ["invitation_id", "email", "token"].includes(key))) return null;
  const parsed = invitationProofSchema.safeParse(Object.fromEntries(params));
  return parsed.success ? parsed.data : null;
}

function createInvitationIntent() {
  let proof: AdminInvitationProof | null = null;
  let ready = false;
  let lifecycle = 0;
  const listeners = new Set<() => void>();
  const intent = {
    get: () => proof,
    server: () => null,
    ready: () => ready,
    serverReady: () => false,
    subscribe(listener: () => void) {
      listeners.add(listener);
      return () => { listeners.delete(listener); };
    },
    set(value: AdminInvitationProof | null) {
      proof = value;
      ready = true;
      listeners.forEach(listener => listener());
    },
    retain() {
      const current = ++lifecycle;
      return () => { queueMicrotask(() => {
        if (current === lifecycle) intent.set(null);
      }); };
    },
  };
  return intent;
}

export const adminInvitationIntent = createInvitationIntent();
export const firstActivationIntent = createInvitationIntent();

