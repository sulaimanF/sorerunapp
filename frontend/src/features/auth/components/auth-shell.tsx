import type { ReactNode } from "react";
import { ArrowUpRight } from "lucide-react";
import { Brand } from "@/components/shared/brand";

export function AuthShell({ children }: { children: ReactNode }) {
  return (
    <div className="auth-shell">
      <aside className="auth-brand">
        <Brand inverse />
        <div className="auth-story">
          <span className="eyebrow">Bertemu di langkah yang sama</span>
          <h1>
            Sore yang baik
            <br />
            dimulai bersama.
          </h1>
          <p>
            Bergerak, bertemu teman, dan menikmati setiap langkah bersama Sore
            Running Club.
          </p>
          <div className="brand-note">
            <ArrowUpRight aria-hidden size={24} />
            <span>
              Satu komunitas.
              <br />
              <strong>Banyak cerita.</strong>
            </span>
          </div>
        </div>
        <p className="auth-brand-footer">Sore Running Club · SRC</p>
        <div className="track-decoration" aria-hidden />
      </aside>
      <div className="auth-content">
        <main id="main-content" className="auth-form-wrap">
          {children}
        </main>
        <footer className="auth-footer">
          sorerunapp · Selangkah lebih dekat.
        </footer>
      </div>
    </div>
  );
}
