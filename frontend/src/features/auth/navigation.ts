const destinations = new Set([
  "/beranda",
  "/kartu",
  "/profil",
  "/verify-email",
]);
export function safeReturnPath(value: string | null | undefined): string {
  // A small allowlist also rejects protocol-relative, encoded, and nested redirect URLs.
  return value && destinations.has(value) ? value : "/beranda";
}
