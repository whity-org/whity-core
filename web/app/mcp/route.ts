import { forwardMcp } from '@/lib/forward-mcp';

/** `/mcp`, forwarded to the backend (WHIT-697); the rules are in `forwardMcp`. */
export async function POST(request: Request) {
  return forwardMcp(request, '/mcp');
}

export async function GET(request: Request) {
  return forwardMcp(request, '/mcp');
}

export async function DELETE(request: Request) {
  return forwardMcp(request, '/mcp');
}
