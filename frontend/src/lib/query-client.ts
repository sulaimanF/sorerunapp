import { QueryClient, QueryCache } from "@tanstack/react-query";
import { ApiError } from "./errors";

export const sessionKey = ["auth", "session"] as const;

export async function clearPrivateQueries(client: QueryClient) {
  await client.cancelQueries();
  client.removeQueries({ predicate: (query) => query.queryKey[0] !== "auth" });
  client.setQueryData(sessionKey, null);
}

export function createQueryClient(): QueryClient {
  const client: QueryClient = new QueryClient({
    queryCache: new QueryCache({
      onError(error, query) {
        if (
          query.meta?.requiresSession &&
          error instanceof ApiError &&
          (error.status === 401 || error.code === "ACCOUNT_BLOCKED")
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
