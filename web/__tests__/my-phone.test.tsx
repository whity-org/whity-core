/**
 * A person's own phone number (WHIT-1181).
 *
 * - PhoneInput (docs/design/components/PhoneInput): Jordan first, grouped as
 *   read, a leading 0 dropped, Arabic-Indic digits accepted, E.164 when the
 *   field is left, errors only after it is left, any other country by its code.
 * - My settings › Profile › Phone number: loads the saved number, saves E.164
 *   with PUT /api/v1/me/phone, and removes it when emptied.
 */

import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { PhoneInput, splitE164 } from '@whity/ui/phone-input';

const apiGet = jest.fn();
const apiPut = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...a: unknown[]) => apiGet(...a),
    PUT: (...a: unknown[]) => apiPut(...a),
  },
}));
const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));

import { MyPhoneSettings } from '@/components/settings/my-phone-settings';

const ok = (phone: string | null) => Promise.resolve({ data: { data: { phone } }, response: { ok: true, status: 200 } });

describe('PhoneInput', () => {
  it('starts in Jordan, groups as read, drops the leading 0 and gives E.164 when left', () => {
    const onChange = jest.fn();
    render(<PhoneInput label="Phone" onChange={onChange} />);
    const input = screen.getByLabelText('Phone') as HTMLInputElement;
    fireEvent.change(input, { target: { value: '0791234567' } });
    expect(input.value).toBe('79 123 4567');
    expect(onChange).not.toHaveBeenCalled();
    fireEvent.blur(input);
    expect(onChange).toHaveBeenLastCalledWith('+962791234567', true);
  });

  it('reads Arabic-Indic digits and stays left to right', () => {
    const onChange = jest.fn();
    render(<PhoneInput label="Phone" lang="ar" onChange={onChange} />);
    const input = screen.getByLabelText('Phone');
    fireEvent.change(input, { target: { value: '٠٧٩٥٥٥١٢٣٤' } });
    fireEvent.blur(input);
    expect(onChange).toHaveBeenLastCalledWith('+962795551234', true);
    expect(input.closest('[dir]')).toHaveAttribute('dir', 'ltr');
    expect(screen.getByRole('option', { name: 'الأردن +962' })).toBeInTheDocument();
  });

  it('says a number is too short only once the field is left', () => {
    const onChange = jest.fn();
    render(<PhoneInput label="Phone" onChange={onChange} />);
    const input = screen.getByLabelText('Phone');
    fireEvent.change(input, { target: { value: '79123' } });
    expect(screen.queryByRole('alert')).toBeNull();
    fireEvent.blur(input);
    expect(screen.getByRole('alert')).toHaveTextContent('This number is too short for Jordan.');
    expect(onChange).toHaveBeenLastCalledWith('+96279123', false);
  });

  it('starts from a saved E.164 in its own country, and keeps any other country whole', () => {
    expect(splitE164('+447400123456')).toEqual({ country: 'GB', digits: '7400123456' });
    expect(splitE164('+962791234567')).toEqual({ country: 'JO', digits: '791234567' });
    expect(splitE164('+33612345678')).toEqual({ country: 'OTHER', digits: '33612345678' });

    const onChange = jest.fn();
    render(<PhoneInput label="Phone" defaultValue="+33612345678" onChange={onChange} />);
    const input = screen.getByLabelText('Phone') as HTMLInputElement;
    expect(input.value).toBe('+33612345678');
    fireEvent.blur(input);
    expect(onChange).toHaveBeenLastCalledWith('+33612345678', true);
  });

  it('gives null when emptied', () => {
    const onChange = jest.fn();
    render(<PhoneInput label="Phone" defaultValue="+962791234567" onChange={onChange} />);
    const input = screen.getByLabelText('Phone');
    fireEvent.change(input, { target: { value: '' } });
    fireEvent.blur(input);
    expect(onChange).toHaveBeenLastCalledWith(null, true);
  });
});

describe('MyPhoneSettings', () => {
  beforeEach(() => {
    apiGet.mockReset();
    apiPut.mockReset();
    addToast.mockReset();
  });

  it('shows the saved number and saves a new one as E.164', async () => {
    apiGet.mockReturnValue(ok('+962791234567'));
    apiPut.mockReturnValue(ok('+962795551234'));
    render(<MyPhoneSettings />);
    const input = (await screen.findByLabelText('Phone number')) as HTMLInputElement;
    expect(input.value).toBe('79 123 4567');

    fireEvent.change(input, { target: { value: '79 555 1234' } });
    fireEvent.blur(input);
    fireEvent.click(screen.getByTestId('my-phone-save'));

    await waitFor(() => expect(apiPut).toHaveBeenCalledWith('/api/v1/me/phone', { body: { phone: '+962795551234' } }));
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Phone number saved.', 'success'));
  });

  it('removes the number when the field is emptied', async () => {
    apiGet.mockReturnValue(ok('+962791234567'));
    apiPut.mockReturnValue(ok(null));
    render(<MyPhoneSettings />);
    const input = await screen.findByLabelText('Phone number');
    fireEvent.change(input, { target: { value: '' } });
    fireEvent.blur(input);
    fireEvent.click(screen.getByTestId('my-phone-save'));
    await waitFor(() => expect(apiPut).toHaveBeenCalledWith('/api/v1/me/phone', { body: { phone: null } }));
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Phone number removed.', 'success'));
  });

  it('sends nothing while the number is incomplete', async () => {
    apiGet.mockReturnValue(ok(null));
    render(<MyPhoneSettings />);
    const input = await screen.findByLabelText('Phone number');
    fireEvent.change(input, { target: { value: '79123' } });
    fireEvent.blur(input);
    expect(screen.getByTestId('my-phone-save')).toBeDisabled();
    expect(apiPut).not.toHaveBeenCalled();
  });
});
