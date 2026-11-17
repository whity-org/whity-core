/**
 * SDK 1.113 (WHIT-1172): setup inputs. A person picks a colour, a time, a week
 * of hours and several choices, and ticks prefilled rows, instead of typing
 * formats; each submits the shape the contract documents.
 */

import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { BlockRenderer } from '@/components/plugin/blocks/block-renderer';
import type { Block } from '@/lib/plugin-features';
import { apiFetch as apiClient } from '@/lib/api/client';
import * as actionPermissionModule from '@/hooks/useActionPermission';
import type { ActionPermission } from '@/hooks/useActionPermission';
import { ToastProvider } from '@/lib/toast-context';

jest.mock('@/lib/api/client', () => ({ apiFetch: jest.fn() }));
jest.mock('@/hooks/useActionPermission', () => ({ useActionPermission: jest.fn() }));

const mockApiClient = apiClient as jest.MockedFunction<typeof apiClient>;
const mockUseActionPermission =
  actionPermissionModule.useActionPermission as jest.MockedFunction<typeof actionPermissionModule.useActionPermission>;

function stubResponse(ok: boolean, status: number, body: unknown): Response {
  return { ok, status, json: () => Promise.resolve(body) } as unknown as Response;
}

beforeEach(() => {
  jest.clearAllMocks();
  mockUseActionPermission.mockReturnValue({ allowed: true, hidden: false, disabled: false, reason: null } as ActionPermission);
  mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));
});

function renderForm(children: Block[]) {
  const form = {
    type: 'form',
    submit: { method: 'POST', endpoint: '/api/v1/x/save' },
    children: [...children, { type: 'submitButton', label: 'Save' }],
  } as Block;
  return render(<BlockRenderer blocks={[form]} />, { wrapper: ({ children: c }) => <ToastProvider>{c}</ToastProvider> });
}

async function save(): Promise<Record<string, unknown>> {
  await userEvent.click(screen.getByRole('button', { name: /^save$/i }));
  await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(1));
  const [, options] = mockApiClient.mock.calls[0] as [string, { body: string }];
  return JSON.parse(options.body) as Record<string, unknown>;
}

describe('BlockRenderer — SDK 1.113 setup inputs', () => {
  it('colorInput: a swatch is picked with one click and submits #rrggbb', async () => {
    renderForm([{ type: 'colorInput', name: 'colour', label: 'Your colour', default: '#0961bb', swatches: ['#A67C00', '#0961bb'] } as Block]);

    const gold = screen.getByRole('button', { name: '#A67C00' });
    expect(screen.getByRole('button', { name: '#0961bb' })).toHaveAttribute('aria-pressed', 'true');
    await userEvent.click(gold);
    expect(gold).toHaveAttribute('aria-pressed', 'true');

    expect((await save())['colour']).toBe('#a67c00');
  });

  it('timeInput: offers times and submits HH:MM', async () => {
    renderForm([{ type: 'timeInput', name: 'opens', label: 'Opens', step: 30, default: '10:00' } as Block]);
    expect((await save())['opens']).toBe('10:00');
  });

  it('timeInput: a required empty time blocks the submit', async () => {
    renderForm([{ type: 'timeInput', name: 'opens', label: 'Opens', required: true } as Block]);
    await userEvent.click(screen.getByRole('button', { name: /^save$/i }));
    expect(await screen.findByText(/opens is required/i)).toBeInTheDocument();
    expect(mockApiClient).not.toHaveBeenCalled();
  });

  it('weeklyHours: draws the week from its first day and submits the calendar rows', async () => {
    renderForm([
      {
        type: 'weeklyHours',
        name: 'hours',
        label: 'Opening hours',
        firstDay: 6,
        default: [
          { weekday: 6, start_time: '10:00', end_time: '21:00' },
          { weekday: 7, start_time: '10:00', end_time: '21:00' },
        ],
      } as Block,
    ]);

    const days = within(screen.getByTestId('block-input-hours-week')).getAllByRole('listitem');
    expect(days).toHaveLength(7);
    expect(days[0]).toHaveAttribute('data-testid', 'block-input-hours-day-6');

    // Open Monday: it takes the first open day's hours.
    await userEvent.click(screen.getByTestId('block-input-hours-open-1'));

    const payload = await save();
    expect(payload['hours']).toEqual([
      { weekday: 1, start_time: '10:00', end_time: '21:00' },
      { weekday: 6, start_time: '10:00', end_time: '21:00' },
      { weekday: 7, start_time: '10:00', end_time: '21:00' },
    ]);
  });

  it('weeklyHours: "Copy to every open day" and "Add a break"', async () => {
    renderForm([
      {
        type: 'weeklyHours',
        name: 'hours',
        label: 'Opening hours',
        default: [
          { weekday: 1, start_time: '10:00', end_time: '21:00' },
          { weekday: 2, start_time: '09:00', end_time: '17:00' },
        ],
      } as Block,
    ]);

    await userEvent.click(screen.getByTestId('block-input-hours-add-1'));
    await userEvent.click(screen.getByTestId('block-input-hours-copy-1'));

    const payload = await save();
    expect(payload['hours']).toEqual([
      { weekday: 1, start_time: '10:00', end_time: '15:00' },
      { weekday: 1, start_time: '16:00', end_time: '21:00' },
      { weekday: 2, start_time: '10:00', end_time: '15:00' },
      { weekday: 2, start_time: '16:00', end_time: '21:00' },
    ]);
  });

  it('weeklyHours: closing a day sends no rows for it', async () => {
    renderForm([
      {
        type: 'weeklyHours',
        name: 'hours',
        label: 'Opening hours',
        default: [
          { weekday: 1, start_time: '10:00', end_time: '21:00' },
          { weekday: 5, start_time: '14:00', end_time: '21:00' },
        ],
      } as Block,
    ]);
    await userEvent.click(screen.getByTestId('block-input-hours-open-5'));
    expect((await save())['hours']).toEqual([{ weekday: 1, start_time: '10:00', end_time: '21:00' }]);
  });

  it('checkboxGroup: submits the ticked values in the options order', async () => {
    renderForm([
      {
        type: 'checkboxGroup',
        name: 'menus',
        label: 'Menus',
        default: ['kids'],
        options: [
          { value: 'men', label: 'Men' },
          { value: 'women', label: 'Women' },
          { value: 'kids', label: 'Kids' },
        ],
      } as Block,
    ]);
    await userEvent.click(screen.getByLabelText('Men'));
    expect((await save())['menus']).toEqual(['men', 'kids']);
  });

  it('fieldArray: default rows start filled, a checkbox read from "true"', async () => {
    renderForm([
      {
        type: 'fieldArray',
        name: 'services',
        label: 'Services',
        itemLabel: 'Service',
        addLabel: 'Add a service',
        default: [
          { include: 'true', name: 'Haircut', minutes: '30' },
          { include: 'false', name: 'Shave', minutes: '20' },
        ],
        children: [
          { type: 'checkbox', name: 'include', label: 'Offer it' },
          { type: 'textInput', name: 'name', label: 'Name' },
          { type: 'numberInput', name: 'minutes', label: 'Minutes' },
        ],
      } as Block,
    ]);

    expect(screen.getAllByLabelText('Name').map((i) => (i as HTMLInputElement).value)).toEqual(['Haircut', 'Shave']);
    expect(screen.getByRole('button', { name: 'Add a service' })).toBeInTheDocument();

    const payload = await save();
    expect(payload['services']).toEqual([
      { include: true, name: 'Haircut', minutes: '30' },
      { include: false, name: 'Shave', minutes: '20' },
    ]);
  });
});

