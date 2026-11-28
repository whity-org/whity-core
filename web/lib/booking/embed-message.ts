import { cleanAncestors } from '@/lib/booking/embed-policy';

/**
 * The booking page inside a website tells the widget what happened (WHIT-1445,
 * AP.24), and only that website: never `'*'`.
 *
 * `embed.js` puts the website's own origin in the page's address (`?parent=`).
 * The message goes to it only when it is one of the sites the workspace listed
 * under Your website, read from the public `GET /api/v1/public/booking/{ws}/embed`.
 * A page that isn't framed, a parent not on the list, or a list that can't
 * be read sends nothing. It says only what happened, never who booked.
 */
export type EmbedEvent = 'booked';

export interface EmbedMessageDeps {
  /** The page's own `location.search`. */
  search: string;
  /** The window that frames the page, or null when it isn't framed. */
  parent: Pick<Window, 'postMessage'> | null;
  /** The workspace's Your website list, as `frame-ancestors` gives it. */
  frameAncestors: (workspace: string) => Promise<string>;
}

export async function tellEmbedder(workspace: string, event: EmbedEvent, deps: EmbedMessageDeps): Promise<boolean> {
  if (deps.parent === null) return false;
  const parentOrigin = new URLSearchParams(deps.search).get('parent');
  if (parentOrigin === null || parentOrigin === '') return false;
  let listed: string[];
  try {
    const ancestors = cleanAncestors(await deps.frameAncestors(workspace));
    listed = ancestors === "'none'" ? [] : ancestors.split(' ');
  } catch {
    return false;
  }
  if (!listed.includes(parentOrigin)) return false;
  deps.parent.postMessage({ type: 'whity-booking', event }, parentOrigin);
  return true;
}

/** The list as the browser reads it, from the public route. */
export async function publicFrameAncestors(workspace: string): Promise<string> {
  const response = await fetch(`/api/v1/public/booking/${encodeURIComponent(workspace)}/embed`, { cache: 'no-store' });
  if (!response.ok) return "'none'";
  const body = (await response.json()) as { data?: { frame_ancestors?: unknown } };
  return typeof body.data?.frame_ancestors === 'string' ? body.data.frame_ancestors : "'none'";
}
