import type { ScopeGrantGroup, ScopeGrantMode } from '@whity/ui/scope-grant';

/**
 * Workspace agents on the wire (WHIT-815, WHIT-816): `/api/agents`,
 * `/api/agent-presets` and `/api/me/agents`.
 */

export type AgentTone = 'plain' | 'formal' | 'brief';
export type AgentLanguage = 'auto' | 'en' | 'ar';

/** The fields a person edits, also what a preset starts an agent with. */
export interface AgentPersona {
  name: string;
  nameAr: string | null;
  role: string | null;
  roleAr: string | null;
  instructions: string | null;
  instructionsAr: string | null;
  tone: AgentTone;
  language: AgentLanguage;
  toolsets: string[];
  grant: Record<string, ScopeGrantMode>;
}

export interface WorkspaceAgent extends AgentPersona {
  id: number;
  slug: string;
  preset: { key: string; version: number } | null;
  status: 'active' | 'archived';
}

export interface AgentPreset extends AgentPersona {
  key: string;
  version: number;
}

/** The workspace's agents as one person sees them. */
export interface MyAgent {
  id: number;
  slug: string;
  name: string;
  nameAr: string | null;
  role: string | null;
  roleAr: string | null;
  assignment: { id: number; principalId: string; createdAt: string } | null;
}

/** A text in the reader's language, or English when it has no Arabic. */
export function localized(english: string | null, arabic: string | null, inArabic: boolean): string {
  const translated = arabic?.trim();
  return inArabic && translated ? translated : (english ?? '');
}

/** A read is a permission whose action is `read` or `view`, as `AgentDefinition` decides. */
export function isReadPermission(permission: string): boolean {
  const action = permission.split(':').pop();
  return action === 'read' || action === 'view';
}

/**
 * A starting grant as ScopeGrant rows, by resource. A write never offers
 * Allowed: only the person using the agent can give that (owner decision Q6),
 * and the server refuses a template that tries.
 */
export function templateGroups(grant: Record<string, ScopeGrantMode>, writeHint: string): ScopeGrantGroup[] {
  const groups = new Map<string, ScopeGrantGroup>();
  for (const permission of Object.keys(grant).sort()) {
    const resource = permission.split(':')[0] ?? permission;
    const group = groups.get(resource) ?? { key: resource, label: resource, actions: [] };
    const read = isReadPermission(permission);
    group.actions.push({
      key: permission,
      label: permission,
      modes: read ? ['off', 'ask', 'allow'] : ['off', 'ask'],
      hint: read ? undefined : writeHint,
    });
    groups.set(resource, group);
  }
  return [...groups.values()];
}

/** The server's reasons for refusing an agent, one per line when it lists them. */
export async function refusal(response: Response, fallback: string): Promise<string> {
  const body = (await response.json().catch(() => null)) as { error?: string; errors?: string[] } | null;
  if (body?.errors && body.errors.length > 0) return body.errors.join('\n');
  return body?.error ?? fallback;
}

/** Every toolset named by a split the caller can reach (`GET /api/mcp/splits`), without repeats. */
export function toolsetsFromSplits(splits: { toolsets: string[] }[], alreadyChosen: string[]): string[] {
  return [...new Set([...splits.flatMap((split) => split.toolsets), ...alreadyChosen])].sort();
}
