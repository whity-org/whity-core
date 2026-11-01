import { forwardOAuthMetadata } from '@/lib/forward-oauth-metadata';
import { isMcpSubpath } from '@/lib/forward-mcp';

/**
 * RFC 9728 metadata for a resource under `/mcp`: a split or an agent (WHIT-765).
 * Each is its own OAuth resource, so each has its own document.
 */
export async function GET(_request: Request, { params }: { params: Promise<{ path: string[] }> }) {
  const { path } = await params;
  if (!isMcpSubpath(path)) return new Response(null, { status: 404 });
  return forwardOAuthMetadata(`/.well-known/oauth-protected-resource/mcp/${path.join('/')}`);
}
