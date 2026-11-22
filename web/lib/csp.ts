/**
 * The web app's Content Security Policy (READINESS CE-12, TM-12).
 *
 * Sent as `Content-Security-Policy-Report-Only` by `proxy.ts`, with a fresh
 * nonce per request, so it reports what it would block without blocking
 * anything yet. Once the reports at `/csp-report` are quiet, it moves to the
 * enforced header. Until then the enforced header stays `frame-ancestors
 * 'none'` (next.config.ts).
 *
 * - Scripts: only those carrying this request's nonce, and what they load
 *   (`'strict-dynamic'`), so an injected `<script>` or inline handler can't
 *   run. Next puts the nonce on its own scripts when it finds it in the
 *   request's policy header; the three blocking bootstraps in the root layout
 *   take it as a prop. Development adds `'unsafe-eval'`, which React needs
 *   there for its error stacks and never in production.
 * - Frames: the app itself (plugin pages) and the bot check, so a plugin page
 *   that navigates its frame elsewhere shows up (TM-12).
 * - Styles stay open (`'unsafe-inline'`): the app sets inline styles widely
 *   and they can't run code. Images, fonts and connections are left wide here
 *   on purpose; the reports show what can be narrowed.
 */

export const CSP_REPORT_PATH = '/csp-report';

/** Hosts the bot check (Cloudflare Turnstile) frames itself from. */
const BOT_CHECK_ORIGIN = 'https://challenges.cloudflare.com';

export function cspNonce(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  let binary = '';
  for (const b of bytes) binary += String.fromCharCode(b);

  return btoa(binary);
}

export function buildCsp(nonce: string, isDevelopment: boolean): string {
  const directives: Array<[string, string[]]> = [
    ['default-src', ["'self'"]],
    ['script-src', ["'self'", `'nonce-${nonce}'`, "'strict-dynamic'", ...(isDevelopment ? ["'unsafe-eval'"] : [])]],
    ['style-src', ["'self'", "'unsafe-inline'"]],
    ['img-src', ["'self'", 'blob:', 'data:', 'https:']],
    ['font-src', ["'self'", 'data:']],
    ['connect-src', ["'self'", 'https:', 'wss:', ...(isDevelopment ? ['ws:'] : [])]],
    ['frame-src', ["'self'", BOT_CHECK_ORIGIN]],
    ['child-src', ["'self'", BOT_CHECK_ORIGIN]],
    ['worker-src', ["'self'", 'blob:']],
    ['object-src', ["'none'"]],
    ['base-uri', ["'self'"]],
    ['form-action', ["'self'"]],
    ['frame-ancestors', ["'none'"]],
    ['report-uri', [CSP_REPORT_PATH]],
  ];

  return directives.map(([name, values]) => `${name} ${values.join(' ')}`).join('; ');
}
