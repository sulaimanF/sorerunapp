import Link from "next/link";
import { Button } from "@/components/ui/button";

export function ResendButton() {
  return <Button variant="outline" asChild><Link href="/resend-verification">Kirim Ulang Email</Link></Button>;
}
