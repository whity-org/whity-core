import type { ScopeGrantGroup, ScopeGrantMode } from '@whity/ui/scope-grant';
import { humanizeKey } from './proposals';

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

export interface AgentOnItsOwnCounts {
  allowed: number;
  askFirst: number;
  off: number;
}

export interface WorkspaceAgent extends AgentPersona {
  id: number;
  slug: string;
  preset: { key: string; version: number } | null;
  status: 'active' | 'archived';
  createdAt: string;
  updatedAt: string;
  paused: boolean;
  pausedAt: string | null;
  pausedBy: string | null;
  updatedBy: string | null;
  worksFor: { id: number; name: string | null }[];
  actionsToday: number;
  proposalsToday: number;
  waiting: number;
  lastActiveAt: string | null;
  onItsOwn: AgentOnItsOwnCounts;
  /** A document this viewer can't read keeps its place, with no title. */
  mayRead: { id: number; title: string | null; kind: string | null; readable: boolean }[];
  /** A12: the person it acts for hears by email when something waits for them. */
  notifyByEmail?: boolean;
}

export interface AgentPreset extends AgentPersona {
  key: string;
  version: number;
  /** Three things it does, in each language; empty for a plugin's preset. */
  does: string[];
  doesAr: string[];
  icon: string | null;
  /** The plugin it comes with; null when it works with Whity alone. */
  needs: string | null;
  /** How many of this workspace's agents came from it. */
  agentsFromIt: number;
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
  paused: boolean;
  actionsToday: number;
  proposalsToday: number;
  waiting: number;
  lastActiveAt: string | null;
  onItsOwn: AgentOnItsOwnCounts | null;
  mayRead: { id: number; title: string; kind: string }[];
}

/** Midnight today on the viewer's clock, for what an agent did today. */
export function startOfToday(now: Date = new Date()): string {
  return new Date(now.getFullYear(), now.getMonth(), now.getDate()).toISOString();
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
    const group = groups.get(resource) ?? { key: resource, label: humanizeKey(resource), actions: [] };
    const read = isReadPermission(permission);
    group.actions.push({
      key: permission,
      label: humanizeKey(permission.split(':').slice(1).join(' ') || permission),
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

/**
 * A toolset's name when the server has none for it, such as a plugin's:
 * `hello-world` is "Hello world". The id itself is a technical detail.
 */
export function toolsetLabel(id: string): string {
  const parts = id.split('.');
  const rest = parts[0] === 'core' && parts.length > 1 ? parts.slice(1) : parts;
  return humanizeKey(rest.join(' '));
}
