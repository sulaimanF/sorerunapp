"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, normalizeApiError } from "@/lib/errors";
import { clearPrivateQueries, sessionKey } from "@/lib/query-client";
import {
  currentMember,
  loginMember,
  logoutMember,
} from "@/features/auth/services";
import { verificationIntent } from "@/features/auth/verification-intent";
import type { BrowserSession, LoginCredentials } from "@/features/auth/types";

type Transition = "idle" | "login" | "logout" | "refresh" | "logout-unknown";
type SessionContextValue = {
  session: BrowserSession | null | undefined;
  loading: boolean;
  error: ApiError | null;
  transition: Transition;
  refresh: () => Promise<BrowserSession | null>;
  login: (credentials: LoginCredentials) => Promise<void>;
  logout: () => Promise<void>;
};
const SessionContext = createContext<SessionContextValue | null>(null);

export function SessionProvider({ children }: { children: ReactNode }) {
  const client = useQueryClient();
  const [transition, setTransition] = useState<Transition>("idle");
  const query = useQuery({
    queryKey: sessionKey,
    queryFn: ({ signal }) => currentMember(signal),
    enabled: transition === "idle",
    meta: { requiresSession: true },
  });
  const announce = useCallback(() => {
    if (typeof BroadcastChannel !== "undefined") {
      const channel = new BroadcastChannel("sorerunapp-session");
      channel.postMessage("session-changed");
      channel.close();
    }
  }, []);
  useEffect(() => {
    if (typeof BroadcastChannel === "undefined") return;
    const channel = new BroadcastChannel("sorerunapp-session");
    channel.onmessage = () => {
      void clearPrivateQueries(client).then(() =>
        client.invalidateQueries({ queryKey: sessionKey }),
      );
    };
    return () => channel.close();
  }, [client]);

  async function refresh() {
    // Hide private data while checking a possibly changed server identity.
    setTransition("refresh");
    await clearPrivateQueries(client);
    try {
      const data = await currentMember();
      client.setQueryData(sessionKey, data);
      return data;
    } finally {
      setTransition("idle");
    }
  }
  async function login(credentials: LoginCredentials) {
    setTransition("login");
    await clearPrivateQueries(client);
    try {
      const data = await loginMember(credentials);
      client.setQueryData(sessionKey, data);
      announce();
    } finally {
      setTransition("idle");
    }
  }
  async function logout() {
    setTransition("logout");
    await clearPrivateQueries(client);
    verificationIntent.set(null);
    try {
      await logoutMember();
      setTransition("idle");
      announce();
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        setTransition("idle");
        announce();
        return;
      }
      setTransition("logout-unknown");
      throw error;
    }
  }
  return (
    <SessionContext
      value={{
        session: query.data,
        loading: query.isPending,
        error: query.error ? normalizeApiError(query.error) : null,
        transition,
        refresh,
        login,
        logout,
      }}
    >
      {children}
    </SessionContext>
  );
}
export function useSession() {
  const context = useContext(SessionContext);
  if (!context) throw new Error("SessionProvider is required.");
  return context;
}
