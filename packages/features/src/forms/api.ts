import type {
  FieldDraft,
  FieldVocabulary,
  FormRow,
  FormSectionDef,
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
    getSubmission: (id: number, fallback: string) =>
      call(transport, "GET", `/api/v1/form-submissions/${id}`, undefined, fallback, (b) => data<SubmissionDetail>(b)),
  }
}

export type FormsApi = ReturnType<typeof createFormsApi>

/** The body the fields endpoint takes for one question. */
export function fieldBody(field: FieldDraft): Record<string, unknown> {
  const validation = Array.isArray(field.validation) ? {} : field.validation
  const help = Array.isArray(field.help_text_i18n) ? {} : field.help_text_i18n
  return {
    field_key: field.field_key,
    field_type: field.field_type,
    label: field.label,
    help_text: Object.values(help).some((v) => v) ? help : null,
    is_required: field.is_required,
    options: field.options,
    validation,
    prefill_source: field.prefill_source,
    section_key: field.section_key,
    visible_when: field.visible_when,
    audience: field.audience,
  }
}
