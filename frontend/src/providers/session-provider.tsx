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
import { isSessionRejection, normalizeApiError, type ApiError } from "@/lib/errors";
import { clearPrivateQueries, sessionKey } from "@/lib/query-client";
import {
  currentMember,
  loginMember,
  logoutMember,
} from "@/features/auth/services";
import { verificationIntent } from "@/features/auth/verification-intent";
import { passwordResetIntent } from "@/features/auth/password-reset-intent";
import { emailChangeIntent } from "@/features/auth/email-change-intent";
import { adminInvitationIntent, firstActivationIntent } from "@/features/administration/invitation-intent";
import type { BrowserSession, LoginCredentials } from "@/features/auth/types";

type Transition = "idle" | "login" | "login-redirect" | "logout" | "refresh" | "logout-check" | "logout-unknown";
type SessionContextValue = {
  session: BrowserSession | null | undefined;
  loading: boolean;
  error: ApiError | null;
  recoveryError: ApiError | null;
  transition: Transition;
  refresh: () => Promise<BrowserSession | null>;
  login: (credentials: LoginCredentials) => Promise<void>;
  completeLogin: () => void;
  logout: () => Promise<void>;
  completeEmailChange: (actorId: number) => Promise<boolean>;
  emailChangeNotice: boolean;
  completePasswordChange: (actorId: number) => Promise<boolean>;
  passwordChangeNotice: boolean;
};
const SessionContext = createContext<SessionContextValue | null>(null);

export function SessionProvider({ children }: { children: ReactNode }) {
  const client = useQueryClient();
  const [transition, setTransition] = useState<Transition>("idle");
  const [recoveryError, setRecoveryError] = useState<ApiError | null>(null);
  const [emailChangeNotice, setEmailChangeNotice] = useState(false);
  const [passwordChangeNotice, setPasswordChangeNotice] = useState(false);
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
    const recoveringLogout = transition === "logout-unknown";
    setTransition(recoveringLogout ? "logout-check" : "refresh");
    setRecoveryError(null);
    await clearPrivateQueries(client);
    try {
      const data = await currentMember();
      client.setQueryData(sessionKey, data);
      setTransition("idle");
      if (data === null) announce();
      return data;
    } catch (error) {
      if (isSessionRejection(error)) {
        setTransition("idle");
        announce();
        return null;
      }
      // A failed lookup cannot settle an uncertain logout or expose private UI.
      setRecoveryError(normalizeApiError(error));
      setTransition(recoveringLogout ? "logout-unknown" : "idle");
      throw error;
    }
  }
  async function login(credentials: LoginCredentials) {
    setEmailChangeNotice(false);
    setPasswordChangeNotice(false);
    setTransition("login");
    try {
      await clearPrivateQueries(client);
      const data = await loginMember(credentials);
      client.setQueryData(sessionKey, data);
      setTransition("login-redirect");
      announce();
    } catch (error) {
      setTransition("idle");
      throw error;
    }
  }
  const completeLogin = useCallback(() => {
    setTransition(current => current === "login-redirect" ? "idle" : current);
  }, []);
  async function completeEmailChange(actorId: number) {
    if (!(await clearPrivateQueries(client, actorId))) return false;
    setEmailChangeNotice(true);
    setPasswordChangeNotice(false);
    verificationIntent.set(null);
    passwordResetIntent.set(null);
    emailChangeIntent.set(null);
    adminInvitationIntent.set(null);
    firstActivationIntent.set(null);
    setTransition("idle");
    announce();
    return true;
  }
  async function completePasswordChange(actorId: number) {
    if (!(await clearPrivateQueries(client, actorId))) return false;
    setPasswordChangeNotice(true);
    setEmailChangeNotice(false);
    verificationIntent.set(null);
    passwordResetIntent.set(null);
    emailChangeIntent.set(null);
    adminInvitationIntent.set(null);
    firstActivationIntent.set(null);
    setTransition("idle");
    announce();
    return true;
  }
  async function logout() {
    setTransition("logout");
    setRecoveryError(null);
    await clearPrivateQueries(client);
    verificationIntent.set(null);
    passwordResetIntent.set(null);
    emailChangeIntent.set(null);
    adminInvitationIntent.set(null);
    firstActivationIntent.set(null);
    try {
      await logoutMember();
      setTransition("idle");
      announce();
    } catch (error) {
      if (isSessionRejection(error)) {
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
        loading: query.isPending || (query.isFetching && !query.data),
        error: query.error ? normalizeApiError(query.error) : null,
        recoveryError,
        transition,
        refresh,
        login,
        completeLogin,
        logout,
        completeEmailChange,
        emailChangeNotice,
        completePasswordChange,
        passwordChangeNotice,
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
