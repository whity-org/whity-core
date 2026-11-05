import type {
  FieldDraft,
  FieldOption,
  FieldValidation,
  FieldVocabulary,
  FormRow,
  FormSectionDef,
  FormDraftRow,
  FormsResult,
  ResponsesSummary,
  LocalizedMap,
  SubmissionDetail,
  SubmissionRow,
  Transport,
} from "./types"

function message(body: unknown, fallback: string): string {
  if (typeof body === "object" && body !== null) {
    const raw = (body as { error?: unknown; message?: unknown }).error ?? (body as { message?: unknown }).message
    if (typeof raw === "string" && raw !== "") return raw
  }
  return fallback
}

function reasonOf(body: unknown): string | undefined {
  const details = typeof body === "object" && body !== null ? (body as { details?: { reason?: unknown } }).details : undefined
  return typeof details?.reason === "string" ? details.reason : undefined
}

async function call<T>(
  transport: Transport,
  method: string,
  path: string,
  body: unknown,
  fallback: string,
  pick: (body: unknown) => T
): Promise<FormsResult<T>> {
  try {
    const response = await transport.request(method, path, body)
    if (response.status < 200 || response.status >= 300) {
      return { ok: false, status: response.status, message: message(response.body, fallback), reason: reasonOf(response.body) }
    }
    return { ok: true, data: pick(response.body) }
  } catch {
    return { ok: false, status: 0, message: fallback }
  }
}

function data<T>(body: unknown): T {
  return (typeof body === "object" && body !== null && "data" in body ? (body as { data: T }).data : body) as T
}

/** The API calls the forms screens make, over whichever transport a client has. */
export function createFormsApi(transport: Transport) {
  return {
    listForms: (fallback: string) =>
      call(transport, "GET", "/api/v1/forms?limit=200", undefined, fallback, (b) => data<FormRow[]>(b)),
    getForm: (id: number, fallback: string) =>
      call(transport, "GET", `/api/v1/forms/${id}`, undefined, fallback, (b) =>
        data<FormRow & { fields: FieldDraft[]; sections: Array<FormSectionDef & { field_keys?: string[] }> }>(b)
      ),
    getFields: (id: number, fallback: string) =>
      call(transport, "GET", `/api/v1/forms/${id}/fields`, undefined, fallback, (b) => ({
        fields: data<FieldDraft[]>(b),
        vocabulary: ((b as { meta?: FieldVocabulary }).meta ?? { field_types: [] }) as FieldVocabulary,
      })),
    createForm: (input: { form_key: string; name: LocalizedMap; description?: LocalizedMap }, fallback: string) =>
      call(transport, "POST", "/api/v1/forms", input, fallback, (b) => data<FormRow>(b)),
    updateForm: (id: number, changes: Record<string, unknown>, fallback: string) =>
      call(transport, "PATCH", `/api/v1/forms/${id}`, changes, fallback, (b) => data<FormRow>(b)),
    replaceFields: (id: number, fields: unknown[], fallback: string) =>
      call(transport, "PUT", `/api/v1/forms/${id}/fields`, { fields }, fallback, (b) => data<FieldDraft[]>(b)),
    publish: (id: number, fallback: string) =>
      call(transport, "POST", `/api/v1/forms/${id}/publish`, {}, fallback, (b) => data<FormRow>(b)),
    archive: (id: number, fallback: string) =>
      call(transport, "POST", `/api/v1/forms/${id}/archive`, {}, fallback, (b) => data<FormRow>(b)),
    enablePublicLink: (id: number, fallback: string) =>
      call(transport, "POST", `/api/v1/forms/${id}/public-link`, {}, fallback, (b) => data<FormRow>(b)),
    disablePublicLink: (id: number, fallback: string) =>
      call(transport, "DELETE", `/api/v1/forms/${id}/public-link`, undefined, fallback, () => true),
    deleteForm: (id: number, fallback: string) =>
      call(transport, "DELETE", `/api/v1/forms/${id}`, undefined, fallback, () => true),
    openToMembers: (id: number, open: boolean, fallback: string) =>
      call(transport, open ? "POST" : "DELETE", `/api/v1/forms/${id}/member-access`, open ? {} : undefined, fallback, (b) => data<FormRow>(b)),
    listMyForms: (fallback: string) =>
      call(transport, "GET", "/api/v1/me/forms", undefined, fallback, (b) => data<FormRow[]>(b)),
    getSummary: (formId: number, fallback: string) =>
      call(transport, "GET", `/api/v1/forms/${formId}/responses/summary`, undefined, fallback, (b) => data<ResponsesSummary>(b)),
    listSubmissions: (formId: number | null, fallback: string) =>
      call(
        transport,
        "GET",
        `/api/v1/form-submissions?limit=200${formId === null ? "" : `&form_id=${formId}`}`,
        undefined,
        fallback,
        (b) => data<SubmissionRow[]>(b)
      ),
    listMySubmissions: (fallback: string) =>
      call(transport, "GET", "/api/v1/me/form-submissions?limit=200", undefined, fallback, (b) => data<SubmissionRow[]>(b)),
    getMySubmission: (id: number, fallback: string) =>
      call(transport, "GET", `/api/v1/me/form-submissions/${id}`, undefined, fallback, (b) => data<SubmissionDetail>(b)),
    withdrawMySubmission: (id: number, fallback: string) =>
      call(transport, "POST", `/api/v1/me/form-submissions/${id}/withdraw`, {}, fallback, (b) => data<SubmissionRow>(b)),
    listMyDrafts: (fallback: string) =>
      call(transport, "GET", "/api/v1/me/form-drafts", undefined, fallback, (b) => data<FormDraftRow[]>(b)),
    deleteMyDraft: (formId: number, fallback: string) =>
      call(transport, "DELETE", `/api/v1/me/forms/${formId}/draft`, undefined, fallback, () => true),
    getSubmission: (id: number, fallback: string) =>
      call(transport, "GET", `/api/v1/form-submissions/${id}`, undefined, fallback, (b) => data<SubmissionDetail>(b)),
  }
}

