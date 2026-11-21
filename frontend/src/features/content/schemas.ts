import { z } from "zod";

export const sectionKeys = ["about", "activities", "gallery", "faq", "contact"] as const;
export type SectionKey = (typeof sectionKeys)[number];
const text = (max: number) => z.string().max(max, `Maksimal ${max} karakter.`).refine(value => !/[<>\u0000-\u0008\u000b\u000c\u000e-\u001f\u202a-\u202e\u2066-\u2069]/u.test(value), "Gunakan teks biasa tanpa markup atau karakter kontrol.");
const imageSchema = z.object({ media_id: z.string().uuid(), alt: text(180), caption: text(300) }).strict();
const itemsSchema = z.object({ title: text(100), description: text(1000) }).strict();
const storySchema = z.object({ title: text(100), description: text(2000), items: z.array(itemsSchema).max(12) }).strict();
export const landingDocumentSchema = z.object({
  schema_version: z.literal(1),
  header: z.object({ login_label: text(60), nav_labels: z.object({ about: text(40), activities: text(40), gallery: text(40), faq: text(40), contact: text(40) }).strict() }).strict(),
  hero: z.object({ eyebrow: text(80), title: text(150), description: text(1000), login_label: text(60), image: imageSchema.nullable() }).strict(),
  about: storySchema,
  activities: storySchema,
  gallery: z.object({ title: text(100), items: z.array(imageSchema).max(24) }).strict(),
  faq: z.object({ title: text(100), items: z.array(z.object({ question: text(200), answer: text(2000) }).strict()).max(20) }).strict(),
  contact: z.object({ title: text(100), description: text(1000), email: z.union([z.literal(""), z.string().email("Masukkan alamat email yang valid.").max(254, "Email maksimal 254 karakter.")]), phone: z.union([z.literal(""), z.string().max(32, "Telepon maksimal 32 karakter.").regex(/^\+?[0-9 ()-]{7,32}$/, "Masukkan nomor telepon yang valid.")]), socials: z.array(z.object({ label: text(60), url: z.union([z.literal(""), z.string().url("Masukkan URL yang valid.").max(500, "URL maksimal 500 karakter.").refine(value => {
    try {
      const url = new URL(value);
      return url.protocol === "https:" && !url.username && !url.password && !/[\s<>\u0000-\u001f]/.test(value);
    } catch { return false; }
  }, "Gunakan URL HTTPS tanpa credential.")]) }).strict()).max(8) }).strict(),
  footer: z.object({ text: text(500) }).strict(),
  seo: z.object({ title: text(70), description: text(180), image: imageSchema.nullable() }).strict(),
  sections: z.array(z.object({ key: z.enum(sectionKeys), visible: z.boolean() }).strict()).length(5).refine(value => new Set(value.map(item => item.key)).size === 5, "Section tidak boleh berulang."),
}).strict();
export type LandingDocument = z.infer<typeof landingDocumentSchema>;
export type LandingImage = z.infer<typeof imageSchema>;
export const landingMediaSchema = z.object({ id: z.string().uuid(), url: z.string().url(), mime: z.literal("image/webp"), width: z.number().int().positive(), height: z.number().int().positive() }).strict();
export type LandingMedia = z.infer<typeof landingMediaSchema>;
export const landingDraftSchema = z.object({ version: z.number().int().nonnegative(), content: landingDocumentSchema, published_version: z.number().int().nonnegative().nullable(), published_at: z.string().datetime({ offset: true }).nullable(), media: z.array(landingMediaSchema) }).strict();
export type LandingDraft = z.infer<typeof landingDraftSchema>;
export const landingPublicationSchema = z.object({ publication: z.object({ version: z.number().int().nonnegative(), published_at: z.string().datetime({ offset: true }), content: landingDocumentSchema, media: z.array(landingMediaSchema) }).strict().nullable() }).strict();
export type LandingPublication = z.infer<typeof landingPublicationSchema>["publication"];

export function documentMediaIds(content: LandingDocument) {
  return new Set([
    ...content.gallery.items.map(item => item.media_id),
    ...(content.hero.image ? [content.hero.image.media_id] : []),
    ...(content.seo.image ? [content.seo.image.media_id] : []),
  ]);
}

export function visibleSections(content: LandingDocument) {
  return content.sections.filter(section => {
    if (!section.visible || !content[section.key].title.trim()) return false;
    switch (section.key) {
      case "about": case "activities": return !!content[section.key].description.trim() || content[section.key].items.length > 0;
      case "gallery": case "faq": return content[section.key].items.length > 0;
      case "contact": return !!(content.contact.description || content.contact.email || content.contact.phone || content.contact.socials.length);
    }
  });
}
