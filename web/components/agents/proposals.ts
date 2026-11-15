import { parseWireDate } from '@whity/features/datetime';
import type { components } from '@whity/api-client';
import type { ActionProposalChange, ActionProposalStatus } from '@whity/ui/action-proposal';
import type { AgentActivityItem } from '@whity/ui/agent-activity';

/**
 * Wire helpers for agent proposals (`GET /api/me/agent-proposals`, WHIT-784):
 * turning what the server stores into what the ActionProposal card and the
 * activity timeline show. No React here, so the rules are testable alone.
 */

export type AgentProposal = components['schemas']['AgentProposal'];
export type AgentAction = components['schemas']['AgentAction'];

/**
 * The words these helpers put on screen, translated by the caller. They take
 * words rather than a translate function because the catalogue extractor
 * reads `t()` calls only where `useTranslation` names their domain.
 */
export interface ProposalWords {
  /** Show a workspace agent's Arabic name when it has one. */
  arabic?: boolean;
  /** For an agent whose credential is gone. */
  unknownAgent: string;
  minute: string;
  /** `{n}` minutes. */
  minutes: string;
  hour: string;
  hours: string;
  day: string;
  days: string;
  /** `{summary}`: what an agent did by itself, or an approved proposal running. */
  acted: string;
  /** `{summary}`. */
  asked: string;
  approved: string;
  declined: string;
}

/** The server's statuses are the card's, one for one. */
export function proposalStatus(proposal: AgentProposal): ActionProposalStatus {
  return proposal.status;
}

/** `invoice_due_date` → "Invoice due date". */
export function humanizeKey(key: string): string {
  const words = key.replace(/([a-z])([A-Z])/g, '$1 $2').replace(/[_-]+/g, ' ').trim().toLowerCase();
  return words.charAt(0).toUpperCase() + words.slice(1);
}

function display(value: unknown): string {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'string') return value;
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  if (Array.isArray(value)) return value.map(display).join(', ');
  return JSON.stringify(value);
}

/** One change in an impact plan (WHIT-764 `ManifestImpact`). */
export interface ImpactChange {
  effect: 'safe' | 'data' | 'destructive';
  change: string;
  path: string;
  records?: number;
  message: string;
  messageAr: string;
}

/**
 * A Whity Builder publish or roll back, as core previewed it for the person
 * approving (WHIT-764, WHIT-1042; TplImpactPlan).
 */
export interface ImpactPlan {
  kind: 'publish' | 'rollback';
  label: { en: string; ar: string } | null;
  plugin: string;
  from: string | null;
  to: string;
  level: 'safe' | 'data' | 'destructive';
  changes: ImpactChange[];
  tests: { passed: number; failed: number; failures: { en: string; ar: string }[]; ranAt: string | null } | null;
}

/** The workspace plugin operations whose proposals carry an impact plan. */
export const IMPACT_PLAN_TOOLS = ['post_api_workspace_plugins', 'post_api_workspace_plugins_plugin_versions_id_activate'];

const EFFECTS = ['safe', 'data', 'destructive'] as const;

function isEffect(value: unknown): value is ImpactChange['effect'] {
  return typeof value === 'string' && (EFFECTS as readonly string[]).includes(value);
}

