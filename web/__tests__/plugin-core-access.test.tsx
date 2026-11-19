/**
 * #2256: the operator sees the core permissions a plugin asks for its roles,
 * per workspace, and approves or takes them back. Nothing is approved on its
 * own, and a reader without the install permission gets no buttons.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PluginCoreAccess } from '@/components/plugins/plugin-core-access';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';

jest.mock('@/lib/api/client', () => ({
  api: { GET: jest.fn(), POST: jest.fn() },
}));
jest.mock('@/lib/toast-context', () => ({
  useToast: jest.fn(),
}));

const mockGet = api.GET as unknown as jest.Mock;
const mockPost = api.POST as unknown as jest.Mock;
const addToast = jest.fn();

const grants = (granted: string[]) => ({
  data: {
    data: {
      plugin: 'AcmeLeave',
      requests: { hr: ['calendar:manage', 'calendar:write'] },
      grants: [
        { tenant_id: 1, workspace: 'Example Bakery', role: 'hr', requested: ['calendar:manage', 'calendar:write'], granted },
      ],
    },
  },
});

beforeEach(() => {
  jest.clearAllMocks();
  (useToast as jest.Mock).mockReturnValue({ toasts: [], addToast, removeToast: jest.fn() });
  mockGet.mockImplementation((path: string) => {
    if (path === '/api/v1/permissions') {
      return Promise.resolve({
        data: {
          data: [
            { name: 'calendar:write', description: 'Keep your own calendar events' },
            { name: 'calendar:manage', description: 'Set up everyone\'s working hours and time off' },
          ],
          pagination: { page: 1, perPage: 100, total: 2, totalPages: 1 },
        },
      });
    }
    return Promise.resolve(grants([]));
  });
});

it('shows what the plugin asks for in plain words and approves it for that role and workspace', async () => {
  mockPost.mockResolvedValue({ data: { data: {} } });
  render(<PluginCoreAccess pluginName="AcmeLeave" canChange />);

  expect(await screen.findByText('Waiting for you')).toBeInTheDocument();
  expect(screen.getByText('Example Bakery')).toBeInTheDocument();
  expect(await screen.findByText('Keep your own calendar events')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Take back' })).toBeNull();

  await userEvent.click(screen.getByRole('button', { name: 'Approve' }));
  await waitFor(() =>
    expect(mockPost).toHaveBeenCalledWith('/api/v1/plugins/{name}/core-grants/{role}/approve', {
      params: { path: { name: 'AcmeLeave', role: 'hr' } },
      body: { tenant_id: 1 },
    })
  );
  expect(addToast).toHaveBeenCalledWith('Approved for hr.', 'success');
});

it('offers to take back an approval, and shows no buttons to someone who may not change it', async () => {
  mockGet.mockImplementation((path: string) =>
    Promise.resolve(path === '/api/v1/permissions' ? { data: undefined } : grants(['calendar:manage', 'calendar:write']))
  );
  const { unmount } = render(<PluginCoreAccess pluginName="AcmeLeave" canChange />);
  expect(await screen.findByText('Approved')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Take back' })).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull();
  unmount();

  render(<PluginCoreAccess pluginName="AcmeLeave" canChange={false} />);
  expect(await screen.findByText('Approved')).toBeInTheDocument();
  expect(screen.queryByRole('button')).toBeNull();
});
