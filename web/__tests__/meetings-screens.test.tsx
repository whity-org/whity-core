/**
 * Meetings in the calendar (WHIT-1115, ADR 0018): the list groups formal
 * meetings by day and keeps Upcoming apart from Past; a meeting's page shows
 * its facts and the tabs, and a decision is only offered once it is held.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MeetingsScreen } from '@/components/meetings/meetings-screen';
import { MeetingScreen } from '@/components/meetings/meeting-screen';

const mockApiClient = jest.fn();
jest.mock('@/lib/api/client', () =>
  jest.requireActual('./helpers/client-via-mock').clientVia(() => mockApiClient)
);

let mockCan = (permission: string): boolean => permission !== '';
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ has: (p: string) => mockCan(p), hasPermission: (p: string) => mockCan(p), loading: false }),
}));
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: jest.fn() }) }));
jest.mock('next/navigation', () => ({
  useRouter: () => ({ push: jest.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

function respond(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: async () => body });
}

const body = { id: 1, tenant_id: 1, body_key: 'board', name: { en: 'Board' }, display_name: 'Example Bakery Board', ou_id: null, description: null, is_active: true, created_at: '', updated_at: '' };
const base = { tenant_id: 1, body_id: 1, created_by_profile_id: null, created_at: '', held_at: null, location: 'Main room' };
const upcoming = { ...base, id: 10, meeting_number: 3, title: { en: 'November board' }, display_title: 'November board', scheduled_at: '2026-11-20 10:00:00', status: 'scheduled' };
const past = { ...base, id: 11, meeting_number: 2, title: { en: 'October board' }, display_title: 'October board', scheduled_at: '2026-10-20 10:00:00', status: 'held' };

function api(extra: (url: string) => Promise<unknown> | null = () => null) {
  mockApiClient.mockImplementation((url: string) => {
    const custom = extra(url);
    if (custom !== null) return custom;
    if (url.startsWith('/api/v1/meetings')) return respond(200, { data: [upcoming, past] });
    if (url.startsWith('/api/v1/convening-bodies/')) return respond(200, { data: { ...body, members: [] } });
    if (url.startsWith('/api/v1/convening-bodies')) return respond(200, { data: [body] });
    if (url.startsWith('/api/v1/calendar/staff')) return respond(200, { data: [] });
    return respond(404, {});
  });
}

describe('MeetingsScreen', () => {
  beforeEach(() => {
    mockApiClient.mockReset();
    mockCan = () => true;
  });

  it('lists upcoming meetings first and past ones on the Past tab', async () => {
    api();
    render(<MeetingsScreen />);
    expect(await screen.findByTestId('meeting-10')).toHaveTextContent('November board');
    expect(screen.queryByTestId('meeting-11')).toBeNull();
    expect(screen.getByTestId('meeting-10')).toHaveAttribute('href', '/calendar/meetings/10');
    expect(screen.getByTestId('meeting-10')).toHaveTextContent('Formal');

    fireEvent.click(screen.getByTestId('meetings-tab-past'));
    expect(await screen.findByTestId('meeting-11')).toHaveTextContent('October board');
    expect(screen.queryByTestId('meeting-10')).toBeNull();
  });

  it('says so plainly when the person may not read meetings', async () => {
    mockCan = () => false;
    api();
    render(<MeetingsScreen />);
    expect(await screen.findByText(/convening:read|don.t have access|not allowed/i)).toBeInTheDocument();
  });
});

describe('MeetingScreen', () => {
  const detail = (status: string) => ({
    ...(status === 'held' ? past : upcoming),
    body,
    agenda: [{ id: 5, tenant_id: 1, meeting_id: 11, position: 1, title: { en: 'Budget' }, display_title: 'Budget', document_id: null, notes: null, created_at: '' }],
    decisions: [],
    invitations: [],
    attendance: [],
  });

  beforeEach(() => {
    mockApiClient.mockReset();
    mockCan = () => true;
  });

  it('offers a decision only once the meeting is held', async () => {
    api((url) => (url.startsWith('/api/v1/meetings/11') ? respond(200, { data: detail('held') }) : null));
    render(<MeetingScreen id={11} />);
    expect(await screen.findByTestId('agenda-decide-5')).toBeInTheDocument();
    expect(screen.queryByTestId('meeting-hold')).toBeNull();
  });

  it('lets a draft or scheduled meeting be held, after asking', async () => {
    api((url) => (url.startsWith('/api/v1/meetings/10') ? respond(200, { data: { ...detail('scheduled'), id: 10 } }) : null));
    render(<MeetingScreen id={10} />);
    expect(await screen.findByTestId('meeting-hold')).toBeInTheDocument();
    expect(screen.queryByTestId('agenda-decide-5')).toBeNull();
    fireEvent.click(screen.getByTestId('meeting-hold'));
    await waitFor(() => expect(screen.getByTestId('meeting-confirm')).toBeInTheDocument());
  });
});