/** The impact plan on a proposal, or null when it has none. */
export function impactPlanOf(proposal: Pick<AgentProposal, 'tool' | 'diff'>): ImpactPlan | null {
  const diff = proposal.diff as Record<string, unknown> | null | undefined;
  if (!IMPACT_PLAN_TOOLS.includes(proposal.tool) || !diff || Array.isArray(diff) || typeof diff !== 'object') return null;
  if (!isEffect(diff.level) || !Array.isArray(diff.changes) || typeof diff.to !== 'string') return null;
  const label = diff.label as { en?: unknown; ar?: unknown } | undefined;
  const tests = diff.tests as Record<string, unknown> | undefined;
  return {
    kind: diff.kind === 'rollback' ? 'rollback' : 'publish',
    label: label && typeof label.en === 'string' ? { en: label.en, ar: typeof label.ar === 'string' && label.ar ? label.ar : label.en } : null,
    plugin: typeof diff.plugin === 'string' ? diff.plugin : '',
    from: typeof diff.from === 'string' ? diff.from : null,
    to: diff.to,
    level: diff.level,
    changes: diff.changes
      .filter((row): row is Record<string, unknown> => typeof row === 'object' && row !== null && isEffect((row as Record<string, unknown>).effect))
      .map((row) => ({
        effect: row.effect as ImpactChange['effect'],
        change: String(row.change ?? ''),
        path: String(row.path ?? ''),
        records: typeof row.records === 'number' ? row.records : undefined,
        message: String(row.message ?? ''),
        messageAr: String(row.messageAr ?? row.message ?? ''),
      })),
    tests:
      tests && typeof tests === 'object'
        ? {
            passed: Number(tests.passed ?? 0),
            failed: Number(tests.failed ?? 0),
            failures: Array.isArray(tests.failures)
              ? tests.failures
                  .filter((f): f is { en: string; ar?: string } => typeof f === 'object' && f !== null && typeof (f as { en?: unknown }).en === 'string')
                  .map((f) => ({ en: f.en, ar: f.ar || f.en }))
              : [],
            ranAt: typeof tests.ranAt === 'string' ? tests.ranAt : null,
          }
        : null,
  };
}

/** The records a destructive plan stops showing values for. */
export function recordsLost(plan: ImpactPlan): number {
  return plan.changes.filter((c) => c.effect === 'destructive').reduce((sum, c) => sum + (c.records ?? 0), 0);
}

/** Approve waits: the tests failed, or a destructive plan has no tick yet. */
export function impactPlanBlocks(plan: ImpactPlan, acknowledged: boolean): boolean {
  return (plan.tests?.failed ?? 0) > 0 || (plan.level === 'destructive' && !acknowledged);
}

/**
 * What will change. A `diff` the operation previewed wins: a list of
 * `{label|field, before, after}`. Without one, the arguments the agent will
 * send are the change: one row per argument, with no "before".
 */
export function proposalChanges(proposal: AgentProposal): ActionProposalChange[] {
  // An impact plan has its own rows (ImpactPlanView), never the manifest as text.
  if (impactPlanOf(proposal) !== null) return [];
  const diff = proposal.diff as unknown;
  if (Array.isArray(diff)) {
    return diff
      .filter((row): row is Record<string, unknown> => typeof row === 'object' && row !== null)
      .map((row) => ({
        label: humanizeKey(String(row.label ?? row.field ?? '')),
        before: row.before === undefined ? undefined : display(row.before),
        after: display(row.after),
      }));
  }
  return Object.entries((proposal.arguments ?? {}) as Record<string, unknown>).map(([key, value]) => ({
    label: humanizeKey(key),
    after: display(value),
  }));
}

/** "30 minutes", "2 hours", "1 day": the undo window in words. */
export function undoWindowWords(seconds: number | null | undefined, words: ProposalWords): string | undefined {
  if (!seconds || seconds <= 0) return undefined;
  if (seconds < 3600) {
    const n = Math.max(1, Math.round(seconds / 60));
    return n === 1 ? words.minute : words.minutes.replace('{n}', String(n));
  }
  if (seconds < 86400) {
    const n = Math.round(seconds / 3600);
    return n === 1 ? words.hour : words.hours.replace('{n}', String(n));
  }
  const n = Math.round(seconds / 86400);
  return n === 1 ? words.day : words.days.replace('{n}', String(n));
}

/** The agent's name, or a plain word when its credential is gone. */
export function agentName(subject: Pick<AgentProposal, 'agent'>, words: ProposalWords): string {
  return (words.arabic && subject.agent?.nameAr) || subject.agent?.name || words.unknownAgent;
}

