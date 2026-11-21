"use client";
import { canManageAdministration } from "@/features/auth/permissions";

import { useRef, useState } from "react";
import Link from "next/link";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useFieldArray, useForm, useWatch, type FieldPath, type UseFormReturn } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { FormField } from "@/components/shared/form-field";
import { Alert, LoadingPanel } from "@/components/shared/feedback";
import { Button } from "@/components/ui/button";
import * as Dialog from "@radix-ui/react-dialog";
import { Input } from "@/components/ui/input";
import { useSession } from "@/providers/session-provider";
import { clearPrivateQueries, sessionKey } from "@/lib/query-client";
import type { BrowserSession } from "@/features/auth/types";
import { isSessionRejection, normalizeApiError, ApiError } from "@/lib/errors";
import { documentMediaIds, landingDocumentSchema, type LandingDocument, type LandingDraft, type LandingImage, type LandingMedia } from "../schemas";
import { deleteLandingMedia, getLandingDraft, getLandingMedia, publishLandingDraft, saveLandingDraft, uploadLandingMedia } from "../services";
import { PrivateLandingImage } from "./private-landing-image";
import { useCountdown } from "@/features/auth/hooks/use-countdown";

type EditorForm = UseFormReturn<LandingDocument>;

function TextField({ form, name, label, multiline = false, maxLength = 2000 }: { form: EditorForm; name: FieldPath<LandingDocument>; label: string; multiline?: boolean; maxLength?: number }) {
  const error = form.getFieldState(name, form.formState).error;
  const id = `content-${name.replaceAll(".", "-")}`;
  const props = { id, maxLength, "aria-invalid": !!error, "aria-describedby": error ? `${id}-error` : undefined, ...form.register(name) };
  return <FormField name={id} label={label} optional error={error?.message}>{multiline ? <textarea className="input" rows={4} {...props} /> : <Input {...props} />}</FormField>;
}

function StoryFields({ form, section }: { form: EditorForm; section: "about" | "activities" }) {
  const items = useFieldArray({ control: form.control, name: `${section}.items` });
  return <div className="stack-medium">
    <TextField form={form} name={`${section}.title`} label="Judul section" maxLength={100} />
    <TextField form={form} name={`${section}.description`} label="Deskripsi section" multiline />
    {items.fields.map((item, index) => <fieldset className="src-content-item stack-medium" key={item.id}><legend>Item {index + 1}</legend>
      <TextField form={form} name={`${section}.items.${index}.title`} label="Judul item" maxLength={100} />
      <TextField form={form} name={`${section}.items.${index}.description`} label="Deskripsi item" multiline maxLength={1000} />
      <Button type="button" variant="outline" onClick={() => items.remove(index)}>Hapus item {index + 1}</Button>
    </fieldset>)}
    <Button type="button" variant="outline" disabled={items.fields.length >= 12} onClick={() => items.append({ title: "", description: "" })}>Tambah item</Button>
  </div>;
}

function ImageFields({ value, onChange, media, label, form, prefix }: { value: LandingImage | null; onChange: (value: LandingImage | null) => void; media: LandingMedia[]; label: string; form: EditorForm; prefix: "hero.image" | "seo.image" | `gallery.items.${number}` }) {
  const id = `image-${label.replaceAll(" ", "-").toLowerCase()}`;
  const error = form.getFieldState(`${prefix}.alt`, form.formState).error;
  const captionError = form.getFieldState(`${prefix}.caption`, form.formState).error;
  return <fieldset className="stack-medium"><legend>{label}</legend>
    <label htmlFor={`${id}-file`}>Gambar</label>
    <select id={`${id}-file`} className="input" value={value?.media_id ?? ""} onChange={event => onChange(event.target.value ? { media_id: event.target.value, alt: "", caption: "" } : null)}>
      <option value="">Tanpa gambar</option>{media.map((file, index) => <option key={file.id} value={file.id}>Gambar {index + 1} · {file.width} × {file.height} · {file.id.slice(0, 8)}</option>)}
    </select>
    {value && <><label htmlFor={`${id}-alt`}>Alt text</label><Input id={`${id}-alt`} value={value.alt} maxLength={180} aria-invalid={!!error} aria-describedby={error ? `${id}-error` : undefined} {...form.register(`${prefix}.alt`)} />
      {error && <p id={`${id}-error`} role="alert" className="field-error">{error.message}</p>}
      <label htmlFor={`${id}-caption`}>Caption (opsional)</label><Input id={`${id}-caption`} value={value.caption} maxLength={300} aria-invalid={!!captionError} aria-describedby={captionError ? `${id}-caption-error` : undefined} {...form.register(`${prefix}.caption`)} />
      {captionError && <p id={`${id}-caption-error`} role="alert" className="field-error">{captionError.message}</p>}</>}
  </fieldset>;
}

