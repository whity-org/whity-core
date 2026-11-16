/**
 * "Discuss in chat" (C2, WHIT-1137): the button finds or makes the chat linked
 * to a record and opens it; the chat shows the record as a block at its top.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { DiscussInChat, LinkedRecord, linkedRecordHref, recordLink } from '@/components/chat/discuss-in-chat';

const mockApiClient = jest.fn();
jest.mock('@/lib/api/client', () => ({ apiFetch: (...args: unknown[]) => mockApiClient(...args) }));
const push = jest.fn();
jest.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));
const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));

function respond(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: async () => body });
}

describe('recordLink', () => {
  it('keeps kinds apart and gives a meeting its own type', () => {
    expect(recordLink('booking', 7, 'Cake tasting')).toEqual({ type: 'record', id: 'booking:7', label: 'Cake tasting' });
    expect(recordLink('document', 7, 'Lease')).toEqual({ type: 'record', id: 'document:7', label: 'Lease' });
    expect(recordLink('meeting', 3, 'November board')).toEqual({ type: 'meeting', id: '3', label: 'November board' });
  });

  it('opens each kind where it lives', () => {
    expect(linkedRecordHref(recordLink('document', 12, 'x'))).toBe('/admin/document-library/12');
    expect(linkedRecordHref(recordLink('form_submission', 5, 'x'))).toBe('/admin/routes/form_submission/5');
    expect(linkedRecordHref(recordLink('meeting', 3, 'x'))).toBe('/calendar/meetings/3');
    expect(linkedRecordHref({ type: 'board', id: '9', label: 'x' })).toBeNull();
  });
});

describe('DiscussInChat', () => {
  beforeEach(() => {
    mockApiClient.mockReset();
    push.mockReset();
    addToast.mockReset();
  });

  it('posts the link and opens the chat', async () => {
    mockApiClient.mockImplementation(() => respond(201, { data: { id: 42 }, created: true }));
    render(<DiscussInChat link={recordLink('booking', 7, 'Cake tasting')} />);
    fireEvent.click(screen.getByTestId('discuss-in-chat'));
    await waitFor(() => expect(push).toHaveBeenCalledWith('/chat?c=42'));
    const [url, init] = mockApiClient.mock.calls[0];
    expect(url).toBe('/api/v1/chat/chats/discuss');
    expect(JSON.parse(String((init as RequestInit).body))).toEqual({ link: { type: 'record', id: 'booking:7', label: 'Cake tasting' } });
  });

  it('says so plainly when the chat could not be opened', async () => {
    mockApiClient.mockImplementation(() => respond(500, {}));
    render(<DiscussInChat link={recordLink('document', 12, 'Lease')} />);
    fireEvent.click(screen.getByTestId('discuss-in-chat'));
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('The chat could not be opened. Try again.', 'error'));
    expect(push).not.toHaveBeenCalled();
    expect(screen.getByTestId('discuss-in-chat')).not.toBeDisabled();
  });
});

describe('LinkedRecord', () => {
  it('names the record and links to it', () => {
    render(<LinkedRecord link={recordLink('document', 12, 'Lease')} />);
    expect(screen.getByTestId('chat-linked-record')).toHaveTextContent('Document · Lease');
    expect(screen.getByRole('link', { name: 'Open' })).toHaveAttribute('href', '/admin/document-library/12');
  });
});