/** What it did or would do in the reader's language; the server's own summary otherwise. */
export function plainSummary(subject: Pick<AgentProposal, 'summary' | 'toolInfo'>, words: ProposalWords): string {
  return (words.arabic && subject.toolInfo?.plainNameAr) || subject.summary;
}

/** Oldest first: the queue's order (register 7.5b). */
export function oldestFirst(proposals: AgentProposal[]): AgentProposal[] {
  return [...proposals].sort((a, b) => a.id - b.id);
}

/**
 * The timeline from proposals alone: each one asked (dashed), and each
 * decision the person made. What an agent did with Allowed permissions comes
 * from the audit log with WHIT-811.
 */
export function proposalActivity(
  proposals: AgentProposal[],
  options: { words: ProposalWords; you: string; time: (iso: string) => string },
): AgentActivityItem[] {
  const { words, you, time } = options;
  const items: AgentActivityItem[] = [];
  for (const proposal of proposals) {
    const agent = agentName(proposal, words);
    const summary = plainSummary(proposal, words);
    items.push({
      id: `${proposal.id}-asked`,
      kind: 'propose',
      actor: agent,
      onBehalfOf: you,
      text: words.asked.replace('{summary}', summary),
      timeLabel: time(proposal.createdAt),
      dateTime: proposal.createdAt,
      tool: proposal.tool,
    });
    if (proposal.decidedAt && (proposal.status === 'approved' || proposal.status === 'declined')) {
      items.push({
        id: `${proposal.id}-decided`,
        kind: proposal.status === 'approved' ? 'approve' : 'undo',
        actor: you,
        actorKind: 'person',
        text: (proposal.status === 'approved' ? words.approved : words.declined).replace('{summary}', summary),
        timeLabel: time(proposal.decidedAt),
        dateTime: proposal.decidedAt,
      });
    }
  }
  // Newest first, by the ISO time each row carries.
  return items.sort((a, b) => ((a.dateTime ?? '') < (b.dateTime ?? '') ? 1 : (a.dateTime ?? '') > (b.dateTime ?? '') ? -1 : 0));
}

/** Seconds left to undo an action, or null when it can't be undone now. */
export function undoSecondsLeft(action: AgentAction, now: number): number | null {
  if (action.status !== 'done' || !action.undoableUntil) return null;
  const until = parseWireDate(action.undoableUntil);
  if (until === null) return null;
  const left = Math.floor((until.getTime() - now) / 1000);
  return left > 0 ? left : null;
}

/**
 * What agents did (`GET /api/me/agent-actions`), for the timeline: a filled
 * row, undone rows as undo. `extra` puts the undo bar under a row that has an
 * undo window.
 */
export function actionActivity(
  actions: AgentAction[],
  options: {
    words: ProposalWords;
    you: string;
    time: (value: string) => string;
    extra: (action: AgentAction) => AgentActivityItem['extra'];
  },
): AgentActivityItem[] {
  const { words, you, time, extra } = options;
  return actions.map((action) => ({
    id: `action-${action.id}`,
    kind: action.status === 'undone' ? 'undo' : 'act',
    actor: agentName(action, words),
    onBehalfOf: you,
    text: words.acted.replace('{summary}', plainSummary(action, words)),
    timeLabel: time(action.createdAt),
    dateTime: action.createdAt,
    tool: action.tool,
    extra: extra(action),
  }));
}

/** Newest first across rows from both lists, which stamp their times differently. */
export function newestFirst(items: AgentActivityItem[]): AgentActivityItem[] {
  const at = (item: AgentActivityItem) => parseWireDate(item.dateTime)?.getTime() ?? 0;
  return [...items].sort((a, b) => at(b) - at(a));
}

/** Whether a server timestamp falls on the viewer's today (read as UTC, as stored). */
export function isToday(value: string, now: Date = new Date()): boolean {
  const date = parseWireDate(value);
  if (date === null) return false;
  return (
    date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth() && date.getDate() === now.getDate()
  );
}
