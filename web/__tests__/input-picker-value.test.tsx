/**
 * @jest-environment ./jest.amman-environment.js
 *
 * What a person picks has to reach the form, and on the day they picked.
 *
 * The pickers look right even when this fails: the field shows the date and the
 * form saves nothing. A delegation meant to end on a date saved with no end date
 * that way, so it never expired. These run in Amman time because the day only
 * slips east of UTC.
 */

import { useState } from 'react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Input } from '@whity/ui/input';
import { DatePicker } from '@whity/ui/date-picker';

function Controlled({
  type,
  start = '',
  onValue,
}: {
  type: string;
  start?: string;
  onValue: (value: string) => void;
}) {
  const [value, setValue] = useState(start);

  return (
    <>
      <Input
        id="field"
        type={type}
        name="field"
        placeholder="Choose"
        value={value}
        onChange={(e) => {
          setValue(e.target.value);
          onValue(e.target.value);
        }}
      />
      <button type="button" onClick={() => setValue('')}>
        Reset
      </button>
    </>
  );
}

const currentMonth = () => {
  const now = new Date();

  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
};

describe('Input type="date"', () => {
  it('runs in Amman time', () => {
    expect(new Date(2026, 10, 28).getTimezoneOffset()).toBe(-180);
  });

  it('gives the form the day that was picked, not the day before', async () => {
    const onValue = jest.fn();
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    render(<Controlled type="date" onValue={onValue} />);

    await user.click(screen.getByRole('button', { name: /Choose/ }));
    await user.click(await screen.findByRole('button', { name: '15' }));

    expect(onValue).toHaveBeenCalledTimes(1);
    expect(onValue).toHaveBeenCalledWith(`${currentMonth()}-15`);
  });

  it('shows the same day it was given', () => {
    render(<Controlled type="date" start="2026-11-28" onValue={jest.fn()} />);

    expect(screen.getByRole('button', { name: /Nov 28, 2026/ })).toBeInTheDocument();
  });

  it('reports clearing the date', async () => {
    const onValue = jest.fn();
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    render(<Controlled type="date" start="2026-11-28" onValue={onValue} />);

    await user.click(screen.getByRole('button', { name: 'Clear date' }));

    expect(onValue).toHaveBeenCalledWith('');
  });

  it('reports the same day again after the form has been reset', async () => {
    const onValue = jest.fn();
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    render(<Controlled type="date" onValue={onValue} />);

    await user.click(screen.getByRole('button', { name: /Choose/ }));
    await user.click(await screen.findByRole('button', { name: '15' }));
    await user.click(screen.getByRole('button', { name: 'Reset' }));
    await user.click(screen.getByRole('button', { name: /Choose/ }));
    await user.click(await screen.findByRole('button', { name: '15' }));

    expect(onValue).toHaveBeenCalledTimes(2);
  });

  it('opens from its label', () => {
    render(
      <>
        <label htmlFor="field">Until</label>
        <Controlled type="date" onValue={jest.fn()} />
      </>
    );

    expect(screen.getByLabelText('Until').tagName).toBe('BUTTON');
  });

  it('still submits with a plain form', async () => {
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    const { container } = render(
      <form>
        <Controlled type="date" onValue={jest.fn()} />
      </form>
    );

    await user.click(screen.getByRole('button', { name: /Choose/ }));
    await user.click(await screen.findByRole('button', { name: '15' }));

    const form = container.querySelector('form') as HTMLFormElement;
    expect(new FormData(form).get('field')).toBe(`${currentMonth()}-15`);
  });
});

describe('DatePicker', () => {
  it('reads a date with no time as that day, wherever the person is', () => {
    render(<DatePicker value="2026-01-01" />);

    expect(screen.getByRole('button', { name: /Jan 1, 2026/ })).toBeInTheDocument();
  });
});

describe('the other pickers Input swaps in', () => {
  it('reports a colour', async () => {
    const onValue = jest.fn();
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    render(<Controlled type="color" start="#112233" onValue={onValue} />);

    await user.click(screen.getByRole('button', { name: /#112233/i }));
    fireEvent.change(await screen.findByPlaceholderText('#000000'), { target: { value: '#445566' } });

    expect(onValue).toHaveBeenLastCalledWith(expect.stringMatching(/^#445566/i));
  });

  it('reports a time', async () => {
    const onValue = jest.fn();
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    const { container } = render(<Controlled type="time" onValue={onValue} />);

    await user.click(within(container).getAllByRole('button')[0]);
    const listbox = document.body;
    const now = within(listbox).queryByRole('button', { name: /^Now$/i });
    if (now !== null) {
      await user.click(now);
    } else {
      await user.click(within(listbox).getByRole('button', { name: '05' }));
    }

    expect(onValue).toHaveBeenCalled();
    expect(String(onValue.mock.calls.at(-1)?.[0])).toMatch(/\d{1,2}:\d{2}/);
  });
});
