import Link from "next/link";
export function Brand({ inverse = false }: { inverse?: boolean }) {
  return (
    <Link
      href="/"
      className={`brand ${inverse ? "brand-inverse" : ""}`}
      aria-label="sorerunapp — Sore Running Club"
    >
      <span className="brand-mark">
        SRC
        <span aria-hidden className="brand-dot">
          .
        </span>
      </span>
      <span className="brand-name">
        Sore Running Club<span>sorerunapp</span>
      </span>
    </Link>
  );
}
