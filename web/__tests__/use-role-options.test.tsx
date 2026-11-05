import { renderHook, waitFor } from '@testing-library/react';
import { useRoleOptions } from '@/app/(protected)/admin/users/use-role-options';
import { api } from '@/lib/api/client';

jest.mock('@/lib/api/client', () => ({ api: { GET: jest.fn() } }));
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: jest.fn() }) }));

describe('useRoleOptions', () => {
  it('shows a plugin role in words and still submits its real name', async () => {
    (api.GET as jest.Mock).mockResolvedValue({
      data: { data: [{ name: 'admin' }, { name: 'hello_viewer' }] },
    });

    const { result } = renderHook(() => useRoleOptions(true));

    await waitFor(() => expect(result.current.roleOptions).toHaveLength(2));
    expect(result.current.roleOptions).toEqual([
      { value: 'admin', label: 'Admin' },
      { value: 'hello_viewer', label: 'Hello viewer' },
    ]);
  });
});
