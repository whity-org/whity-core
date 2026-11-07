/**
 * What the "Verify a document" scanner may open (#1734).
 *
 * A QR code is untrusted input: anyone can print one. The scanner therefore
 * follows only THIS instance's own verification page, `/verify/{token}` on the
 * host the reader is already on, and turns everything else down with a reason
 * instead of navigating. It never opens a scanned URL as given: it rebuilds a
 * same-origin path from the token alone, so a code cannot smuggle a query,
 * a fragment or a `//other.host` path through.
 *
 * Typed by hand, the reader may paste the whole link, or the bare 64-character
 * code a document's QR carries (DocumentQrService::newToken, 256 bits as hex).
 */

/** The token DocumentQrService mints: 32 random bytes, hex-encoded. */
const BARE_TOKEN_RE = /^[0-9a-f]{64}$/i;
/** A token segment inside one of our own links: URL-safe characters only. */
const LINK_TOKEN_RE = /^[A-Za-z0-9_-]{16,128}$/;
const VERIFY_PATH_RE = /^\/verify\/([^/?#]+)\/?$/;

export type VerifyInput =
  | { ok: true; token: string; path: string }
  | { ok: false; reason: 'empty' | 'foreign' | 'invalid' };

function accepted(token: string): VerifyInput {
  return { ok: true, token, path: `/verify/${encodeURIComponent(token)}` };
}

/**
 * Read a scanned or typed value against the page's own origin
 * (`window.location.origin`). Same host on http or https counts as ours, so a
 * code printed with the public `https://` address still opens behind a local
 * `http://` proxy; any other host is `foreign`.
 */
export function parseVerifyInput(raw: string, origin: string): VerifyInput {
  const value = raw.trim();
  if (value === '') return { ok: false, reason: 'empty' };

  if (BARE_TOKEN_RE.test(value)) return accepted(value.toLowerCase());

  let url: URL;
  try {
    // A hand-typed link often drops the scheme ("app.whity.dev/verify/…").
    url = new URL(/^[a-z][a-z0-9+.-]*:/i.test(value) ? value : `https://${value}`);
  } catch {
    return { ok: false, reason: 'invalid' };
  }

  let own: URL;
  try {
    own = new URL(origin);
  } catch {
    return { ok: false, reason: 'invalid' };
  }

  if (url.protocol !== 'https:' && url.protocol !== 'http:') return { ok: false, reason: 'invalid' };
  if (url.username !== '' || url.password !== '') return { ok: false, reason: 'foreign' };
  if (url.host.toLowerCase() !== own.host.toLowerCase()) return { ok: false, reason: 'foreign' };

  const match = VERIFY_PATH_RE.exec(url.pathname);
  if (!match) return { ok: false, reason: 'foreign' };

  let token: string;
  try {
    token = decodeURIComponent(match[1]);
  } catch {
    return { ok: false, reason: 'invalid' };
  }
  return LINK_TOKEN_RE.test(token) ? accepted(token) : { ok: false, reason: 'invalid' };
}
