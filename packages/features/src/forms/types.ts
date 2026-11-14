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
  /** WHIT-1017: when the reference is given. Absent on an older server, which meant on submit. */
  reference_assign?: "submit" | "approval"
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
  /** WHIT-920: the public link asks visitors to sign in first. */
  public_requires_sign_in?: boolean
  /** WHIT-921: the "Final document template" a route's final document stage uses when it names none. */
  final_template_id?: number | null
  /** WHIT-921: whether the person who sent it can open its final document. */
  final_document_to_applicant?: boolean
  /** Questions a person chose to keep out of that document. */
  document_left_out?: string[]
  submission_count?: number
  /** The heading the person filling it in reads; null shows the name (WHIT-944). */
  title?: LocalizedMap | null
  banner?: FormBannerValue | null
}

/**
 * The picture at the top of a form: a pattern drawn from the brand colour, or an
 * uploaded image. A PATCH sends `{ kind: "image" }` back to keep the uploaded one.
 */
export type FormBannerValue =
  | { kind: "pattern"; style: string; seed: number }
  | { kind: "image"; image_url?: string }

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

/** `GET /api/v1/me/form-drafts`: what somebody started and has not sent. */
export interface FormDraftRow {
  form_id: number
  form_name: LocalizedMap | null
  /** The step they were on, counted from 0. */
  step: number | null
  updated_at: string
}

/** A file somebody attached. Download it from the submission's `files/{id}` address. */
export interface SubmissionFile {
  id: number
  field_key: string
  content_type: string
  byte_size: number
  filename: string | null
}

/** WHIT-920: where the caller's own submission is — stages, outcomes, and the notes written for them. */
export interface SubmissionProgress {
  state: string
  stages: Array<{
    position: number
    /** Null when the route gives the stage no name. */
    label: string | null
    status: "done" | "current" | "waiting" | "sent_back" | "not_approved"
    outcome: "approved" | "rejected" | null
    /** WHIT-921: the stage that makes the final document. */
    kind?: "finalise"
    /** Only when the workspace shows who reviewed it. */
    reviewers?: string[]
  }>
  notes: Array<{
    kind: "sent_back" | "not_approved" | "note"
    note: string
    stage: string | null
    stage_position?: number | null
    at: string
    from?: string | null
  }>
  can_resubmit: boolean
  resume_from: "sender" | "start" | null
  shows_reviewers: boolean
  /**
   * WHIT-921: the route's final document, once its final stage is reached.
   * `can_open`: the PDF exists and the form lets the applicant have it.
   */
  final_document?: { status: "waiting" | "ready" | "failed"; can_open: boolean } | null
}

/** WHIT-921: a published document template a form's final document can be made with. */
export interface FinalTemplateOption {
  id: number
  title: string
}

/** A field the template prints that nothing on the form fills. */
export interface DocumentFitNeed {
  path: string
  label: string
  reason: "not_asked" | "staff_only" | "other_record"
  /** The question to add for it, or null when a question can't fill it. */
  field_key: string | null
}

/** How a form and its final document template fit, both ways. */
export interface DocumentFit {
  hidden: false
  template: { id: number; title: string; lang: string; published: boolean }
  filled: number
  needs: DocumentFitNeed[]
  unused: Array<{ field_key: string; label: LocalizedMap; in_draft: boolean; left_out: boolean }>
  can_edit_template: boolean
  route_uses_other_template: boolean
}

export interface SubmissionDetail extends SubmissionRow {
  fields: FieldDraft[]
  files?: SubmissionFile[]
  form_version_now: number | null
  /** On the caller's own submission only (WHIT-920). */
  progress?: SubmissionProgress | null
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
