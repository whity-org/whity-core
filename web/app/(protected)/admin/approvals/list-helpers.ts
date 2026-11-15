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