describe('BlockRenderer — WHIT-1176 a setup step opens with what is saved', () => {
  it('a preloaded number shows in its field, lists seed their inputs, and an unchanged save sends them back', async () => {
    mockApiClient.mockImplementation((url: string, init?: { method?: string }) => {
      if ((init?.method ?? 'GET') === 'GET' && url.includes('/x/current')) {
        return Promise.resolve(
          stubResponse(true, 200, {
            data: {
              chairs: 3,
              colour: '#a67c00',
              clientele: ['women'],
              hours: [{ weekday: 6, start_time: '10:00', end_time: '21:00' }],
            },
          })
        );
      }
      return Promise.resolve(stubResponse(true, 200, {}));
    });
    const form = {
      type: 'form',
      submit: { method: 'POST', endpoint: '/api/v1/x/save' },
      dataSource: { method: 'GET', path: '/api/v1/x/current' },
      children: [
        { type: 'numberInput', name: 'chairs', label: 'Chairs' },
        { type: 'colorInput', name: 'colour', label: 'Colour', swatches: ['#a67c00', '#0961bb'] },
        { type: 'checkboxGroup', name: 'clientele', label: 'Who comes', options: [{ value: 'men', label: 'Men' }, { value: 'women', label: 'Women' }] },
        { type: 'weeklyHours', name: 'hours', label: 'Hours', firstDay: 6 },
        { type: 'submitButton', label: 'Save' },
      ],
    } as Block;
    render(<BlockRenderer blocks={[form]} />, { wrapper: ({ children: c }) => <ToastProvider>{c}</ToastProvider> });

    expect(await screen.findByDisplayValue('3')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: '#a67c00' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByLabelText('Women')).toBeChecked();
    expect(screen.getByTestId('block-input-hours-open-6')).toHaveAttribute('data-state', 'checked');

    await userEvent.click(screen.getByRole('button', { name: /^save$/i }));
    await waitFor(() => expect(mockApiClient.mock.calls.some(([u]) => String(u).includes('/x/save'))).toBe(true));
    const [, options] = mockApiClient.mock.calls.find(([u]) => String(u).includes('/x/save')) as [string, { body: string }];
    expect(JSON.parse(options.body)).toEqual({
      chairs: '3',
      colour: '#a67c00',
      clientele: ['women'],
      hours: [{ weekday: 6, start_time: '10:00', end_time: '21:00' }],
    });
  });

  it('colorInput with nothing chosen says so and sends nothing', async () => {
    renderForm([{ type: 'colorInput', name: 'colour', label: 'Colour', swatches: ['#a67c00'] } as Block]);
    expect(screen.getByText('No colour chosen')).toBeInTheDocument();
    expect(screen.queryByText('#000000')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: '#a67c00' })).toHaveAttribute('aria-pressed', 'false');
    expect(await save()).not.toHaveProperty('colour');
  });
});
