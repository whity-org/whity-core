/**
 * The role permission picker reads the whole catalogue, not its first page.
 *
 * The server caps a page at 100 (PaginationParams::MAX_PER_PAGE). Once the
 * core catalogue passed 100, a single page silently dropped the permissions
 * that sort last (users:*), and the picker could no longer grant them.
 */
import type { Transport } from '@whity/features/roles';
import { createRolesAdapter } from '@/lib/roles-adapter';

function pagedTransport(total: number): { transport: Transport; paths: string[] } {
  const names = Array.from({ length: total }, (_, i) => `perm${String(i).padStart(3, '0')}:read`);
  const paths: string[] = [];
  const transport: Transport = {
    request: jest.fn().mockImplementation(async (_method: string, path: string) => {
      paths.push(path);
      const page = Number(new URL(path, 'http://x').searchParams.get('page') ?? '1');
      const slice = names.slice((page - 1) * 100, page * 100);
      return {
        status: 200,
        body: {
          data: slice.map((name, i) => ({ id: (page - 1) * 100 + i + 1, name })),
          pagination: { page, perPage: 100, total, totalPages: Math.ceil(total / 100) },
        },
      };
    }),
  };
  return { transport, paths };
}

describe('listPermissions', () => {
  it('reads every page when the catalogue is longer than one', async () => {
    const { transport, paths } = pagedTransport(230);
    const permissions = await createRolesAdapter(transport).listPermissions();

    expect(permissions).toHaveLength(230);
    expect(permissions[229]?.name).toBe('perm229:read');
    expect(paths).toHaveLength(3);
  });

  it('asks once when everything fits on a page', async () => {
    const { transport, paths } = pagedTransport(40);
    expect(await createRolesAdapter(transport).listPermissions()).toHaveLength(40);
    expect(paths).toHaveLength(1);
  });

  it('still fails plainly when a page fails', async () => {
    const transport: Transport = {
      request: jest.fn().mockResolvedValue({ status: 500, body: { message: 'Failed to fetch permissions' } }),
    };
    await expect(createRolesAdapter(transport).listPermissions()).rejects.toThrow('Failed to fetch permissions');
  });
});
