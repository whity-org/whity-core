/**
 * The shared pieces of design milestone A0: confirm tiers (P.4), the save bar
 * (P.3), money in normal units (3.7b) and table selection (7.6).
 */
import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { DataTable } from '@whity/ui/data-table';
import { MoneyInput, normaliseMoneyText } from '@whity/ui/money/money-input';
import { SaveBar } from '@whity/ui/save-bar';
import { DisplayPreferencesContextProvider, TechnicalDetails } from '@whity/ui/display-preferences';

describe('ConfirmAction', () => {
  it('keeps tier 3 disabled until the name is typed, then confirms and closes', async () => {
    const onConfirm = jest.fn();
    const onOpenChange = jest.fn();
    render(
      <ConfirmAction
        open
        onOpenChange={onOpenChange}
        title="Clear two-step for Hala?"
        description="This removes her authenticator."
        confirmLabel="Clear two-step"
        cancelLabel="Cancel"
        onConfirm={onConfirm}
        typeToConfirm={{ value: 'hala@example.com', label: 'Type hala@example.com to confirm' }}
      />,
    );
    const confirm = screen.getByRole('button', { name: 'Clear two-step' });
    expect(confirm).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Type hala@example.com to confirm'), { target: { value: 'hala' } });
    expect(confirm).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Type hala@example.com to confirm'), { target: { value: 'hala@example.com' } });
    expect(confirm).toBeEnabled();
    fireEvent.click(confirm);
    expect(onConfirm).toHaveBeenCalledTimes(1);
    await screen.findByRole('button', { name: 'Clear two-step' });
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it('tier 2 needs no typing', () => {
    render(
      <ConfirmAction
        open
        onOpenChange={() => {}}
        title="Delete the role?"
        description="6 people lose it."
        confirmLabel="Delete role"
        cancelLabel="Cancel"
        onConfirm={() => {}}
      />,
    );
    expect(screen.getByRole('button', { name: 'Delete role' })).toBeEnabled();
  });
});

describe('SaveBar', () => {
  const props = { summary: 'Unit changed', discardLabel: 'Discard', saveLabel: 'Save changes', onDiscard: () => {}, onSave: () => {} };

  it('shows only when something changed', () => {
    const { rerender } = render(<SaveBar dirty={false} {...props} />);
    expect(screen.queryByText('Unit changed')).not.toBeInTheDocument();
    rerender(<SaveBar dirty {...props} />);
    expect(screen.getByText('Unit changed')).toBeInTheDocument();
  });

  it('asks before leaving while dirty, and stops asking afterwards', () => {
    const { rerender } = render(<SaveBar dirty {...props} />);
    const leave = () => {
      const event = new Event('beforeunload', { cancelable: true });
      window.dispatchEvent(event);
      return event.defaultPrevented;
    };
    expect(leave()).toBe(true);
    rerender(<SaveBar dirty={false} {...props} />);
    expect(leave()).toBe(false);
  });
});

describe('MoneyInput', () => {
  it('turns Arabic digits and marks into Western ones', () => {
    expect(normaliseMoneyText('١٬٢٣٤٫٥')).toBe('1234.5');
    expect(normaliseMoneyText('1,234.50')).toBe('1234.50');
  });

  it('shows the currency decimals when not focused and refuses too many', () => {
    function Host() {
      const [value, setValue] = useState('1234.5');
      return <MoneyInput aria-label="Price" currency="JOD" value={value} onValueChange={setValue} />;
    }
    render(<Host />);
    const input = screen.getByLabelText('Price') as HTMLInputElement;
    expect(input.value).toBe('1,234.500');
    fireEvent.focus(input);
    fireEvent.change(input, { target: { value: '1.2345' } });
    expect(screen.getByText('No more than 3 decimals for JOD.')).toBeInTheDocument();
  });
});

describe('DataTable selection', () => {
  const rows = [
    { id: 'a', name: 'Rana', amount: 10 },
    { id: 'b', name: 'Omar', amount: 5 },
  ];
  const columns = [{ accessorKey: 'name' as const, header: 'Name' }];

  function Host({ onRowClick }: { onRowClick?: (row: (typeof rows)[number]) => void }) {
    const [selected, setSelected] = useState<ReadonlySet<string>>(new Set());
    return (
      <DataTable
        columns={columns}
        data={rows}
        getRowId={(row) => row.id}
        onRowClick={onRowClick}
        selection={{
          selected,
          onSelectedChange: setSelected,
          summary: (chosen) => `JOD ${chosen.reduce((sum, row) => sum + row.amount, 0)}`,
        }}
      />
    );
  }

  it('selects, totals, selects all and clears', () => {
    render(<Host />);
    fireEvent.click(screen.getAllByRole('checkbox', { name: 'Select row' })[0]);
    const bar = screen.getByRole('region');
    expect(within(bar).getByText('1 selected')).toBeInTheDocument();
    expect(within(bar).getByText('JOD 10')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('checkbox', { name: 'Select all' }));
    expect(within(screen.getByRole('region')).getByText('2 selected')).toBeInTheDocument();
    fireEvent.click(within(screen.getByRole('region')).getByRole('button', { name: 'Clear' }));
    expect(screen.queryByRole('region')).not.toBeInTheDocument();
  });

  it('opens a row on click, but not on a click on its checkbox', () => {
    const onRowClick = jest.fn();
    render(<Host onRowClick={onRowClick} />);
    fireEvent.click(screen.getAllByRole('checkbox', { name: 'Select row' })[0]);
    expect(onRowClick).not.toHaveBeenCalled();
    fireEvent.click(screen.getByText('Omar'));
    expect(onRowClick).toHaveBeenCalledWith(rows[1]);
  });
});

describe('TechnicalDetails', () => {
  const update = () => {};
  it('renders only when the person turned them on', () => {
    const { rerender } = render(
      <DisplayPreferencesContextProvider value={{ textSize: 'standard', showTechnicalDetails: false, update }}>
        <TechnicalDetails>users:read</TechnicalDetails>
      </DisplayPreferencesContextProvider>,
    );
    expect(screen.queryByText('users:read')).not.toBeInTheDocument();
    rerender(
      <DisplayPreferencesContextProvider value={{ textSize: 'standard', showTechnicalDetails: true, update }}>
        <TechnicalDetails>users:read</TechnicalDetails>
      </DisplayPreferencesContextProvider>,
    );
    expect(screen.getByText('users:read')).toBeInTheDocument();
  });
});
