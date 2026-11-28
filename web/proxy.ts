import { NextResponse, type NextRequest } from 'next/server';
import { isPathHidden } from '@/lib/routes/hidden';
import { hiddenIdsForGuard } from '@/lib/routes/hidden-cache';
import { buildCsp, cspNonce } from '@/lib/csp';
import { embedWorkspace, frameAncestorsFor } from '@/lib/booking/embed-policy';

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
  // READINESS CE-12: a fresh nonce for every page, and the policy that names
  // it. Next reads the policy from the REQUEST to put the nonce on its own
  // scripts (it reads the Report-Only header too); the layout reads `x-nonce`
  // for the three blocking bootstraps. Report-Only for now (lib/csp.ts).
  const nonce = cspNonce();
  const policy = buildCsp(nonce, process.env.NODE_ENV === 'development');
  const requestHeaders = new Headers(request.headers);
  requestHeaders.set('x-nonce', nonce);
  requestHeaders.set('content-security-policy-report-only', policy);

  // HD.5 and the customer area (WHIT-1416, design section 41): `/@handle` and
  // everything under it is served by `app/w/[handle]`. Next reads an `@`
  // folder as a parallel-route slot, so the address people share is rewritten
  // here instead. The handle only names the workspace; the API decides access.
  const area = customerAreaPath(request.nextUrl.pathname);
  if (area !== null) {
    const target = new URL(area + request.nextUrl.search, request.url);
    const rewritten = NextResponse.rewrite(target, { request: { headers: requestHeaders } });
    rewritten.headers.set('Content-Security-Policy-Report-Only', policy);

    return rewritten;
  }

  const response = isPathHidden(request.nextUrl.pathname, await hiddenIdsForGuard())
    // A path no route answers: Next renders the app's not-found page.
    ? NextResponse.rewrite(new URL('/_whity/hidden', request.url), { status: 404, request: { headers: requestHeaders } })
    : NextResponse.next({ request: { headers: requestHeaders } });
  response.headers.set('Content-Security-Policy-Report-Only', policy);

  // WHIT-1445 (AP.24): the booking widget's page is the one page another site
  // may frame, and only the sites the workspace listed under Your website.
  // next.config.ts leaves its framing headers to this.
  const embedded = embedWorkspace(request.nextUrl.pathname);
  if (embedded !== null) {
    response.headers.set('Content-Security-Policy', `frame-ancestors ${await frameAncestorsFor(embedded)}`);
  }

  return response;
}

/**
 * `/@example-bakery/bills` → `/w/example-bakery/bills`; null for any other
 * path. The handle follows HD.1's shape (lower case, digits, hyphens), so an
 * odd `@` path is left alone and gets the normal not-found page.
 */
export function customerAreaPath(pathname: string): string | null {
  const match = /^\/@([a-z0-9][a-z0-9-]{0,63})(\/[A-Za-z0-9/_.-]*)?$/.exec(pathname);
  if (match === null) {
    return null;
  }

  return `/w/${match[1]}${match[2] ?? ''}`.replace(/\/$/, '');
}

export const config = {
  // Pages only. The API, MCP, build files and Next's own assets have no menu
  // id, so they never go through the guard.
  matcher: [
    '/((?!api/|mcp|_next/|\\.well-known/|web-build|sw\\.js|manifest\\.webmanifest|openapi\\.json|app-icon/|push-icon/|favicon).*)',
  ],
};
