/**
 * "Your booking link" (WHIT-1117): the address with Copy and Share; a
 * ready-made message in the workspace's language, editable, sent by WhatsApp
 * or email; and, when the booking page is off, a way for a manager to turn it on.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { BookingLinkCard, bookingMessage } from '@/components/calendar/booking-link';
import { calendarApi } from '@/lib/calendar/api';

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({
    user: { tenant_id: 5 },
    memberships: [{ tenant_id: 5, tenant_name: 'صالون الحاج', tenant_name_language: 'ar', tenant_names: { ar: 'صالون الحاج', en: 'Alhaj Salon' } }],
  }),
}));
jest.mock('@/lib/calendar/api', () => ({ calendarApi: { saveSettings: jest.fn() } }));
jest.mock('react-qr-code', () => ({ __esModule: true, default: () => <svg data-testid="qr" /> }));

const LINK = 'https://app.whity.dev/book/alhaj-saloon';

beforeEach(() => jest.clearAllMocks());

it('writes the message in each language', () => {
  expect(bookingMessage('en', 'Example Bakery', LINK)).toBe(`Book your appointment with Example Bakery: ${LINK}`);
  expect(bookingMessage('ar', 'صالون الحاج', LINK)).toBe(`احجز موعدك لدى صالون الحاج: ${LINK}`);
});

it('shows the link, copies it, and shares a message in the workspace’s language', async () => {
  const writeText = jest.fn().mockResolvedValue(undefined);
  Object.assign(navigator, { clipboard: { writeText } });
  render(<BookingLinkCard link={LINK} enabled canManage onEnabled={() => {}} />);
  expect(screen.getByTestId('booking-link-url')).toHaveTextContent(LINK);
  fireEvent.click(screen.getByTestId('booking-link-copy'));
  await waitFor(() => expect(writeText).toHaveBeenCalledWith(LINK));

  fireEvent.click(screen.getByTestId('booking-link-share-open'));
  const message = (await screen.findByTestId('booking-link-message')) as HTMLTextAreaElement;
  // The workspace's name is in Arabic, so the message starts in Arabic.
  expect(message.value).toBe(`احجز موعدك لدى صالون الحاج: ${LINK}`);
  fireEvent.click(screen.getByRole('button', { name: 'English' }));
  expect(message.value).toBe(`Book your appointment with Alhaj Salon: ${LINK}`);
  fireEvent.change(message, { target: { value: 'Book with us: ' + LINK } });
  expect(screen.getByTestId('booking-link-whatsapp')).toHaveAttribute('href', `https://wa.me/?text=${encodeURIComponent('Book with us: ' + LINK)}`);
  expect(screen.getByTestId('booking-link-email').getAttribute('href')).toContain(`body=${encodeURIComponent('Book with us: ' + LINK)}`);
  expect(screen.getByTestId('qr')).toBeInTheDocument();
});

it('offers a manager to turn the booking page on instead of hiding', async () => {
  (calendarApi.saveSettings as jest.Mock).mockResolvedValue({});
  const onEnabled = jest.fn();
  render(<BookingLinkCard link={LINK} enabled={false} canManage onEnabled={onEnabled} />);
  expect(screen.queryByTestId('booking-link-url')).toBeNull();
  fireEvent.click(screen.getByTestId('booking-link-turn-on'));
  await waitFor(() => expect(onEnabled).toHaveBeenCalled());
  expect(calendarApi.saveSettings).toHaveBeenCalledWith({ booking_page_enabled: true });
});

it('tells someone who can’t manage the calendar whom to ask', () => {
  render(<BookingLinkCard link={LINK} enabled={false} canManage={false} onEnabled={() => {}} />);
  expect(screen.queryByTestId('booking-link-turn-on')).toBeNull();
  expect(screen.getByText('Ask someone who manages the calendar to turn on the booking page.')).toBeInTheDocument();
});
