import type { Block } from './blocks';
import type { Requester } from './client';

/**
 * Test helpers for plugin frontends (SDK 1.165, decision D8): walk a block
 * tree, and answer requests without a server.
 */

/**
 * The keys a block keeps its children under (`BlockContract::knownChildSlots()`).
 * test/testing.test.ts checks this against the generated contract.
 */
export const CHILD_SLOTS = ['children', 'otherwise'] as const;

/**
 * Visit every block in a tree, depth first, in each slot's order. `path` names
 * where the block sits (`[0].children[2]`), the way the host's errors do.
 */
export function walkBlocks(tree: readonly Block[], visit: (block: Block, path: string) => void, prefix = ''): void {
  tree.forEach((node, index) => {
    const path = `${prefix}[${index}]`;
    visit(node, path);
    for (const slot of CHILD_SLOTS) {
      const children = (node as unknown as Record<string, unknown>)[slot];
      if (Array.isArray(children)) {
        walkBlocks(children as Block[], visit, `${path}.${slot}`);
      }
    }
  });
}

/** Every block of one type in a tree, with where it sits. */
export function findBlocks<T extends Block['type']>(
  tree: readonly Block[],
  type: T,
): { block: Extract<Block, { type: T }>; path: string }[] {
  const found: { block: Extract<Block, { type: T }>; path: string }[] = [];
  walkBlocks(tree, (node, path) => {
    if (node.type === type) {
      found.push({ block: node as Extract<Block, { type: T }>, path });
    }
  });

  return found;
}

/** One request the mock requester answered. */
export interface RecordedRequest {
  method: string;
  url: string;
  body: unknown;
}

/** An answer: a JSON body for 200, or a status and body. */
export type MockAnswer = unknown | { status: number; body?: unknown };

/**
 * A requester that answers from a table instead of a server: `'GET /api/v1/x'`
 * (or just the URL, for any method) => the JSON it returns. A URL with a query
 * string matches its own entry first, then the entry without the query. Anything
 * else answers 404. `calls` records every request, in order.
 */
export function createMockRequester(
  answers: Record<string, MockAnswer | ((request: RecordedRequest) => MockAnswer)>,
): Requester & { calls: RecordedRequest[] } {
  const calls: RecordedRequest[] = [];
  const isStatus = (value: unknown): value is { status: number; body?: unknown } =>
    typeof value === 'object' && value !== null && 'status' in value && typeof (value as { status: unknown }).status === 'number';

  const requester = async (url: string, init?: RequestInit): Promise<Response> => {
    const method = (init?.method ?? 'GET').toUpperCase();
    const body = typeof init?.body === 'string' ? JSON.parse(init.body) : (init?.body ?? null);
    const request: RecordedRequest = { method, url, body };
    calls.push(request);

    const bare = url.split('?')[0] ?? url;
    const keys = [`${method} ${url}`, url, `${method} ${bare}`, bare];
    const key = keys.find((candidate) => candidate in answers);
    if (key === undefined) {
      return new Response(JSON.stringify({ error: { code: 'not_found', message: `No mock answer for ${method} ${url}` } }), { status: 404 });
    }
    const entry = answers[key];
    const answer = typeof entry === 'function' ? (entry as (r: RecordedRequest) => MockAnswer)(request) : entry;
    if (isStatus(answer)) {
      return new Response(answer.body === undefined ? null : JSON.stringify(answer.body), { status: answer.status });
    }

    return new Response(JSON.stringify(answer), { status: 200, headers: { 'Content-Type': 'application/json' } });
  };

  return Object.assign(requester, { calls });
}