export type FormsApi = ReturnType<typeof createFormsApi>

/** Kinds whose choices the author writes, in a question or in a table's column. */
const OPTION_KINDS: readonly string[] = ["select", "multiselect"]

function cleanText(map: LocalizedMap | [] | undefined): LocalizedMap {
  if (map === undefined || Array.isArray(map)) return {}
  const clean: LocalizedMap = {}
  for (const language of ["en", "ar"] as const) {
    const text = map[language]?.trim()
    if (text) clean[language] = text
  }
  return clean
}

function cleanOptions(kind: string, options: readonly FieldOption[] | undefined): FieldOption[] {
  // Only a kind with written choices keeps them. Switching a choice question to text
  // leaves its choices on the draft, so they can come back if it is switched again.
  if (!OPTION_KINDS.includes(kind)) return []
  return (options ?? []).map((option) => ({ value: option.value.trim(), label: cleanText(option.label) }))
}

function cleanNumber(value: unknown): unknown {
  return typeof value === "string" && value.trim() !== "" && !Number.isNaN(Number(value)) ? Number(value) : value
}

function cleanValidation(kind: string, raw: FieldValidation | []): FieldValidation {
  const source: FieldValidation = Array.isArray(raw) ? {} : raw
  const clean: Record<string, unknown> = {}
  for (const rule of ["min", "max", "maxLength"] as const) {
    if (source[rule] !== undefined && source[rule] !== null) clean[rule] = cleanNumber(source[rule])
  }
  const pattern = source.pattern?.trim()
  if (pattern) clean.pattern = pattern
  if (source.maxFiles !== undefined && source.maxFiles !== null) clean.maxFiles = source.maxFiles
  if (source.ou_type) clean.ou_type = source.ou_type
  if (kind === "repeating") {
    clean.columns = (source.columns ?? []).map((column) => ({
      key: column.key,
      type: column.type,
      label: cleanText(column.label),
      required: column.required === true,
      options: cleanOptions(column.type, column.options),
    }))
    if (source.minRows !== undefined && source.minRows !== null) clean.minRows = source.minRows
    if (source.maxRows !== undefined && source.maxRows !== null) clean.maxRows = source.maxRows
  }
  return clean as FieldValidation
}

/**
 * The body the fields endpoint takes for one question, in the form the server stores.
 *
 * The builder compares these to see what changed, so a question typed here and the same
 * question read back must come out identical, for every kind. Text is trimmed and empty
 * languages dropped, choices belong only to kinds that have them, and a table's columns
 * always state `required` and `options`. `question-body-vectors.json` holds the server
 * and this function to one answer.
 */
export function fieldBody(field: FieldDraft): Record<string, unknown> {
  const help = cleanText(field.help_text_i18n)
  return {
    field_key: field.field_key,
    field_type: field.field_type,
    label: cleanText(field.label),
    help_text: Object.keys(help).length > 0 ? help : null,
    is_required: field.is_required === true,
    options: cleanOptions(field.field_type, field.options),
    validation: cleanValidation(field.field_type, field.validation),
    prefill_source: field.prefill_source ?? null,
    section_key: field.section_key ?? null,
    visible_when: field.visible_when ?? null,
    audience: field.audience ?? "applicant",
  }
}
