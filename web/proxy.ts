import { NextResponse, type NextRequest } from 'next/server';
import { isPathHidden } from '@/lib/routes/hidden';
import { hiddenIdsForGuard } from '@/lib/routes/hidden-cache';

/**
 * The route guard for hidden pages (whity-org/whity-core#2386).
 *
 * An operator hides menu items and Settings pages by id (`navigation.hidden_ids`
 * or `WHITY_HIDDEN_NAV_IDS` on the backend). The server already leaves them out
 * of every menu; this makes their pages answer 404 too, so a bookmark or a
 * typed address finds nothing either. The path maps to its ids through the
 * route manifest (lib/routes/hidden.ts); the list comes from the backend,
 * cached (lib/routes/hidden-cache.ts).
 */
export async function proxy(request: NextRequest): Promise<NextResponse> {
  if (isPathHidden(request.nextUrl.pathname, await hiddenIdsForGuard())) {
    // A path no route answers: Next renders the app's not-found page.
    return NextResponse.rewrite(new URL('/_whity/hidden', request.url), { status: 404 });
  }
  return NextResponse.next();
}

export const config = {
  // Pages only. The API, MCP, build files and Next's own assets have no menu
  // id, so they never go through the guard.
  matcher: [
    '/((?!api/|mcp|_next/|\\.well-known/|web-build|sw\\.js|manifest\\.webmanifest|openapi\\.json|app-icon/|push-icon/|favicon).*)',
  ],
};
