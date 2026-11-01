import type { ScopeGrantGroup, ScopeGrantMode } from '@whity-org/ui/scope-grant';

/**
 * The agent grant, per permission (WHIT-810 over WHIT-832's
 * `/api/mcp/tokens/{jti}/grant`). The row shape is `AgentGrantView`, which
 * WHIT-816's `/api/me/agents/{id}/assignment/grant` will serve unchanged, so
 * moving to it means changing `grantPath` and nothing else.
 *
 * These routes are deliberately not in openapi.json (an agent must never be
 * able to reach its own grant), so the types live here.
 */

export type GrantMode = ScopeGrantMode;

export type WithheldReason = 'not_held' | 'outside_credential' | 'always_asks' | 'refused_for_agents';

export interface GrantRow {
  permission: string;
  /** What the person chose, or null for the default. */
  mode: GrantMode | null;
  /** What they may choose. Off is always among them. */
  modes: GrantMode[];
  withheld: Partial<Record<GrantMode, { reason: WithheldReason | string; message: string }>>;
  /** What the agent can do now with the reads and writes behind it; null when there are none of that kind. */
  effective: { read: GrantMode | null; write: GrantMode | null };
  /** The longest undo window among its writes (WHIT-812); null when they can't be undone. */
  undoWindowSeconds?: number | null;
}

export interface AgentGrant {
  jti: string;
  name: string;
  permissions: GrantRow[];
}

/** Where one credential's grant lives. The only line that changes for WHIT-816. */
export function grantPath(jti: string): string {
  return `/api/v1/mcp/tokens/${encodeURIComponent(jti)}/grant`;
}

/** `tag-groups` → "Tag groups", `send_reminder` → "Send reminder". */
function words(key: string): string {
  const text = key.replace(/[_-]+/g, ' ').trim().toLowerCase();
  return text.charAt(0).toUpperCase() + text.slice(1);
}

/**
 * The mode a row shows as selected. A chosen mode is itself. The default
 * shows what it amounts to for the permission's changes when it has any (a
 * row reading Allowed while its changes are Off would mislead), otherwise
 * for its reads. The hint under the row spells out both.
 */
export function shownMode(row: GrantRow): GrantMode {
  if (row.mode !== null) return row.mode;
  return row.effective.write ?? row.effective.read ?? 'off';
}

/** Permissions grouped by resource (`invoices:send` → Invoices), in the server's order. */
export function grantGroups(
  rows: GrantRow[],
  hint: (row: GrantRow) => string | undefined,
): ScopeGrantGroup[] {
  const groups = new Map<string, ScopeGrantGroup>();
  for (const row of rows) {
    const [resource, ...rest] = row.permission.split(':');
    const action = rest.join(':') || resource;
    const group = groups.get(resource) ?? { key: resource, label: words(resource), actions: [] };
    group.actions.push({ key: row.permission, label: words(action), modes: row.modes, hint: hint(row) });
    groups.set(resource, group);
  }
  return [...groups.values()];
}
