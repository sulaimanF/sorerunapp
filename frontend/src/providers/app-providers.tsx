"use client";
import type { ReactNode } from "react";
import { QueryClientProvider } from "@tanstack/react-query";
import { getQueryClient } from "@/lib/query-client";
import { SessionSafetyBoundary } from "@/features/auth/components/session-safety-boundary";
import { SessionProvider } from "./session-provider";

export function AppProviders({ children }: { children: ReactNode }) {
  return (
    <QueryClientProvider client={getQueryClient()}>
      <SessionProvider>
        <SessionSafetyBoundary>{children}</SessionSafetyBoundary>
      </SessionProvider>
    </QueryClientProvider>
  );
}
