import { WHITY_BLUE } from '@/lib/pwa/shared';

/** The push icon's query, read strictly (WHIT-1076). */

/** One Latin letter or digit, as the server sends it. */
export function letterFrom(raw: string | null): string | null {
  return raw && /^[A-Z0-9]$/.test(raw) ? raw : null;
}

/** A six-digit hex colour without '#', else Whity Blue. */
export function colourFrom(raw: string | null): string {
  return raw && /^[0-9a-f]{6}$/i.test(raw) ? `#${raw.toLowerCase()}` : WHITY_BLUE;
}
