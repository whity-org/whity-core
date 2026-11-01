import { forwardMcp, isMcpSubpath } from '@/lib/forward-mcp';

/**
 * `/mcp/<split>` and `/mcp/agents/<slug>`, forwarded to the backend (WHIT-765).
 * Only well-formed paths are forwarded; the backend decides whether they exist.
 */
type Context = { params: Promise<{ path: string[] }> };

async function handle(request: Request, { params }: Context): Promise<Response> {
  const { path } = await params;
  if (!isMcpSubpath(path)) return new Response(null, { status: 404 });
  return forwardMcp(request, `/mcp/${path.join('/')}`);
}

export const POST = handle;
export const GET = handle;
export const DELETE = handle;
