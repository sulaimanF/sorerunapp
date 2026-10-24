"use client";

import { useEffect, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Alert } from "@/components/shared/feedback";
import { FormField } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { normalizeApiError, type ApiError } from "@/lib/errors";
import { clearPrivateQueries, sessionKey } from "@/lib/query-client";
import type { BrowserSession } from "@/features/auth/types";
import { getMemberProfile, updateMemberProfile } from "../services";
import { profileSchema, profileValues, type MemberProfile, type ProfileValues } from "../schemas";

const fields = ["full_name", "birth_date", "phone", "city", "gender"] as const;

export function ProfileEditor({ profile, userId }: { profile: MemberProfile; userId: number }) {
  const client = useQueryClient();
  const pending = useRef<AbortController | null>(null);
  const [problem, setProblem] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState("");
  const [checking, setChecking] = useState(false);
  const form = useForm<ProfileValues>({
    resolver: zodResolver(profileSchema),
    defaultValues: profileValues(profile),
  });
  useEffect(() => () => pending.current?.abort(), []);
  const errors = form.formState.errors;
  const busy = form.formState.isSubmitting || checking;
  const outcomeUnknown = !!problem && (problem.status === 0 || problem.status >= 500);
  const sameAccount = () => client.getQueryData<BrowserSession | null>(sessionKey)?.user.id === userId;

  async function accept(data: MemberProfile, controller: AbortController, message: string) {
    if (controller.signal.aborted || !sameAccount()) return;
    await client.cancelQueries({ queryKey: ["members", "profile", userId] });
    if (controller.signal.aborted || !sameAccount()) return;
    client.setQueryData(["members", "profile", userId], data);
    form.reset(profileValues(data));
    setProblem(null);
    setNotice(message);
    await Promise.all([
      client.invalidateQueries({ queryKey: sessionKey }),
      client.invalidateQueries({ queryKey: ["membership", "card", userId] }),
    ]);
  }

  async function showError(error: unknown, controller: AbortController) {
    if (controller.signal.aborted || !sameAccount()) return;
    const parsed = normalizeApiError(error);
    if (parsed.status === 401 || parsed.code === "ACCOUNT_BLOCKED") {
      await clearPrivateQueries(client);
      await client.invalidateQueries({ queryKey: sessionKey });
      return;
    }
    setProblem(parsed);
    let first: (typeof fields)[number] | undefined;
    for (const field of fields) {
      if (parsed.fields[field]?.[0]) {
        form.setError(field, { message: parsed.fields[field][0] });
        first ??= field;
      }
    }
    if (first) {
      const field = first;
      requestAnimationFrame(() => {
        if (!controller.signal.aborted && sameAccount()) form.setFocus(field);
      });
    }
  }

  async function submit(values: ProfileValues) {
    const controller = new AbortController();
    pending.current = controller;
    setProblem(null);
    setNotice("");
    try {
      const result = await updateMemberProfile(values, controller.signal);
      await accept(result, controller, "Profil berhasil diperbarui.");
    } catch (error) {
      await showError(error, controller);
    } finally {
      if (pending.current === controller) pending.current = null;
    }
  }

  async function checkSavedProfile() {
    const controller = new AbortController();
    pending.current = controller;
    setChecking(true);
    setNotice("");
    try {
      const result = await getMemberProfile(controller.signal);
      await accept(result, controller, "Data profil terbaru berhasil dimuat.");
    } catch (error) {
      await showError(error, controller);
    } finally {
      if (!controller.signal.aborted && sameAccount()) setChecking(false);
      if (pending.current === controller) pending.current = null;
    }
  }

  const described = (field: (typeof fields)[number]) => [
    field === "birth_date" ? "birth_date-help" : null,
    errors[field] ? `${field}-error` : null,
  ].filter(Boolean).join(" ") || undefined;

  return (
    <form onSubmit={(event) => void form.handleSubmit(submit)(event)} noValidate aria-label="Form profil anggota" className="stack-medium">
      <h2 className="text-heading-3">Biodata anggota</h2>
      <p className="supporting">Tanda * menunjukkan data wajib. Perubahan berlaku setelah berhasil disimpan.</p>
      {notice && !form.formState.isDirty && <Alert tone="success">{notice}</Alert>}
      {!problem && Object.keys(errors).length > 0 && <Alert>Periksa data yang ditandai sebelum menyimpan.</Alert>}
      {problem && <Alert>
        {problem.message}
        {outcomeUnknown && <p>Hasil penyimpanan belum terkonfirmasi. Periksa data tersimpan sebelum mengirim ulang.</p>}
        {problem.status === 419 && <p>Perlindungan sesi akan diperbarui saat Anda mencoba menyimpan lagi.</p>}
      </Alert>}
      <fieldset disabled={busy} className="stack-medium">
        <legend className="sr-only">Data pribadi Anda</legend>
        <FormField name="full_name" label="Nama lengkap" error={errors.full_name?.message}>
          <Input id="full_name" autoComplete="name" required maxLength={150} aria-invalid={!!errors.full_name} aria-describedby={described("full_name")} {...form.register("full_name")} />
        </FormField>
        <FormField name="birth_date" label="Tanggal lahir" help="Format dd/mm/yyyy, misalnya 29/02/2012." error={errors.birth_date?.message}>
          <Input id="birth_date" placeholder="dd/mm/yyyy" inputMode="numeric" autoComplete="bday" required maxLength={10} aria-invalid={!!errors.birth_date} aria-describedby={described("birth_date")} {...form.register("birth_date")} />
        </FormField>
        <FormField name="phone" label="Nomor telepon" error={errors.phone?.message}>
          <Input id="phone" type="tel" autoComplete="tel" required maxLength={32} aria-invalid={!!errors.phone} aria-describedby={described("phone")} {...form.register("phone")} />
        </FormField>
        <FormField name="city" label="Kota/domisili" error={errors.city?.message}>
          <Input id="city" autoComplete="address-level2" required maxLength={100} aria-invalid={!!errors.city} aria-describedby={described("city")} {...form.register("city")} />
        </FormField>
        <FormField name="gender" label="Jenis kelamin" optional error={errors.gender?.message}>
          <Input id="gender" maxLength={32} aria-invalid={!!errors.gender} aria-describedby={described("gender")} {...form.register("gender")} />
        </FormField>
      </fieldset>
      {outcomeUnknown && <Button type="button" variant="outline" disabled={busy} onClick={() => void checkSavedProfile()}>
        {checking ? "Memeriksa data…" : "Periksa Data Tersimpan"}
      </Button>}
      <div className="flex flex-wrap gap-3">
        <Button type="submit" disabled={busy}>{form.formState.isSubmitting ? "Menyimpan…" : "Simpan Perubahan"}</Button>
        <Button type="button" variant="outline" disabled={busy || !form.formState.isDirty} onClick={() => {
          form.reset(profileValues(profile)); setProblem(null); setNotice("");
        }}>Batalkan Perubahan</Button>
      </div>
    </form>
  );
}
