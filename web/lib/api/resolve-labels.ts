/**
 * Unit and role names for a screen that holds their ids (#1019).
 *
 * `POST /api/v1/labels/resolve` names a few ids for a caller holding the asking
 * SURFACE's permission (`documents` → documents:read, `documents.route` →
 * documents:route), so showing "Department of Civil Engineering" no longer needs
 * the right to list every unit (`ous:read`) or role (`roles:read`).
 *
 * Never throws. An id the server does not name (unknown, or another workspace's)
 * is simply missing from the map and the screen keeps showing the id. `available`
 * is false only when the request itself was refused or failed, which is what the
 * screens' "names could not be loaded" notices report.
 */

type ApiClient = (url: string, options?: RequestInit) => Promise<Response>;

export type LabelSurface = 'documents' | 'documents.route';

export interface LabelRequest {
  ous?: readonly number[];
  roles?: readonly number[];
}

export interface ResolvedLabels {
  ous: Map<number, string>;
  roles: Map<number, string>;
  available: boolean;
}

/** The server's default cap (`labels.resolve_max_ids`); larger sets go in chunks. */
export const LABELS_PER_REQUEST = 100;

function unique(ids: readonly number[] | undefined): number[] {
  return Array.from(new Set((ids ?? []).filter((id) => Number.isInteger(id) && id > 0))).sort(
    (a, b) => a - b
  );
}

/**
 * A stable key for a set of ids, for a fetch effect's dependency list: the same
 * ids in another order are the same request.
 */
export function labelRequestKey(request: LabelRequest): string {
  return `${unique(request.ous).join(',')}|${unique(request.roles).join(',')}`;
}

export async function resolveLabels(
  apiClient: ApiClient,
  surface: LabelSurface,
  request: LabelRequest
): Promise<ResolvedLabels> {
  const result: ResolvedLabels = { ous: new Map(), roles: new Map(), available: true };
  const pending: { kind: 'ous' | 'roles'; id: number }[] = [
    ...unique(request.ous).map((id) => ({ kind: 'ous' as const, id })),
    ...unique(request.roles).map((id) => ({ kind: 'roles' as const, id })),
  ];

  for (let start = 0; start < pending.length; start += LABELS_PER_REQUEST) {
    const chunk = pending.slice(start, start + LABELS_PER_REQUEST);
    const body = {
      surface,
      ous: chunk.filter((item) => item.kind === 'ous').map((item) => item.id),
      roles: chunk.filter((item) => item.kind === 'roles').map((item) => item.id),
    };
    try {
      const response = await apiClient('/api/v1/labels/resolve', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      if (!response.ok) {
        result.available = false;
        continue;
      }
      const parsed = (await response.json().catch(() => null)) as {
        data?: { ous?: Record<string, string>; roles?: Record<string, string> };
      } | null;
      for (const [id, name] of Object.entries(parsed?.data?.ous ?? {})) {
        result.ous.set(Number(id), name);
      }
      for (const [id, name] of Object.entries(parsed?.data?.roles ?? {})) {
        result.roles.set(Number(id), name);
      }
    } catch {
      result.available = false;
    }
  }

  return result;
}
