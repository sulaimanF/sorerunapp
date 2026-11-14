import { QueryClient, QueryCache } from "@tanstack/react-query";
import { isSessionRejection } from "./errors";

export const sessionKey = ["auth", "session"] as const;

export async function clearPrivateQueries(client: QueryClient, expectedUserId?: number) {
  if (expectedUserId !== undefined) {
    const current = client.getQueryData<{ user: { id: number } } | null>(sessionKey);
    if (current && current.user.id !== expectedUserId) return false;
  }
  await client.cancelQueries();
  if (expectedUserId !== undefined) {
    const current = client.getQueryData<{ user: { id: number } } | null>(sessionKey);
    if (current && current.user.id !== expectedUserId) return false;
  }
  client.removeQueries({ predicate: (query) => query.queryKey[0] !== "auth" });
  client.setQueryData(sessionKey, null);
  return true;
}

export function createQueryClient(): QueryClient {
  const client: QueryClient = new QueryClient({
    queryCache: new QueryCache({
      onError(error, query) {
        if (
          query.meta?.requiresSession &&
          isSessionRejection(error)
        ) {
          void clearPrivateQueries(client);
        }
      },
    }),
    defaultOptions: {
      queries: {
        staleTime: 0,
        gcTime: 0,
        retry: false,
        refetchOnWindowFocus: true,
      },
      mutations: { retry: false, gcTime: 0 },
    },
  });
  return client;
}
let browserQueryClient: QueryClient | undefined;
export function getQueryClient(): QueryClient {
  if (typeof window === "undefined") return createQueryClient();
  browserQueryClient ??= createQueryClient();
  return browserQueryClient;
}
