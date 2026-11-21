/**
 * One place to choose (merge map N3, WHIT-1119): each kind has a Push and an
 * Email switch on the same preferences every sender reads; reminders by email
 * start off; chat never comes by email.
 */

import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor, within } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/api/client', () => jest.requireActual('./helpers/client-via-mock').clientVia(() => mockApiClient));
jest.mock('@/lib/pwa/push', () => ({
  NOTIFICATIONS_ANCHOR: 'notifications',
  deviceLabel: () => ({ browser: 'Chrome', os: 'Windows' }),
  usePush: () => ({
    loading: false,
    busy: false,
    support: 'ready',
    on: true,
    error: null,
    devices: [{ id: 1, user_agent: 'x', created_at: '2026-11-01T00:00:00Z' }],
    currentId: 1,
    turnOn: jest.fn(),
    turnOff: jest.fn(),
    remove: jest.fn(),
  }),
}));

import { PushSettings } from '@/components/settings/push-settings';

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

let saved: unknown[] = [];

// #2209: a plugin chooses whether its kind starts on by email.
const pluginTypes = [
  { type: 'keyhub:task.assigned', label: { en: 'A task was given to you', ar: 'أُسندت إليك مهمة' }, plugin: 'keyhub', email_default: true },
  { type: 'keyhub:stage.begun', label: { en: 'A stage you own has begun', ar: 'بدأت مرحلة تملكها' }, plugin: 'keyhub', email_default: false },
];

beforeEach(() => {
  saved = [];
  mockApiClient.mockReset();
  mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
    if (url === '/api/v1/me/notification-preferences' && (init?.method ?? 'GET') === 'GET') {
      return jsonResponse(200, { data: [{ type: 'chat.message', channel: 'push', enabled: false }], plugin_types: pluginTypes });
    }
    if (url === '/api/v1/me/notification-preferences' && init?.method === 'PUT') {
      const body = JSON.parse(String(init.body)) as { preferences: unknown[] };
      saved.push(...body.preferences);
      return jsonResponse(200, { data: body.preferences, plugin_types: pluginTypes });
    }
    return jsonResponse(404, {});
  });
});

function row(name: string) {
  const table = screen.getByTestId('notification-choices');
  return within(table).getByRole('rowheader', { name }).closest('tr') as HTMLElement;
}

describe('Notification choices', () => {
  it('shows push and email for each kind, with each person’s own choices', async () => {
    render(<PushSettings />);
    await waitFor(() => expect(within(row('Chat messages')).getAllByRole('switch')[0]).not.toBeChecked());

    // Chat never comes by email.
    expect(within(row('Chat messages')).getAllByRole('switch')).toHaveLength(1);
    // Reminders by email start off; push starts on.
    const [push, email] = within(row('Reminders 15 minutes before meetings and events')).getAllByRole('switch');
    expect(push).toBeChecked();
    expect(email).not.toBeChecked();
    // Other kinds come by email until turned off.
    expect(within(row('Meeting invitations and changes')).getAllByRole('switch')[1]).toBeChecked();
  });

  it('turning email on for reminders saves that one choice', async () => {
    render(<PushSettings />);
    const email = await waitFor(() => within(row('Reminders 15 minutes before meetings and events')).getAllByRole('switch')[1]);

    await userEvent.click(email);

    await waitFor(() => expect(saved).toEqual([{ type: 'calendar.event.reminder', channel: 'email', enabled: true }]));
  });

  it('a plugin kind starts on by email only when the plugin said so (#2209)', async () => {
    render(<PushSettings />);
    await waitFor(() => expect(within(row('A task was given to you')).getAllByRole('switch')).toHaveLength(2));

    expect(within(row('A task was given to you')).getAllByRole('switch')[1]).toBeChecked();
    expect(within(row('A stage you own has begun')).getAllByRole('switch')[1]).not.toBeChecked();

    await userEvent.click(within(row('A stage you own has begun')).getAllByRole('switch')[1]);

    await waitFor(() => expect(saved).toEqual([{ type: 'keyhub:stage.begun', channel: 'email', enabled: true }]));
  });
});
