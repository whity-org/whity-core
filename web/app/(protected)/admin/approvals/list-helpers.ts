import { parseWireDate } from '@whity/features/datetime';
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
 * The amount a proposal is about, when its arguments say one: "JOD 312.000".
 * Amounts are typed in normal units with the currency's decimals (register,
 * money), so they are shown as given, with three decimals for JOD-like
 * currencies and two otherwise. Null when the call has no amount.
 *
 * @param raw "JOD 312" without padding, for adding up.
 */
export function proposalAmount(proposal: AgentProposal, raw = false): string | null {
  const args = (proposal.arguments ?? {}) as Record<string, unknown>;
  const amountKey = AMOUNT_KEYS.find((key) => typeof args[key] === 'number' || (typeof args[key] === 'string' && /^-?\d+(\.\d+)?$/.test(args[key] as string)));
  if (amountKey === undefined) return null;
  const currencyKey = CURRENCY_KEYS.find((key) => typeof args[key] === 'string' && (args[key] as string).length === 3);
  const currency = currencyKey ? (args[currencyKey] as string).toUpperCase() : null;
  if (currency === null) return null;
  const value = Number(args[amountKey]);
  if (!Number.isFinite(value)) return null;
  if (raw) return `${currency} ${value}`;
  const decimals = ['JOD', 'KWD', 'BHD', 'OMR', 'TND', 'IQD', 'LYD'].includes(currency) ? 3 : 2;
  return `${currency} ${value.toFixed(decimals)}`;
}
