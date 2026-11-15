/**
 * Minor units, major units, and the exponent that separates them — the client
 * half of `Whity\Core\Money\Currency`.
 *
 * WHY THERE IS A CLIENT HALF AT ALL. Amounts cross the wire as integers of
 * minor units, which is the only representation that does not lose money to
 * floating point. Turning 5000 into something a person reads therefore happens
 * here, and it cannot be done without knowing that the Jordanian dinar has
 * THREE decimal places: 5000 fils is 5.000 JOD, not 50.00. Dividing by 100 —
 * which is what every hand-rolled formatter does — shows every customer an
 * amount ten times too large.
 *
 * WHY `Intl` IS NOT THE SOURCE OF THE EXPONENT. It is the obvious idea — the
 * engine ships ISO 4217 data, so why transcribe a table? — and it is wrong.
 * `Intl.NumberFormat(...).resolvedOptions().maximumFractionDigits` reports
 * CLDR's DISPLAY convention, not the currency's official exponent, and the two
 * disagree for thirteen currencies whose minor unit is worthless in practice.
 * ICU says the Iraqi dinar has 0 decimal places; ISO 4217 says 3. Lebanese
 * pounds, Afghanis, Serbian dinars and ten others are the same story with 0
 * against 2.
 *
 * Deriving the exponent from `Intl` here would therefore have made the client
 * disagree with the server about what an IQD amount MEANS — by a factor of a
 * thousand — while every JOD and USD test passed. So the table below is the
 * authority, it is ISO's numbers, and it is identical to the PHP one by
 * construction; a test asserts the two tables entry-for-entry and separately
 * pins the ICU divergences so they cannot change unnoticed.
 *
 * HOW AN AMOUNT IS WRITTEN (design 3.5.1, CU.2). `toDecimalString` is exact —
 * integer arithmetic only. `moneyText` (and `formatMoney`, its old name) puts
 * that figure in the one form the register gives for every language: a symbol
 * before the number for dollars and euros ($312.00), otherwise the code and a
 * space (JOD 312.000), Western digits, a real minus (−$35.00). Until 3.5.1
 * this used `Intl`, which gave Arabic-Indic digits and moved the symbol by
 * locale; the register now keeps amounts in Western digits in Arabic too.
 */

/** Two decimal places, which is every active ISO 4217 code not listed below. */
const DEFAULT_EXPONENT = 2;

/**
 * Every active ISO 4217 currency whose exponent is not two. THE AUTHORITY, not
 * a fallback: see the note above on why `Intl` cannot play this role. Identical
 * to the PHP table by construction — `currency-exponent-parity.test.ts` asserts
 * it entry for entry.
 */
const EXPONENT_EXCEPTIONS: Readonly<Record<string, number>> = {
  BHD: 3, IQD: 3, JOD: 3, KWD: 3, LYD: 3, OMR: 3, TND: 3,
  BIF: 0, CLP: 0, DJF: 0, GNF: 0, ISK: 0, JPY: 0, KMF: 0, KRW: 0, PYG: 0,
  RWF: 0, UGX: 0, UYI: 0, VND: 0, VUV: 0, XAF: 0, XOF: 0, XPF: 0,
  CLF: 4, UYW: 4,
};

const exponentCache = new Map<string, number>();

/** Uppercased and trimmed, or `null` when it is not a three-letter code. */
function normalize(currency: string): string | null {
  const code = currency.trim().toUpperCase();
  return /^[A-Z]{3}$/.test(code) ? code : null;
}

/**
 * The currency's ISO 4217 exponent.
 *
 * Throws on a malformed code rather than assuming two, because an amount
 * rendered against a guessed currency is wrong in a way nobody can see.
 */
export function currencyExponent(currency: string): number {
  const code = normalize(currency);
  if (code === null) {
    throw new Error(`Not an ISO 4217 currency code: '${currency}'`);
  }

  const cached = exponentCache.get(code);
  if (cached !== undefined) return cached;

  // The table, never Intl — see the module note. Asking the engine here would
  // silently make this disagree with the server for IQD, LBP and eleven others.
  const exponent = EXPONENT_EXCEPTIONS[code] ?? DEFAULT_EXPONENT;

  exponentCache.set(code, exponent);
  return exponent;
}

/** 1000 for JOD, 100 for USD, 1 for JPY. */
export function minorUnitsPerMajor(currency: string): number {
  return 10 ** currencyExponent(currency);
}

/**
 * Minor units as an exact decimal string: `5000` JOD becomes `"5.000"`.
 *
 * Integer arithmetic throughout, so this is correct for every amount a
 * JavaScript integer holds and never introduces a fractional artefact of its
 * own. No currency code is appended — that is the caller's or `Intl`'s job.
 */
