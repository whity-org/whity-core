/**
 * Company stamps (#1729): the wire shapes `/api/v1/document-stamps` answers
 * with, and the client-side checks on a stamp's image before it is sent.
 *
 * The server checks the image again (type by its bytes, size, pixel bounds);
 * these checks only spare the person a round trip for the obvious cases.
 */

export interface CompanyStamp {
  id: number;
  name: string;
  legal_name: string;
  /** A `data:image/png;base64,…` or `data:image/jpeg;base64,…` URL. */
  image: string;
  image_hash: string;
  holders: {
    people: { profile_id: number; name: string }[];
    roles: { role_id: number; name: string }[];
  };
  created_at: string;
  updated_at: string;
  archived_at: string | null;
}

export interface StampDraft {
  name: string;
  legal_name: string;
  image: string;
  people: number[];
  roles: number[];
}

/** The fields a 422 names in `details.field`. */
export type StampField = 'name' | 'legal_name' | 'image' | 'holders';

/** `StampImage::MAX_BYTES` on the server: 512 KB of image. */
export const MAX_STAMP_IMAGE_BYTES = 524_288;

export const STAMP_IMAGE_TYPES = ['image/png', 'image/jpeg'] as const;

export type ImageProblem = 'type' | 'size' | null;

/** Why a chosen file cannot be a stamp's image, or null when it can. */
export function imageProblem(file: { type: string; size: number }): ImageProblem {
  if (!(STAMP_IMAGE_TYPES as readonly string[]).includes(file.type)) return 'type';
  if (file.size > MAX_STAMP_IMAGE_BYTES) return 'size';
  return null;
}

/** Read a file as a data URL. */
export function readAsDataUrl(file: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(typeof reader.result === 'string' ? reader.result : '');
    reader.onerror = () => reject(reader.error ?? new Error('read'));
    reader.readAsDataURL(file);
  });
}

export function draftOf(stamp: CompanyStamp | null): StampDraft {
  if (stamp === null) return { name: '', legal_name: '', image: '', people: [], roles: [] };
  return {
    name: stamp.name,
    legal_name: stamp.legal_name,
    image: stamp.image,
    people: stamp.holders.people.map((p) => p.profile_id),
    roles: stamp.holders.roles.map((r) => r.role_id),
  };
}

function sameIds(a: number[], b: number[]): boolean {
  if (a.length !== b.length) return false;
  const sorted = [...b].sort((x, y) => x - y);
  return [...a].sort((x, y) => x - y).every((id, i) => id === sorted[i]);
}

/** Whether the holders in a draft differ from the stamp's. */
export function holdersChanged(stamp: CompanyStamp, draft: StampDraft): boolean {
  return (
    !sameIds(
      stamp.holders.people.map((p) => p.profile_id),
      draft.people
    ) ||
    !sameIds(
      stamp.holders.roles.map((r) => r.role_id),
      draft.roles
    )
  );
}

/** A refusal's message and the field it names, from a 422 body. */
export function refusalOf(body: unknown, fallback: string): { message: string; field: StampField | null } {
  const record = typeof body === 'object' && body !== null ? (body as Record<string, unknown>) : {};
  const message = typeof record.error === 'string' && record.error !== '' ? record.error : fallback;
  const details = typeof record.details === 'object' && record.details !== null ? (record.details as Record<string, unknown>) : {};
  const field = details.field;
  return {
    message,
    field: field === 'name' || field === 'legal_name' || field === 'image' || field === 'holders' ? field : null,
  };
}
