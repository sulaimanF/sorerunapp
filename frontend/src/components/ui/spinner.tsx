import { LoaderCircle } from "lucide-react";

export function Spinner({ size = 18 }: { size?: number }) {
  return <LoaderCircle className="spinner" aria-hidden="true" size={size} />;
}