export function LandingEditorScreen() {
  const { session } = useSession();
  const actor = session?.user.id;
  const [generation, setGeneration] = useState(0);
  const query = useQuery({ queryKey: ["content", "draft", actor], queryFn: ({ signal }) => getLandingDraft(signal), enabled: !!actor && canManageAdministration(session), meta: { requiresSession: true }, staleTime: Infinity, refetchOnWindowFocus: false });
  if (query.isPending) return <LoadingPanel />;
  if (query.error) return <div className="stack-medium"><Alert>{normalizeApiError(query.error).message}</Alert><Button onClick={() => void query.refetch()}>Muat Ulang Draft</Button></div>;
  return <LandingEditor key={`${actor}-${generation}`} draft={query.data} actor={actor ?? 0} reload={async () => { const result = await query.refetch(); if (result.data && !result.error) setGeneration(value => value + 1); }} />;
}

function LandingEditor({ draft, actor, reload }: { draft: LandingDraft; actor: number; reload: () => Promise<void> }) {
  const client = useQueryClient();
  const form = useForm<LandingDocument>({ resolver: zodResolver(landingDocumentSchema), defaultValues: draft.content });
  const sections = useFieldArray({ control: form.control, name: "sections" });
  const gallery = useFieldArray({ control: form.control, name: "gallery.items" });
  const faq = useFieldArray({ control: form.control, name: "faq.items" });
  const socials = useFieldArray({ control: form.control, name: "contact.socials" });
  const [extraMedia, setExtraMedia] = useState<LandingMedia[]>([]);
  const [problem, setProblem] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState("");
  const [working, setBusy] = useState(false);
  const countdown = useCountdown();
  const busy = working || countdown.remaining > 0;
  const inFlight = useRef(false);
  const fileInput = useRef<HTMLInputElement>(null);
  const values = useWatch({ control: form.control, compute: (content: LandingDocument) => content });
  const selectedIds = documentMediaIds(values);
  const media = [...new Map([...draft.media, ...extraMedia].map(file => [file.id, file])).values()];
  const current = () => client.getQueryData<BrowserSession | null>(sessionKey)?.user.id === actor;
  const unknown = !!problem && (problem.status === 0 || problem.status >= 500 || problem.code === "CONTENT_VERSION_CONFLICT");

  function isFieldPath(path: string): path is FieldPath<LandingDocument> {
    let value: unknown = form.getValues();
    for (const part of path.split(".")) {
      if (!value || typeof value !== "object" || !Object.hasOwn(value, part)) return false;
      value = Reflect.get(value, part);
    }
    return typeof value === "string" || typeof value === "boolean" || typeof value === "number";
  }

  async function perform(action: () => Promise<void>) {
    if (inFlight.current) return false;
    inFlight.current = true;
    setBusy(true); setProblem(null); setNotice("");
    try { await action(); return true; } catch (error) {
      if (current()) {
        const parsed = normalizeApiError(error);
        if (isSessionRejection(parsed)) {
          await clearPrivateQueries(client, actor);
          await client.invalidateQueries({ queryKey: sessionKey });
          return false;
        }
        setProblem(parsed);
        if (parsed.status === 429) countdown.start(parsed.retryAfter || 60);
        let first: FieldPath<LandingDocument> | undefined;
        for (const [field, messages] of Object.entries(parsed.fields)) {
          const path = field.replace(/^content\./, "");
          if (isFieldPath(path)) { form.setError(path, { message: messages[0] }); first ??= path; }
        }
        if (first) { const field = first; requestAnimationFrame(() => { if (current()) form.setFocus(field); }); }
        if (parsed.code === "FORBIDDEN") void client.invalidateQueries({ queryKey: sessionKey });
      }
      return false;
    }
    finally { inFlight.current = false; if (current()) setBusy(false); }
  }

  async function save(content: LandingDocument) {
    await perform(async () => {
      const result = await saveLandingDraft(draft.version, content);
      if (!current()) return;
      // Use the submitted snapshot; background reads must not replace ongoing edits.
      const references = documentMediaIds(content);
      client.setQueryData(["content", "draft", actor], { ...draft, version: result.version, content, media: media.filter(item => references.has(item.id)) });
      form.reset(content);
      setNotice(`Draft versi ${result.version} berhasil disimpan.`);
    });
  }

  async function publish() {
    return perform(async () => {
      const result = await publishLandingDraft(draft.version);
      if (!current()) return;
      client.setQueryData(["content", "draft", actor], { ...draft, published_version: result.version, published_at: result.published_at });
      setNotice(`Draft versi ${result.version} berhasil dipublikasikan.`);
    });
  }

  async function upload(file: File) {
    await perform(async () => {
      if (file.size > 5 * 1024 * 1024 || !["image/jpeg", "image/png", "image/webp"].includes(file.type)) throw new ApiError("Gunakan JPEG/PNG/WebP maksimal 5 MB.", 422, "VALIDATION_FAILED");
      const result = await uploadLandingMedia(file);
      if (!current()) return;
      setExtraMedia(previous => [...previous, result]);
      setNotice("Gambar tersimpan privat. Pilih gambar pada field yang sesuai dan isi alt text.");
      if (fileInput.current) fileInput.current.value = "";
    });
  }

  return <div className="stack-large">
    <header className="stack-small"><p className="eyebrow text-brand">Administrasi konten</p><h1 className="page-heading">Kelola Landing Page</h1><p>Satu draft bersama admin. Perubahan baru terlihat pengunjung setelah dipublikasikan.</p></header>
    <div className="card stack-small"><p>Draft versi <strong>{draft.version}</strong> · {draft.published_version === null ? "Belum ada publikasi" : `Publikasi versi ${draft.published_version}`}</p>
      {draft.published_at && <p className="supporting">Diterbitkan {new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Jakarta" }).format(new Date(draft.published_at))} WIB</p>}
      <p className="supporting">Field boleh belum lengkap saat disimpan sebagai draft. Publish memerlukan headline, deskripsi, label masuk, SEO, serta alt text gambar yang ditampilkan.</p>
    </div>
    {notice && <Alert tone="success">{notice}</Alert>}
    {countdown.remaining > 0 && <p role="status">Tunggu {countdown.remaining} detik sebelum mencoba kembali.</p>}
    {problem && <Alert>{problem.message}{Object.entries(problem.fields).map(([name, messages]) => <p key={name}>{messages.join(" ")}</p>)}{unknown && <p>Hasil atau versi perlu diperiksa. Simpan salinan perubahan Anda sebelum memuat ulang draft terbaru.</p>}</Alert>}
    {unknown && <Button type="button" variant="outline" disabled={busy} onClick={() => void reload()}>Muat Ulang Draft Terbaru</Button>}
    <form noValidate aria-label="Editor landing page" onSubmit={event => void form.handleSubmit(save)(event)} className="stack-large">
      {Object.keys(form.formState.errors).length > 0 && <Alert>Periksa field yang ditandai. Gunakan teks biasa dan URL HTTPS yang valid.</Alert>}
      <fieldset disabled={busy} className="stack-large">
        <legend className="sr-only">Konten landing page</legend>
        <section className="card stack-medium"><h2 className="text-heading-2">Header dan hero</h2>
          <TextField form={form} name="header.login_label" label="Label tombol masuk di header" maxLength={60} />
          <TextField form={form} name="hero.eyebrow" label="Teks pengantar" maxLength={80} />
          <TextField form={form} name="hero.title" label="Headline" maxLength={150} />
          <TextField form={form} name="hero.description" label="Deskripsi hero" multiline maxLength={1000} />
          <TextField form={form} name="hero.login_label" label="Label tombol masuk di hero" maxLength={60} />
          <p className="supporting">Tujuan kedua tombol tetap /login.</p>
          <ImageFields form={form} prefix="hero.image" label="Gambar hero" media={media} value={values.hero.image} onChange={value => form.setValue("hero.image", value, { shouldDirty: true })} />
        </section>
        <section className="card stack-medium"><h2 className="text-heading-2">Urutan dan tampilan section</h2>
          {sections.fields.map((section, index) => <div className="src-content-section-order" key={section.id}>
            <label className="checkbox-row"><input type="checkbox" {...form.register(`sections.${index}.visible`)} />Tampilkan {section.key}</label>
            <TextField form={form} name={`header.nav_labels.${section.key}`} label={`Label menu ${section.key}`} maxLength={40} />
            <div className="src-landing-actions"><Button type="button" variant="outline" disabled={index === 0} onClick={() => sections.move(index, index - 1)}>Naikkan {section.key}</Button><Button type="button" variant="outline" disabled={index === sections.fields.length - 1} onClick={() => sections.move(index, index + 1)}>Turunkan {section.key}</Button></div>
          </div>)}<p className="supporting">Section yang belum memiliki isi tidak ditampilkan, meskipun dicentang.</p>
        </section>
        <section className="card stack-medium"><h2 className="text-heading-2">Tentang komunitas</h2><StoryFields form={form} section="about" /></section>
        <section className="card stack-medium"><h2 className="text-heading-2">Kegiatan dan manfaat</h2><p className="supporting">Konten editorial; tidak membuka data event internal atau pendaftaran event.</p><StoryFields form={form} section="activities" /></section>
        <section className="card stack-medium"><h2 className="text-heading-2">Galeri</h2><TextField form={form} name="gallery.title" label="Judul galeri" maxLength={100} />
          {gallery.fields.map((item, index) => <div className="src-content-item stack-medium" key={item.id}><ImageFields form={form} prefix={`gallery.items.${index}`} label={`Gambar galeri ${index + 1}`} media={media} value={values.gallery.items[index]} onChange={value => { if (value) form.setValue(`gallery.items.${index}`, value, { shouldDirty: true }); else gallery.remove(index); }} />
            <div className="src-landing-actions"><Button type="button" variant="outline" disabled={index === 0} onClick={() => gallery.move(index, index - 1)}>Naikkan foto {index + 1}</Button><Button type="button" variant="outline" onClick={() => gallery.remove(index)}>Hapus foto {index + 1}</Button></div></div>)}
          <Button type="button" variant="outline" disabled={!media.length || gallery.fields.length >= 24} onClick={() => { if (media[0]) gallery.append({ media_id: media[0].id, alt: "", caption: "" }); }}>Tambah foto galeri</Button>
        </section>
        <section className="card stack-medium"><h2 className="text-heading-2">FAQ</h2><TextField form={form} name="faq.title" label="Judul FAQ" maxLength={100} />
          {faq.fields.map((item, index) => <div key={item.id} className="src-content-item stack-medium"><TextField form={form} name={`faq.items.${index}.question`} label={`Pertanyaan ${index + 1}`} maxLength={200} /><TextField form={form} name={`faq.items.${index}.answer`} label={`Jawaban ${index + 1}`} multiline /><div className="src-landing-actions"><Button type="button" variant="outline" disabled={index === 0} onClick={() => faq.move(index, index - 1)}>Naikkan FAQ {index + 1}</Button><Button type="button" variant="outline" onClick={() => faq.remove(index)}>Hapus FAQ {index + 1}</Button></div></div>)}
          <Button type="button" variant="outline" disabled={faq.fields.length >= 20} onClick={() => faq.append({ question: "", answer: "" })}>Tambah FAQ</Button>
        </section>
        <section className="card stack-medium"><h2 className="text-heading-2">Kontak dan footer</h2>
          <TextField form={form} name="contact.title" label="Judul kontak" maxLength={100} /><TextField form={form} name="contact.description" label="Deskripsi kontak" multiline maxLength={1000} />
          <TextField form={form} name="contact.email" label="Email kontak publik" maxLength={254} /><TextField form={form} name="contact.phone" label="Telepon kontak publik" maxLength={32} />
          {socials.fields.map((item, index) => <div key={item.id} className="src-content-item stack-medium"><TextField form={form} name={`contact.socials.${index}.label`} label={`Label media sosial ${index + 1}`} maxLength={60} /><TextField form={form} name={`contact.socials.${index}.url`} label={`URL HTTPS ${index + 1}`} maxLength={500} /><Button type="button" variant="outline" onClick={() => socials.remove(index)}>Hapus tautan {index + 1}</Button></div>)}
          <Button type="button" variant="outline" disabled={socials.fields.length >= 8} onClick={() => socials.append({ label: "", url: "" })}>Tambah media sosial</Button>
          <TextField form={form} name="footer.text" label="Teks footer" multiline maxLength={500} />
        </section>
        <section className="card stack-medium"><h2 className="text-heading-2">SEO</h2><TextField form={form} name="seo.title" label="Judul SEO" maxLength={70} /><TextField form={form} name="seo.description" label="Meta description" multiline maxLength={180} /><ImageFields form={form} prefix="seo.image" label="Gambar berbagi" media={media} value={values.seo.image} onChange={value => form.setValue("seo.image", value, { shouldDirty: true })} /></section>
      </fieldset>
      <div className="src-landing-actions"><Button type="submit" disabled={busy || unknown}>{busy ? "Memproses…" : "Simpan Draft"}</Button>
        {!form.formState.isDirty && !busy && !unknown ? <Link href="/admin/landing-page/preview" className="button button-outline">Preview Draft Tersimpan</Link> : <span className="supporting">Simpan draft sebelum preview atau publish.</span>}
        {!form.formState.isDirty && !unknown && <PublishButton disabled={busy} onPublish={publish} />}
      </div>
    </form>
    <section className="card stack-medium" aria-labelledby="upload-title"><h2 id="upload-title" className="text-heading-2">Media privat landing</h2>
      <p className="supporting" id="landing-upload-help">JPEG/PNG/WebP maksimal 5 MB, sisi 16–6000 px, maksimal 16 megapixel. Gambar diproses ulang maksimal 2400 px. Gunakan foto yang diizinkan; isi alt text pada penggunaannya. Gambar baru tetap privat sampai publish.</p>
      <label htmlFor="landing-upload">Unggah gambar</label><Input id="landing-upload" type="file" accept="image/jpeg,image/png,image/webp" ref={fileInput} aria-describedby="landing-upload-help" disabled={busy} onChange={event => { const file = event.target.files?.[0]; if (file) void upload(file); }} />
      {media.map((item, index) => <div className="src-content-media-row" key={item.id}><span>Gambar {index + 1} · {item.width} × {item.height} · {item.id.slice(0, 8)}{selectedIds.has(item.id) && " · Sedang dipilih dalam form"}</span><Button type="button" variant="outline" disabled={busy || selectedIds.has(item.id)} onClick={() => void perform(async () => { await deleteLandingMedia(item.id); if (!current()) return; setExtraMedia(previous => previous.filter(file => file.id !== item.id)); await client.invalidateQueries({ queryKey: ["content", "media", actor] }); setNotice("Gambar yang tidak digunakan telah dihapus."); })}>Hapus gambar {index + 1}</Button></div>)}
      <p className="supporting">Gambar yang direferensikan draft atau revisi terbit tidak dapat dihapus.</p>
      <MediaLibrary actor={actor} disabled={busy} onSelect={item => setExtraMedia(previous => [...previous.filter(file => file.id !== item.id), item])} />
    </section>
  </div>;
}

function MediaLibrary({ actor, disabled, onSelect }: { actor: number; disabled: boolean; onSelect: (item: LandingMedia) => void }) {
  const [page, setPage] = useState(1);
  const client = useQueryClient();
  const [problem, setProblem] = useState("");
  const query = useQuery({ queryKey: ["content", "media", actor, page], queryFn: ({ signal }) => getLandingMedia(page, signal), meta: { requiresSession: true } });
  return <div className="stack-medium"><h3 className="text-heading-3">Pustaka media</h3>
    <Button type="button" variant="outline" disabled={disabled || query.isFetching} onClick={() => void query.refetch()}>Perbarui pustaka</Button>
    {query.isPending && <p role="status">Memuat pustaka media…</p>}
    {query.error && <Alert>{normalizeApiError(query.error).message}</Alert>}
    {problem && <Alert>{problem}</Alert>}
    {query.data?.items.length === 0 && <p>Belum ada gambar.</p>}
    {query.data?.items.map(item => <div key={item.id} className="src-content-media-row"><span>{item.id.slice(0, 8)} · {item.width} × {item.height} · {item.state === "ready" ? "Siap dipilih" : "Penghapusan tertunda"}</span>
      {item.state === "ready" && <><div className="src-content-thumbnail"><PrivateLandingImage id={item.id} alt="Pratinjau gambar pustaka" width={item.width} height={item.height} /></div><Button type="button" variant="outline" disabled={disabled} onClick={() => onSelect({ id: item.id, url: item.url, mime: item.mime, width: item.width, height: item.height })}>Pilih gambar {item.id.slice(0, 8)}</Button></>}
      {item.state === "deleting" && <Button type="button" variant="outline" disabled={disabled} onClick={() => { setProblem(""); void deleteLandingMedia(item.id).then(() => client.invalidateQueries({ queryKey: ["content", "media", actor] })).catch(async error => { const parsed = normalizeApiError(error); if (isSessionRejection(parsed)) { await clearPrivateQueries(client, actor); await client.invalidateQueries({ queryKey: sessionKey }); } else setProblem(parsed.message); }); }}>Lanjutkan penghapusan {item.id.slice(0, 8)}</Button>}
    </div>)}
    <div className="src-landing-actions"><Button type="button" variant="outline" disabled={disabled || page === 1 || query.isFetching} onClick={() => setPage(value => value - 1)}>Media sebelumnya</Button><Button type="button" variant="outline" disabled={disabled || !query.data || page >= query.data.last_page || query.isFetching} onClick={() => setPage(value => value + 1)}>Media berikutnya</Button></div>
  </div>;
}

function PublishButton({ disabled, onPublish }: { disabled: boolean; onPublish: () => Promise<boolean> }) {
  const [open, setOpen] = useState(false);
  const cancel = useRef<HTMLButtonElement>(null);
  return <Dialog.Root open={open} onOpenChange={value => { if (!disabled) setOpen(value); }}><Dialog.Trigger asChild><Button type="button" disabled={disabled}>Publikasikan</Button></Dialog.Trigger><Dialog.Portal><Dialog.Overlay className="dialog-overlay" /><Dialog.Content className="dialog-panel" onOpenAutoFocus={event => { event.preventDefault(); cancel.current?.focus(); }} onEscapeKeyDown={event => { if (disabled) event.preventDefault(); }} onInteractOutside={event => { if (disabled) event.preventDefault(); }}><Dialog.Title className="text-heading-3">Publikasikan landing page?</Dialog.Title><Dialog.Description>Draft tersimpan akan terlihat oleh pengunjung. Periksa kembali isi dan hak penggunaan gambar.</Dialog.Description><Button type="button" disabled={disabled} onClick={() => { void onPublish().then(() => setOpen(false)); }}>{disabled ? "Menerbitkan…" : "Ya, Publikasikan"}</Button><Dialog.Close asChild><Button ref={cancel} type="button" variant="outline" disabled={disabled}>Batal</Button></Dialog.Close></Dialog.Content></Dialog.Portal></Dialog.Root>;
}
