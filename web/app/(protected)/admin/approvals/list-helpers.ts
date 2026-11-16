import { parseWireDate } from '@whity/features/datetime';
import { moneyText, parseMoney } from '@whity/ui/money/currency';
import type { AgentProposal } from '@/components/agents/proposals';

/**
 * Small, testable helpers for the Approvals list (AQ.1). No React here.
 */

export interface WaitedWords {
  /** `{n}` minutes, short: "20 min". */
  minutes: string;
  /** "5 h". */
  hours: string;
  /** "2 d". */
  days: string;
}

/** How long a proposal has waited, in the largest whole unit. */
export function waitedWords(createdAt: string, now: number, words: WaitedWords): string {
  const created = parseWireDate(createdAt);
  if (created === null) return '';
  const minutes = Math.max(0, Math.floor((now - created.getTime()) / 60_000));
  if (minutes < 60) return words.minutes.replace('{n}', String(Math.max(1, minutes)));
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return words.hours.replace('{n}', String(hours));
  return words.days.replace('{n}', String(Math.floor(hours / 24)));
}

const AMOUNT_KEYS = ['amount', 'total', 'grand_total', 'value'];
const CURRENCY_KEYS = ['currency', 'currency_code'];

/**
 * The amount an agent's call names, in the currency it names, as minor units
 * of that currency. Null when the call has no amount, no currency, or a figure
 * with more decimals than the currency has.
 */
export function proposalMoney(proposal: AgentProposal): { currency: string; minor: number } | null {
  const args = (proposal.arguments ?? {}) as Record<string, unknown>;
  const amountKey = AMOUNT_KEYS.find((key) => typeof args[key] === 'number' || (typeof args[key] === 'string' && /^-?\d+(\.\d+)?$/.test(args[key] as string)));
  if (amountKey === undefined) return null;
  const currencyKey = CURRENCY_KEYS.find((key) => typeof args[key] === 'string' && /^[A-Za-z]{3}$/.test(args[key] as string));
  if (currencyKey === undefined) return null;
  const currency = (args[currencyKey] as string).toUpperCase();
  const value = Number(args[amountKey]);
  if (!Number.isFinite(value)) return null;
  try {
    // Through the decimal string, never `value * 1000`: the exponent is the
    // currency's own (2 for USD, 3 for JOD), and too many decimals is refused.
    return { currency, minor: parseMoney(String(value), currency) };
  } catch {
    return null;
  }
}

/** The amount as people read it (CU.2: $312.00, JOD 312.000), or null. */
export function proposalAmount(proposal: AgentProposal): string | null {
  const money = proposalMoney(proposal);
  return money === null ? null : moneyText(money.minor, money.currency);
}

/* ── Decided (TplApprovalsDecided) ─────────────────────────────────────────── */

export type DecidedOutcome = 'approved' | 'declined' | 'expired' | 'undone';

/** When a decided proposal was settled: decided, undone, or let expire. */
export function settledAt(proposal: AgentProposal): string {
  if (proposal.status === 'undone' && proposal.action?.undoneAt) return proposal.action.undoneAt;
  if (proposal.status === 'expired') return proposal.expiresAt;
  return proposal.decidedAt ?? proposal.createdAt;
}

function dayKey(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export interface DecidedFilters {
  outcome: DecidedOutcome[];
  /** Agent names, as shown. */
  agent: string[];
  /** Only what was settled in the last this-many days; null for any time. */
  days: number | null;
}

/** The proposals that match the filters, newest first. */
export function filterDecided(
  proposals: AgentProposal[],
  filters: DecidedFilters,
  agentOf: (proposal: AgentProposal) => string,
  now: number,
): AgentProposal[] {
  const at = (p: AgentProposal) => parseWireDate(settledAt(p))?.getTime() ?? 0;
  return proposals
    .filter((p) => p.status !== 'waiting')
    .filter((p) => filters.outcome.length === 0 || filters.outcome.includes(p.status as DecidedOutcome))
    .filter((p) => filters.agent.length === 0 || filters.agent.includes(agentOf(p)))
    .filter((p) => filters.days === null || now - at(p) <= filters.days * 86_400_000)
    .sort((a, b) => at(b) - at(a));
}

export interface DayGroup {
  /** `today`, `yesterday`, or the local calendar day `YYYY-MM-DD`. */
  key: string;
  /** A wire time on that day, for formatting its heading. */
  sample: string;
  items: AgentProposal[];
}

/** Grouped by the viewer's calendar day, in the order given (newest first). */
export function groupByDay(proposals: AgentProposal[], now: Date = new Date()): DayGroup[] {
  const today = dayKey(now);
  const yesterday = dayKey(new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1));
  const groups: DayGroup[] = [];
  for (const proposal of proposals) {
    const when = settledAt(proposal);
    const date = parseWireDate(when);
    const day = date === null ? 'unknown' : dayKey(date);
    const key = day === today ? 'today' : day === yesterday ? 'yesterday' : day;
    const last = groups[groups.length - 1];
    if (last && last.key === key) last.items.push(proposal);
    else groups.push({ key, sample: when, items: [proposal] });
  }
  return groups;
}

function csvCell(value: string): string {
  return /[",\n\r]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value;
}

/** A CSV for the audit (note 3): one row per proposal, a header row first. */
export function decidedCsv(header: string[], rows: string[][]): string {
  return [header, ...rows].map((row) => row.map(csvCell).join(',')).join('\r\n') + '\r\n';
}
