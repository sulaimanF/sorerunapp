import QRCode from "qrcode";
export const QR_MARGIN = 4;
export function createMembershipQr(payload: string) {
  if (!/^src-member:v1:[A-Za-z0-9_-]{43}$/.test(payload))
    throw new Error("Invalid membership credential format.");
  const { modules } = QRCode.create(payload, { errorCorrectionLevel: "M" });
  const total = modules.size + QR_MARGIN * 2;
  let path = "";
  for (let row = 0; row < modules.size; row++)
    for (let col = 0; col < modules.size; col++) {
      if (modules.get(row, col))
        path += `M${col + QR_MARGIN} ${row + QR_MARGIN}h1v1h-1z`;
    }
  return { total, path, modules };
}
