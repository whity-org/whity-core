/**
 * The shapes the forms API returns and the builder edits. Only what the screens
 * read is typed; the server owns the rest.
 */

import type { Transport } from "../record"

export type { Transport }

/** `{ en, ar }` and any other language a workspace offers. */
export type LocalizedMap = Record<string, string | undefined>

export type FormStatus = "draft" | "published" | "archived"

export interface FormSectionDef {
  key: string
  title: LocalizedMap
  description: LocalizedMap
}

export interface FormRow {
  id: number
  form_key: string
  name: LocalizedMap | null
  description: string | null
  description_i18n: LocalizedMap | []
  submit_label: LocalizedMap | null
  reference_format: string | null
  sections: FormSectionDef[]
  status: FormStatus
  version: number
  route_template_id: number | null
  created_at: string
  updated_at: string
  public_enabled: boolean
  public_slug: string | null
  public_url: string | null
  public_opens_at: string | null
  public_closes_at: string | null
  /** Present once the server offers the switch (WHIT-859). */
  open_to_members?: boolean
  submission_count?: number
}

export type FieldKind =
  | "text"
  | "textarea"
  | "number"
  | "date"
  | "select"
  | "multiselect"
  | "checkbox"
  | "file"
  | "profile_ref"
  | "ou_ref"
  | "repeating"
  | "signature"

export interface FieldOption {
  value: string
  label: LocalizedMap
}

export interface FieldColumn {
  key: string
  type: string
  label: LocalizedMap
  required?: boolean
  options?: FieldOption[]
}

export interface FieldValidation {
  min?: number
  max?: number
  maxLength?: number
  pattern?: string
  maxFiles?: number
  ou_type?: string
  columns?: FieldColumn[]
  minRows?: number
  maxRows?: number
}

export type FieldCondition =
  | { field: string; equals: string | number | boolean; in?: undefined }
  | { field: string; in: string[]; equals?: undefined }

export type FieldAudience = "applicant" | "staff"

/** One question, as the builder holds it. `id` is absent until saved. */
export interface FieldDraft {
  id?: number
  field_key: string
  field_type: FieldKind
  label: LocalizedMap
  help_text_i18n: LocalizedMap | []
  is_required: boolean
  options: FieldOption[]
  validation: FieldValidation | []
  prefill_source: string | null
  section_key: string | null
  visible_when: FieldCondition | null
  audience: FieldAudience
}

export interface FieldVocabulary {
  field_types: string[]
}

export interface SubmissionRow {
  id: number
  form_id: number
  form_version: number
  submitted_by_profile_id: number | null
  /** The person's name, as the server resolves it. */
  submitted_by_name?: string | null
  document_id: number | null
  reference: string | null
  data: Record<string, unknown> | []
  submitted_at: string
  state?: string
  current_step?: string | null
  form_key?: string
  form_name?: LocalizedMap | null
}

export interface SubmissionDetail extends SubmissionRow {
  fields: FieldDraft[]
  form_version_now: number | null
}

export interface FormsScreenCaps {
  /** `forms:manage`. Without it the screens are read-only. */
  canManage: boolean
}

export type FormsResult<T> = { ok: true; data: T } | { ok: false; status: number; message: string; reason?: string }

export type NotifyType = "success" | "error" | "warning" | "info"

/** What a client hands every forms screen: its transport, its toast and its router. */
export interface FormsScreenEnv {
  transport: Transport
  notify: (message: string, type: NotifyType, action?: { label: string; onClick: () => void }) => void
  navigate: (path: string) => void
}

/** `GET /api/v1/forms/{id}/responses/summary`: counts only, never free text. */
export interface ResponsesSummary {
  total: number
  per_week: Array<{ week: string; count: number }>
  routing: { not_routed: number; in_progress: number; completed: number; withdrawn?: number }
  questions: Array<{
    field_key: string
    label: LocalizedMap
    options: Array<{ value: string; label: LocalizedMap | null; count: number }>
  }>
}
