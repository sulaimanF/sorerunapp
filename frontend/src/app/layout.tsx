import type { Metadata } from "next";
import type { ReactNode } from "react";
import localFont from "next/font/local";
import { AppProviders } from "@/providers/app-providers";
import "./globals.css";
const jakarta = localFont({
  src: "../assets/fonts/PlusJakartaSans-Variable.ttf",
  variable: "--font-jakarta",
  weight: "200 800",
  style: "normal",
  display: "swap",
});
export const metadata: Metadata = {
  title: {
    default: "sorerunapp — Sore Running Club",
    template: "%s | sorerunapp",
  },
  description:
    "Keanggotaan Sore Running Club. Daftar, verifikasi email, dan akses kartu anggota Anda.",
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};
export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="id" className={jakarta.variable} data-scroll-behavior="smooth">
      <body>
        <a href="#main-content" className="skip-link">
          Lewati ke konten utama
        </a>
        <AppProviders>{children}</AppProviders>
      </body>
    </html>
  );
}
