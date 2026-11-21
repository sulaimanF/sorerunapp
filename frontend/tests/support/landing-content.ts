import type { LandingDocument } from "../../src/features/content/schemas";

export function landingFixtureContent(): LandingDocument {
  return {
    schema_version: 1,
    header: { login_label: "Masuk ke Aplikasi", nav_labels: { about: "Tentang", activities: "Kegiatan", gallery: "Galeri", faq: "FAQ", contact: "Kontak" } },
    hero: { eyebrow: "Konten sintetis", title: "Komunitas untuk pengujian", description: "Bukan konten resmi SRC.", login_label: "Masuk ke Aplikasi", image: null },
    about: { title: "Tentang pengujian", description: "Cerita sintetis.", items: [] },
    activities: { title: "", description: "", items: [] }, gallery: { title: "", items: [] },
    faq: { title: "Pertanyaan pengujian", items: [{ question: "Bagaimana membuka aplikasi?", answer: "Gunakan tombol masuk." }] },
    contact: { title: "", description: "", email: "", phone: "", socials: [] },
    footer: { text: "Footer sintetis" }, seo: { title: "SEO landing sintetis", description: "Deskripsi SEO sintetis.", image: null },
    sections: [{ key: "about", visible: true }, { key: "activities", visible: true }, { key: "gallery", visible: true }, { key: "faq", visible: true }, { key: "contact", visible: true }],
  };
}
