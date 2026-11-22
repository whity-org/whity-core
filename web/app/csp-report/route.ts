/**
 * `POST /csp-report` — where browsers send what the Content Security Policy
 * would have blocked (READINESS CE-12; the policy is in lib/csp.ts).
 *
 * The policy ships Report-Only, so these reports are how we learn what has to
 * change before it is enforced. Each one becomes a single line in the web
 * service's log: the directive, the blocked source and the page, never the
 * query string (it can carry tokens) and never more than a few hundred
 * characters. No sign-in, as browsers send them without credentials; a
 * per-process cap keeps a flood from filling the log.
 */
export const dynamic = 'force-dynamic';

const MAX_BODY_BYTES = 16 * 1024;
const MAX_LINES_PER_MINUTE = 60;

let windowStart = 0;
let linesThisWindow = 0;

type Report = Record<string, unknown>;

/** Control characters out, so a report can't forge or split a log line. */
function printable(value: string): string {
  return value.replace(/[\u0000-\u001f\u007f-\u009f\u2028\u2029]/g, '');
}

function withoutQuery(value: unknown): string {
  if (typeof value !== 'string') return '';
  const cut = value.split(/[?#]/)[0] ?? '';

  return printable(cut).slice(0, 200);
}

/** The reports in a body, in either format browsers send. */
export function reportsIn(body: unknown): Report[] {
  if (Array.isArray(body)) {
    // Reporting API: [{ type: 'csp-violation', body: {...} }]
    return body
      .filter((r): r is { body: Report } => typeof r === 'object' && r !== null && typeof (r as { body?: unknown }).body === 'object')
      .map((r) => r.body);
  }
  if (typeof body === 'object' && body !== null && typeof (body as { 'csp-report'?: unknown })['csp-report'] === 'object') {
    return [(body as { 'csp-report': Report })['csp-report']];
  }

  return [];
}

/** One log line for a report: what was blocked, by which directive, on which page. */
export function describeReport(report: Report): string {
  const directive = printable(String(report['effectiveDirective'] ?? report['effective-directive'] ?? report['violated-directive'] ?? '')).slice(0, 60);
  const blocked = withoutQuery(report['blockedURL'] ?? report['blocked-uri']);
  const page = withoutQuery(report['documentURL'] ?? report['document-uri']);

  return `[csp] ${directive} blocked=${blocked || '(inline)'} page=${page}`;
}

export async function POST(request: Request): Promise<Response> {
  const text = await request.text();
  if (text.length > MAX_BODY_BYTES) {
    return new Response(null, { status: 413 });
  }

  let body: unknown = null;
  try {
    body = JSON.parse(text);
  } catch {
    return new Response(null, { status: 400 });
  }

  const now = Date.now();
  if (now - windowStart > 60_000) {
    windowStart = now;
    linesThisWindow = 0;
  }
  for (const report of reportsIn(body)) {
    if (linesThisWindow >= MAX_LINES_PER_MINUTE) break;
    linesThisWindow += 1;
    console.warn(describeReport(report));
  }

  return new Response(null, { status: 204 });
}
