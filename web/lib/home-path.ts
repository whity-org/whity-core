/**
 * The instance's home page and its way back to the product beside it
 * (whity-org/whity-core#2386).
 *
 * The server decides both (`navigation.home_path`, `WHITY_HOME_PATH`, and the
 * back-to-product link; see src/Core/Navigation/HomeLinks.php) and sends them
 * with the branding, which the root layout renders on the server. So the
 * first paint already links home correctly, and a sign-in lands there without
 * waiting for another request.
 *
 * Shared by the server (`lib/branding.ts`) and the client
 * (`lib/branding-context.tsx`), so no `server-only` here.
 */

export const DEFAULT_HOME_PATH = '/dashboard';

export interface BackToProduct {
  url: string;
  label: string;
}

/** A path on this site, never `//host` or another scheme: anything else is the dashboard. */
export function safeHomePath(value: unknown): string {
  return typeof value === 'string' && /^\/(?![/\\])[^\s\\]*$/.test(value) && value.length <= 200
    ? value
    : DEFAULT_HOME_PATH;
}

/** The back link as the server sent it, or null when there is none or it is not safe to follow. */
export function parseBackToProduct(value: unknown): BackToProduct | null {
  if (typeof value !== 'object' || value === null) return null;
  const { url, label } = value as Record<string, unknown>;
  if (typeof url !== 'string' || typeof label !== 'string' || label === '') return null;
  const local = safeHomePath(url) === url;
  const https = /^https:\/\/[^\s/]+/i.test(url);
  return local || https ? { url, label } : null;
}
