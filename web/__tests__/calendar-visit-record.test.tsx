/**
 * The visit record on a booking (WHIT-1443, design section 39 §7.3, AP.21):
 * someone who may not see it sees nothing, not even that it exists; before
 * Finish visit the notes are written; after it they show signed and only a
 * correction can be added.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { VisitRecordSection } from '@/components/calendar/visit-record';
import type { CalendarBooking } from '@/lib/calendar/api';

const mockApiClient = jest.fn();
jest.mock('@/lib/api/client', () =>
  jest.requireActual('./helpers/client-via-mock').clientVia(() => mockApiClient)
);
const mockToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast: mockToast, toasts: [], removeToast: () => {} }),
}));

function respond(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: async () => body });
}

const booking = { id: 9, service_id: 7, profile_id: 11, customer: { name: 'Lina Haddad' }, starts_at: '2026-11-16T07:00:00Z', ends_at: '2026-11-16T07:30:00Z', status: 'confirmed', source: 'staff' } as CalendarBooking;
const person = { profile_id: 11, name: 'Amro Saleh' };

describe('Visit record', () => {
  beforeEach(() => {
    mockApiClient.mockReset();
    mockToast.mockReset();
  });

  it('shows nothing to someone who may not see it', async () => {
    mockApiClient.mockImplementation(() => respond(403, { error: 'Only the person treating them can open this.' }));
    const { container } = render(<VisitRecordSection booking={booking} timeZone="UTC" />);
    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    expect(screen.queryByTestId('visit-record')).toBeNull();
    expect(container.textContent).toBe('');
  });

  it('writes the notes until Finish visit', async () => {
    const sent: unknown[] = [];
    let notes: unknown = null;
    mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
      if (url === '/api/v1/calendar/bookings/9/visit' && init?.method === 'PUT') {
        sent.push(JSON.parse(String(init.body)));
        notes = { body: 'Allergic to latex', author: person, created_at: '', updated_at: '', finished_at: null, finished_by: null };
      }
      return respond(200, { data: { booking_id: 9, notes, finished: false, corrections: [], files: [], forms: [] } });
    });
    render(<VisitRecordSection booking={booking} timeZone="UTC" />);
    fireEvent.change(await screen.findByTestId('visit-notes-input'), { target: { value: 'Allergic to latex' } });
    fireEvent.click(screen.getByTestId('visit-save'));
    await waitFor(() => expect(sent).toEqual([{ body: 'Allergic to latex' }]));
    await waitFor(() => expect(screen.getByTestId('visit-finish')).toBeEnabled());
  });

  it('after Finish visit shows the notes signed and only adds corrections', async () => {
    mockApiClient.mockImplementation(() =>
      respond(200, {
        data: {
          booking_id: 9,
          notes: { body: 'Allergic to latex', author: person, created_at: '2026-11-16T07:00:00Z', updated_at: '2026-11-16T07:00:00Z', finished_at: '2026-11-16T08:00:00Z', finished_by: person },
          finished: true,
          corrections: [{ id: 1, body: 'Also allergic to nuts', author: person, created_at: '2026-11-16T09:00:00Z' }],
          files: [{ id: 3, filename: 'x-ray.pdf', content_type: 'application/pdf', byte_size: 10, added_by: person, created_at: '2026-11-16T07:10:00Z' }],
          forms: [{ id: 50, form_id: 1, name: 'Health questions', names: { en: 'Health questions' }, submitted_at: '2026-11-15T10:00:00Z', printout_path: '/api/v1/form-submissions/50/printout' }],
        },
      })
    );
    render(<VisitRecordSection booking={booking} timeZone="UTC" />);
    expect(await screen.findByTestId('visit-notes')).toHaveTextContent('Allergic to latex');
    expect(screen.getByTestId('visit-notes')).toHaveTextContent('Finished by Amro Saleh');
    expect(screen.queryByTestId('visit-notes-input')).toBeNull();
    expect(screen.queryByTestId('visit-save')).toBeNull();
    expect(screen.queryByTestId('visit-finish')).toBeNull();
    expect(screen.getByTestId('visit-corrections')).toHaveTextContent('Also allergic to nuts');
    expect(screen.getByTestId('visit-files')).toHaveTextContent('x-ray.pdf');
    expect(screen.getByTestId('visit-forms')).toHaveTextContent('Health questions');
    expect(screen.getByTestId('visit-correct')).toBeDisabled();
    fireEvent.change(screen.getByTestId('visit-correction-input'), { target: { value: 'Dose was 5 ml' } });
    expect(screen.getByTestId('visit-correct')).toBeEnabled();
  });
});
