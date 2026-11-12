/**
 * WC-ac35b6cf — EmailDomainsPage tests.
 *
 * Mocks @/lib/api-client (the page imports the module-level `apiClient`
 * singleton, like SsoProvidersPage does, rather than useAuth().apiClient),
 * useCapabilities (for the `email_domains:manage` gate), and useToast.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => mockApiClient(...args),
}));

let mockCaps: string[] = ['email_domains:manage'];
let mockCapsLoading = false;
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({
    has: (capability: string) => mockCaps.includes(capability),
    loading: mockCapsLoading,
  }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import EmailDomainsPage from '@/app/(protected)/admin/settings/email-domains/page';

interface EmailDomain {
  id: number;
  tenant_id: number;
  domain: string;
  default_role_id: number;
  auto_provision: boolean;
  verified_at: string | null;
  is_verified: boolean;
  created_at: string;
  verification?: { record_name: string; record_type: string; record_value: string };
}

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  });
}

const VERIFIED_DOMAIN: EmailDomain = {
  id: 1,
  tenant_id: 5,
  domain: 'example.com',
  default_role_id: 2,
  auto_provision: true,
  verified_at: '2026-01-01T00:00:00Z',
  is_verified: true,
  created_at: '2026-01-01T00:00:00Z',
};

const PENDING_DOMAIN: EmailDomain = {
  id: 2,
  tenant_id: 5,
  domain: 'pending.com',
  default_role_id: 2,
  auto_provision: true,
  verified_at: null,
  is_verified: false,
  created_at: '2026-01-02T00:00:00Z',
  verification: {
    record_name: '_whity-challenge.pending.com',
    record_type: 'TXT',
    record_value: 'whity-verify=abc123',
  },
};

const ROLES = [
  { id: 1, name: 'admin' },
  { id: 2, name: 'member' },
];

function mockRoutes(domains: EmailDomain[]) {
  mockApiClient.mockImplementation((url: string) => {
    if (url.startsWith('/api/v1/email-domains') === false && url.startsWith('/api/v1/roles')) {
      return jsonResponse(200, { data: ROLES });
    }
    if (url === '/api/v1/email-domains') {
      return jsonResponse(200, { data: domains });
    }
    if (url.startsWith('/api/v1/roles')) {
      return jsonResponse(200, { data: ROLES });
    }
    return jsonResponse(404, {});
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  mockCaps = ['email_domains:manage'];
  mockCapsLoading = false;
});

describe('EmailDomainsPage — permission gate', () => {
  it('denies access without the permission', async () => {
    mockCaps = [];
    mockRoutes([]);

    render(<EmailDomainsPage />);

    expect(await screen.findByText('Access denied')).toBeInTheDocument();
  });

  it('does not flash a refusal while permissions load', async () => {
    mockCaps = [];
    mockCapsLoading = true;
    mockRoutes([]);

    render(<EmailDomainsPage />);

    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    expect(screen.queryByText('Access denied')).not.toBeInTheDocument();
  });

  it('renders for whoever holds the permission, whatever their role is called', async () => {
    mockRoutes([VERIFIED_DOMAIN]);

    render(<EmailDomainsPage />);

    await waitFor(() => expect(screen.getByTestId('email-domain-1')).toBeInTheDocument());
  });
});

describe('EmailDomainsPage — list rendering', () => {
  it('shows a verified domain with the Verified badge and no challenge instructions', async () => {
    mockRoutes([VERIFIED_DOMAIN]);
    render(<EmailDomainsPage />);

    await waitFor(() => expect(screen.getByTestId('email-domain-status-1')).toHaveTextContent('Verified'));
    expect(screen.queryByTestId('email-domain-verify-1')).not.toBeInTheDocument();
  });

  it('shows a pending domain with challenge instructions and a Verify button', async () => {
    mockRoutes([PENDING_DOMAIN]);
    render(<EmailDomainsPage />);

    await waitFor(() =>
      expect(screen.getByTestId('email-domain-status-2')).toHaveTextContent('Pending verification')
    );
    expect(screen.getByText('_whity-challenge.pending.com')).toBeInTheDocument();
    expect(screen.getByText('whity-verify=abc123')).toBeInTheDocument();
    expect(screen.getByTestId('email-domain-verify-2')).toBeInTheDocument();
  });

  it('shows the empty state when no domains are registered', async () => {
    mockRoutes([]);
    render(<EmailDomainsPage />);

    await waitFor(() => expect(screen.getByText(/No email domains registered yet/i)).toBeInTheDocument());
  });
});

describe('EmailDomainsPage — add domain', () => {
  it('submits a new domain registration', async () => {
    mockRoutes([]);
    mockApiClient.mockImplementation((url: string, opts?: { method?: string }) => {
      if (opts?.method === 'POST' && url === '/api/v1/email-domains') {
        return jsonResponse(201, { data: PENDING_DOMAIN });
      }
      if (url === '/api/v1/email-domains') {
        return jsonResponse(200, { data: [] });
      }
      if (url.startsWith('/api/v1/roles')) {
        return jsonResponse(200, { data: ROLES });
      }
      return jsonResponse(404, {});
    });

    render(<EmailDomainsPage />);
    await waitFor(() => expect(screen.getByTestId('email-domains-add')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('email-domains-add'));

    await waitFor(() => expect(screen.getByTestId('email-domain-form')).toBeInTheDocument());
    fireEvent.change(screen.getByLabelText('Domain'), { target: { value: 'pending.com' } });
    fireEvent.click(screen.getByTestId('email-domain-save'));

    await waitFor(() => {
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/email-domains',
        expect.objectContaining({
          method: 'POST',
          body: JSON.stringify({ domain: 'pending.com', default_role_id: 1, auto_provision: true }),
        })
      );
    });
    await waitFor(() => expect(addToast).toHaveBeenCalledWith(expect.stringContaining('registered'), 'success'));
  });
});

describe('EmailDomainsPage — verify + delete', () => {
  it('calls the verify endpoint and shows a success toast on success', async () => {
    mockRoutes([PENDING_DOMAIN]);
    mockApiClient.mockImplementation((url: string, opts?: { method?: string }) => {
      if (opts?.method === 'POST' && url === '/api/v1/email-domains/2/verify') {
        return jsonResponse(200, { data: VERIFIED_DOMAIN });
      }
      if (url === '/api/v1/email-domains') {
        return jsonResponse(200, { data: [PENDING_DOMAIN] });
      }
      if (url.startsWith('/api/v1/roles')) {
        return jsonResponse(200, { data: ROLES });
      }
      return jsonResponse(404, {});
    });

    render(<EmailDomainsPage />);
    await waitFor(() => expect(screen.getByTestId('email-domain-verify-2')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('email-domain-verify-2'));

    await waitFor(() => expect(addToast).toHaveBeenCalledWith(expect.stringContaining('verified'), 'success'));
  });

  it('deletes a domain after confirming', async () => {
    mockRoutes([VERIFIED_DOMAIN]);
    mockApiClient.mockImplementation((url: string, opts?: { method?: string }) => {
      if (opts?.method === 'DELETE' && url === '/api/v1/email-domains/1') {
        return jsonResponse(204, {});
      }
      if (url === '/api/v1/email-domains') {
        return jsonResponse(200, { data: [VERIFIED_DOMAIN] });
      }
      if (url.startsWith('/api/v1/roles')) {
        return jsonResponse(200, { data: ROLES });
      }
      return jsonResponse(404, {});
    });

    render(<EmailDomainsPage />);
    await waitFor(() => expect(screen.getByTestId('email-domain-delete-1')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('email-domain-delete-1'));

    await waitFor(() => expect(screen.getByTestId('email-domain-confirm-delete-1')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('email-domain-confirm-delete-1'));

    await waitFor(() => expect(addToast).toHaveBeenCalledWith(expect.stringContaining('removed'), 'success'));
  });
});

describe('EmailDomainsPage — edit domain', () => {
  function routesWithPatch(domains: EmailDomain[], patchStatus = 200) {
    mockApiClient.mockImplementation((url: string, opts?: { method?: string }) => {
      if (opts?.method === 'PATCH' && url === '/api/v1/email-domains/1') {
        return patchStatus === 200
          ? jsonResponse(200, { data: { ...VERIFIED_DOMAIN, default_role_id: 1, auto_provision: false } })
          : jsonResponse(patchStatus, { error: 'Role not assignable' });
      }
      if (url === '/api/v1/email-domains') {
        return jsonResponse(200, { data: domains });
      }
      if (url.startsWith('/api/v1/roles')) {
        return jsonResponse(200, { data: ROLES });
      }
      return jsonResponse(404, {});
    });
  }

  it('opens the form on the row with the current values and the name locked', async () => {
    routesWithPatch([VERIFIED_DOMAIN]);
    render(<EmailDomainsPage />);

    await waitFor(() => expect(screen.getByTestId('email-domain-edit-1')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('email-domain-edit-1'));

    expect(await screen.findByTestId('email-domain-form')).toBeInTheDocument();
    expect(screen.queryByTestId('email-domain-1')).not.toBeInTheDocument();
    const domainInput = screen.getByLabelText('Domain');
    expect(domainInput).toHaveValue('example.com');
    expect(domainInput).toBeDisabled();
    expect(screen.getByTestId('email-domain-name-locked')).toBeInTheDocument();
    expect(screen.getByTestId('email-domain-auto-provision-switch')).toBeChecked();
  });

  it('PATCHes only the role and the switch, never the domain name', async () => {
    routesWithPatch([VERIFIED_DOMAIN]);
    render(<EmailDomainsPage />);

    await waitFor(() => expect(screen.getByTestId('email-domain-edit-1')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('email-domain-edit-1'));
    await screen.findByTestId('email-domain-form');
    fireEvent.click(screen.getByTestId('email-domain-auto-provision-switch'));
    fireEvent.click(screen.getByTestId('email-domain-save'));

    await waitFor(() => {
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/email-domains/1',
        expect.objectContaining({
          method: 'PATCH',
          body: JSON.stringify({ default_role_id: 2, auto_provision: false }),
        })
      );
    });
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Domain updated.', 'success'));
    await waitFor(() => expect(screen.queryByTestId('email-domain-form')).not.toBeInTheDocument());
  });

  it('keeps the form open and shows the server error when the update is refused', async () => {
    routesWithPatch([VERIFIED_DOMAIN], 422);
    render(<EmailDomainsPage />);

    await waitFor(() => expect(screen.getByTestId('email-domain-edit-1')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('email-domain-edit-1'));
    await screen.findByTestId('email-domain-form');
    fireEvent.click(screen.getByTestId('email-domain-save'));

    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Role not assignable', 'error'));
    expect(screen.getByTestId('email-domain-form')).toBeInTheDocument();
  });

  it('Cancel puts the row back without calling the API', async () => {
    routesWithPatch([VERIFIED_DOMAIN]);
    render(<EmailDomainsPage />);

    await waitFor(() => expect(screen.getByTestId('email-domain-edit-1')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('email-domain-edit-1'));
    await screen.findByTestId('email-domain-form');
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(await screen.findByTestId('email-domain-1')).toBeInTheDocument();
    expect(mockApiClient).not.toHaveBeenCalledWith(
      '/api/v1/email-domains/1',
      expect.objectContaining({ method: 'PATCH' })
    );
  });
});
