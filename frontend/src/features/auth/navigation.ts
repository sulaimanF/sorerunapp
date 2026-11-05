// Authentication always uses fixed internal destinations, independent of previous pages.
export const AUTH_NAVIGATION = {
  login: "/login",
  afterLogin: "/beranda",
} as const;
