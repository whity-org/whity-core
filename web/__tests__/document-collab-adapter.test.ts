/**
 * Editor M5, web adapter: the server's comment and suggestion rows mapped to
 * the editor's types; refusals by code; mentions only for a typed name.
 */
const GET = jest.fn();
const POST = jest.fn();
jest.mock('@/lib/api/client', () => ({ api: { GET: (...a: unknown[]) => GET(...a), POST: (...a: unknown[]) => POST(...a) } }));

import { collabAdapter, toActor, toSuggestion } from '@/lib/documents/collab';

const ok = (data: unknown, status = 200) => ({ data: { data }, response: new Response(null, { status }) });
const refused = (status: number, code: string) => ({
  data: undefined,
  error: { error: 'Refused', details: { code } },
  response: new Response(null, { status }),
});

beforeEach(() => {
  GET.mockReset();
  POST.mockReset();
});

it('maps actors: integer ids become strings, an agent’s `for` becomes onBehalfOf', () => {
  expect(toActor({ kind: 'person', id: 11, name: 'Rana Haddad' })).toEqual({ kind: 'person', id: '11', name: 'Rana Haddad' });
  expect(toActor({ kind: 'agent', id: 'billing', name: 'Billing assistant', for: { id: 11, name: 'Rana' } })).toEqual({
    kind: 'agent',
    id: 'billing',
    name: 'Billing assistant',
    onBehalfOf: { id: '11', name: 'Rana' },
    scope: [],
  });
});

it('maps a suggestion row, keeping the patch as the wire sends it', () => {
  const s = toSuggestion({
    id: 41,
    kind: 'text',
    summary: 'Delete “usually ”',
    request: null,
    permission: 'documents:suggest',
    by: { kind: 'person', id: 11, name: 'Rana Haddad' },
    at: '2026-10-29T13:52:00Z',
    status: 'open',
    patch: { op: 'replace-text', blockId: 'p2', deleteText: 'usually ', insert: [], occurrence: 0 },
    base_version: 7,
    decided_by: null,
    decided_at: null,
  });
  expect(s).toMatchObject({ id: '41', status: 'open', baseVersion: 7, permission: 'documents:suggest' });
  expect(s.patch).toEqual({ op: 'replace-text', blockId: 'p2', deleteText: 'usually ', insert: [], occurrence: 0 });
});

it('a stale or already-decided decision resolves as such, not as an error', async () => {
  POST.mockResolvedValueOnce(refused(409, 'suggestion_stale')).mockResolvedValueOnce(refused(409, 'already_decided'));
  await expect(collabAdapter.decide!('7', '41', 'accepted')).resolves.toEqual({ ok: false, reason: 'stale' });
  await expect(collabAdapter.decide!('7', '41', 'accepted')).resolves.toEqual({ ok: false, reason: 'already_decided' });
  expect(POST).toHaveBeenCalledWith('/api/v1/document-sources/{id}/suggestions/{sid}/decision', {
    params: { path: { id: 7, sid: 41 } },
    body: { decision: 'accepted' },
  });
});

it('a refused suggestion throws with its code', async () => {
  POST.mockResolvedValueOnce(refused(422, 'patch_does_not_apply'));
  await expect(collabAdapter.suggest!('7', { kind: 'text', summary: 'x', patch: { op: 'delete-blocks', blockIds: ['p9'] } })).rejects.toMatchObject({
    status: 422,
    code: 'patch_does_not_apply',
  });
});

it('finds mentionable people for a typed name and never sends an empty query', async () => {
  await expect(collabAdapter.searchPeople!('7', '  ')).resolves.toEqual([]);
  expect(GET).not.toHaveBeenCalled();
  GET.mockResolvedValueOnce(ok([{ kind: 'person', id: 12, name: 'Omar Khalil', email: 'omar@example.jo' }, { kind: 'person', id: 13, name: 'Lina Saad' }]));
  await expect(collabAdapter.searchPeople!('7', 'o')).resolves.toEqual([
    { id: '12', name: 'Omar Khalil', detail: 'omar@example.jo' },
    { id: '13', name: 'Lina Saad' },
  ]);
  expect(GET).toHaveBeenCalledWith('/api/v1/document-sources/{id}/mentionable', { params: { path: { id: 7 }, query: { q: 'o' } } });
});

it('changes come back with every status; loading keeps only the open ones', async () => {
  const row = (id: number, status: string) => ({
    id,
    kind: 'text',
    summary: '',
    request: null,
    permission: 'documents:suggest',
    by: { kind: 'person', id: 11, name: 'Rana' },
    at: '2026-10-29T13:52:00Z',
    status,
    patch: { op: 'delete-blocks', blockIds: ['p1'] },
    base_version: 1,
    decided_by: null,
    decided_at: null,
  });
  GET.mockResolvedValueOnce(ok({ cursor: 'c2', draft_version: 9, suggestions: [row(1, 'open'), row(2, 'stale')], comments: [] }));
  const changes = await collabAdapter.collabChanges!('7', 'c1');
  expect(changes.suggestions.map((s) => [s.id, s.status])).toEqual([
    ['1', 'open'],
    ['2', 'stale'],
  ]);
  expect(changes).toMatchObject({ cursor: 'c2', draftVersion: 9 });
});
