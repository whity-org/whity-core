/**
 * READINESS CE-12, TM-12: the web app's Content Security Policy and its
 * report endpoint.
 */
import { buildCsp, cspNonce, CSP_REPORT_PATH } from '@/lib/csp';
import { describeReport, reportsIn } from '@/app/csp-report/route';

function directive(policy: string, name: string): string[] {
  const found = policy.split(';').map((d) => d.trim()).find((d) => d.startsWith(name + ' '));

  return found ? found.split(/\s+/).slice(1) : [];
}

test('scripts need this request\'s nonce, and nothing inline or eval runs in production', () => {
  const policy = buildCsp('abc123', false);
  const scripts = directive(policy, 'script-src');

  expect(scripts).toEqual(expect.arrayContaining(["'nonce-abc123'", "'strict-dynamic'"]));
  expect(scripts).not.toContain("'unsafe-inline'");
  expect(scripts).not.toContain("'unsafe-eval'");
  expect(directive(buildCsp('abc123', true), 'script-src')).toContain("'unsafe-eval'");
});

test('plugins, forms and the page base are held to this origin', () => {
  const policy = buildCsp('n', false);

  expect(directive(policy, 'object-src')).toEqual(["'none'"]);
  expect(directive(policy, 'base-uri')).toEqual(["'self'"]);
  expect(directive(policy, 'form-action')).toEqual(["'self'"]);
  expect(directive(policy, 'frame-ancestors')).toEqual(["'none'"]);
  // TM-12: a plugin frame that navigates elsewhere is reported.
  expect(directive(policy, 'frame-src')).toEqual(["'self'", 'https://challenges.cloudflare.com']);
  expect(directive(policy, 'report-uri')).toEqual([CSP_REPORT_PATH]);
});

test('each nonce is fresh and long enough', () => {
  const a = cspNonce();
  const b = cspNonce();

  expect(a).not.toBe(b);
  expect(atob(a)).toHaveLength(16);
});

test('a report becomes one short line without the query string', () => {
  const legacy = reportsIn({
    'csp-report': {
      'effective-directive': 'script-src-elem',
      'blocked-uri': 'https://evil.example/x.js?token=secret',
      'document-uri': 'https://app.example/forms/5?code=abc',
    },
  });
  const modern = reportsIn([{ type: 'csp-violation', body: { effectiveDirective: 'frame-src', blockedURL: 'https://elsewhere.example/', documentURL: 'https://app.example/p' } }]);

  expect(describeReport(legacy[0])).toBe('[csp] script-src-elem blocked=https://evil.example/x.js page=https://app.example/forms/5');
  expect(describeReport(modern[0])).toBe('[csp] frame-src blocked=https://elsewhere.example/ page=https://app.example/p');
  expect(reportsIn('nonsense')).toEqual([]);
});

test('a report can not split or forge a log line', () => {
  const [report] = reportsIn({
    'csp-report': {
      'effective-directive': 'script-src\n[csp] forged',
      'blocked-uri': 'https://evil.example/a\r\n[csp] forged line',
      'document-uri': 'https://app.example/p\u2028x',
    },
  });

  const line = describeReport(report);
  expect(line).not.toMatch(/[\r\n\u2028]/);
  expect(line.startsWith('[csp] ')).toBe(true);
});