export function toDecimalString(minorUnits: number, currency: string): string {
  if (!Number.isInteger(minorUnits)) {
    throw new Error(
      `Expected an integer of minor units, got ${minorUnits}. A fractional ` +
        'minor unit is not an amount of money.',
    );
  }

  const exponent = currencyExponent(currency);
  if (exponent === 0) return String(minorUnits);

  // The sign is taken off first: -1 fil must render "-0.001", and leaving the
  // minus in the arithmetic produces "0.-01".
  const sign = minorUnits < 0 ? '-' : '';
  const magnitude = Math.abs(minorUnits);
  const divisor = 10 ** exponent;

  const major = Math.floor(magnitude / divisor);
  const minor = magnitude % divisor;

  return `${sign}${major}.${String(minor).padStart(exponent, '0')}`;
}

/**
 * The currencies written with a symbol before the number (CU.2), as the
 * design's `Whity.currencies` has them. Every other currency writes its code.
 * Identical to the PHP `Currency::SYMBOLS`.
 */
const SYMBOLS: Readonly<Record<string, string>> = { USD: '$', EUR: '€' };

/** The minus a person reads (U+2212), as the design writes −$35.00. */
const MINUS = '−';

/** The symbol CU.2 writes before the number, or null when the code is written. */
export function currencySymbol(currency: string): string | null {
  const code = normalize(currency);
  return code === null ? null : (SYMBOLS[code] ?? null);
}

/**
 * The sub-unit's name for the currencies the design names one for — what
 * MoneyInput's decimals message says in brackets.
 */
const SUB_UNITS: Readonly<Record<string, string>> = { USD: 'cents', EUR: 'cents', JOD: 'fils', SAR: 'halalas', AED: 'fils' };

export function currencySubUnit(currency: string): string | null {
  const code = normalize(currency);
  return code === null ? null : (SUB_UNITS[code] ?? null);
}

/**
 * The amount as a person reads it, by CU.2: "$312.00", "−$35.00",
 * "JOD 1,234.500", "SAR 312.00". The same text as the PHP
 * `Currency::display`, in every language.
 *
 * Western digits and comma grouping in English and Arabic alike (CU.1, 3.7b):
 * an amount is a code, not prose, so the Arabic page shows the same figures.
 * The caller isolates it (`<bdi>` or the `Money` component) so it never
 * flips inside Arabic text.
 *
 * Exact: built from `toDecimalString`, so no float is involved.
 */
export function moneyText(minorUnits: number, currency: string): string {
  const code = normalize(currency);
  if (code === null) {
    throw new Error(`Not an ISO 4217 currency code: '${currency}'`);
  }

  const decimal = toDecimalString(Math.abs(minorUnits), code);
  const [whole = '0', fraction] = decimal.split('.');
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const number = fraction === undefined ? grouped : `${grouped}.${fraction}`;
  const symbol = SYMBOLS[code];

  return (minorUnits < 0 ? MINUS : '') + (symbol !== undefined ? `${symbol}${number}` : `${code} ${number}`);
}

/**
 * The amount as a person reads it. Since design 3.5.1 this is `moneyText`:
 * one form in every language (CU.2), so `locale` no longer changes it. Kept
 * so existing callers need not change.
 */
export function formatMoney(
  minorUnits: number,
  currency: string,
  _locale?: string,
): string {
  return moneyText(minorUnits, currency);
}

/**
 * Read a human-entered major-unit amount into minor units.
 *
 * Mirrors the PHP `Currency::parse` exactly, and that exactness is the point:
 * if the client accepted what the server refuses, the user would see a
 * validation error on an amount the form told them was fine; if it accepted
 * what the server silently rounds, they would be billed something they did not
 * type.
 *
 * So: more precision than the currency has is REFUSED, not rounded, and group
 * separators are refused rather than guessed at — "1.234,56" and "1,234.56"
 * are the same string to different halves of the world and mean amounts a
 * thousand times apart.
 */
export function parseMoney(input: string, currency: string): number {
  const code = normalize(currency);
  if (code === null) {
    throw new Error(`Not an ISO 4217 currency code: '${currency}'`);
  }

  const exponent = currencyExponent(code);
  const trimmed = input.trim();

  if (trimmed === '') {
    throw new Error(`Cannot read an empty amount as ${code}.`);
  }

  const match = /^(-?)(\d+)(?:\.(\d*))?$/.exec(trimmed);
  if (match === null) {
    throw new Error(
      `Cannot read "${input}" as ${code}: expected digits with at most one "." ` +
        'and an optional leading "-". Group and decimal separators are ambiguous ' +
        'between locales and are refused rather than guessed at.',
    );
  }

  const [, sign, whole, fraction = ''] = match;

  if (fraction.length > exponent) {
    throw new Error(
      `Cannot read "${input}" as ${code}: ${code} has ${exponent} decimal ` +
        `place${exponent === 1 ? '' : 's'}, and this has ${fraction.length}. ` +
        'Refusing to round an amount somebody typed.',
    );
  }

  const digits = whole + fraction.padEnd(exponent, '0');
  const value = Number(`${sign}${digits}`);

  if (!Number.isSafeInteger(value)) {
    throw new Error(
      `Cannot read "${input}" as ${code}: ${digits} minor units is beyond the ` +
        'range in which an integer is exact.',
    );
  }

  return value;
}
