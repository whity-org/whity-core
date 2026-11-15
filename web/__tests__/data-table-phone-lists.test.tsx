/**
 * PwLists (PW.4): a stacked DataTable on a phone gets the phone app's list
 * controls. Sort by the list opens a sheet with one choice per line; Select
 * turns the boxes on with Cancel, the count and Clear all / Choose all; search,
 * Filter and the main button sit in a bar at the bottom; filters on show are
 * chips with a cross. None of it renders on a wider window.
 */

import React from 'react';
import { render, screen, within } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { DataTable } from '@whity/ui/data-table';

type Row = { id: number; number: string; customer: string };
const data: Row[] = [
  { id: 1, number: 'INV-1048', customer: 'Awss Haddad' },
  { id: 2, number: 'INV-1047', customer: 'Ibrahim Mansour' },
];
const columns = [
  { accessorKey: 'number' as const, header: 'Number', enableSorting: true },
  { accessorKey: 'customer' as const, header: 'Customer', enableSorting: true },
];

function phoneWidth(matches: boolean) {
  window.matchMedia = jest.fn().mockImplementation((query: string) => ({
    matches: matches && query.includes('max-width: 639.98px'),
    media: query,
    addEventListener: jest.fn(),
    removeEventListener: jest.fn(),
  })) as unknown as typeof window.matchMedia;
}

function Selectable(props: Partial<React.ComponentProps<typeof DataTable<Row>>>) {
  const [selected, setSelected] = React.useState<Set<string>>(new Set());
  return (
    <DataTable
      data={data}
      columns={columns}
      getRowId={(r) => String(r.id)}
      selection={{ selected, onSelectedChange: setSelected, actions: <button type="button">Remind</button> }}
      enableGlobalFilter
      globalFilterPlaceholder="Search invoices"
      {...props}
    />
  );
}

afterEach(() => {
  // @ts-expect-error reset between tests
  delete window.matchMedia;
});

it('renders none of the phone controls on a wider window', () => {
  phoneWidth(false);
  render(<Selectable />);
  expect(screen.queryByTestId('data-table-phone-sort')).toBeNull();
  expect(screen.getAllByPlaceholderText('Search invoices')).toHaveLength(1);
});

it('sorts from a sheet with one choice per line, the current one ticked', async () => {
  phoneWidth(true);
  render(<Selectable />);
  const sort = screen.getByTestId('data-table-phone-sort');
  expect(sort).toHaveTextContent('Default order');
  await userEvent.click(sort);
  const choices = await screen.findAllByRole('radio');
  expect(choices.map((c) => c.textContent)).toEqual([
    'Default order',
    'Number, lowest first',
    'Number, highest first',
    'Customer, lowest first',
    'Customer, highest first',
  ]);
  await userEvent.click(screen.getByRole('radio', { name: 'Customer, highest first' }));
  expect(screen.getByTestId('data-table-phone-sort')).toHaveTextContent('Customer, highest first');
  const rows = screen.getAllByRole('row');
  expect(rows[1]).toHaveTextContent('INV-1047');
});

it('selects with Cancel, the count and Choose all / Clear all, and a bulk bar at the bottom', async () => {
  phoneWidth(true);
  render(<Selectable />);
  await userEvent.click(screen.getByTestId('data-table-phone-select'));
  const toolbar = screen.getByText('0 chosen').parentElement as HTMLElement;
  await userEvent.click(within(toolbar).getByRole('button', { name: 'Choose all' }));
  expect(screen.getByText('2 chosen')).toBeInTheDocument();
  const bar = document.querySelector('[data-slot="data-table-bulk-bar"]') as HTMLElement;
  expect(bar.className).toContain('max-sm:fixed');
  expect(within(bar).getByRole('button', { name: 'Remind' })).toBeInTheDocument();
  // The search bar steps aside while choosing.
  expect(document.querySelector('[data-slot="data-table-phone-bar"]')).toBeNull();
  await userEvent.click(within(toolbar).getByRole('button', { name: 'Clear all' }));
  expect(screen.getByText('0 chosen')).toBeInTheDocument();
  await userEvent.click(within(toolbar).getByRole('button', { name: 'Cancel' }));
  expect(screen.getByTestId('data-table-phone-select')).toBeInTheDocument();
});

it('puts search, Filter and the main button at the bottom, with filter chips', async () => {
  phoneWidth(true);
  const onRemove = jest.fn();
  const onCreate = jest.fn();
  render(
    <Selectable
      phone={{
        mainAction: { label: 'New invoice', onClick: onCreate },
        filters: {
          content: <p>Status choices</p>,
          chips: [{ id: 'overdue', label: 'Overdue', onRemove }],
          onClear: jest.fn(),
        },
      }}
    />,
  );
  const bar = document.querySelector('[data-slot="data-table-phone-bar"]') as HTMLElement;
  expect(within(bar).getByPlaceholderText('Search invoices')).toBeInTheDocument();
  expect(screen.getAllByPlaceholderText('Search invoices')).toHaveLength(1);
  await userEvent.click(within(bar).getByRole('button', { name: 'New invoice' }));
  expect(onCreate).toHaveBeenCalled();
  await userEvent.click(within(bar).getByTestId('data-table-phone-filter'));
  expect(await screen.findByText('Status choices')).toBeInTheDocument();
  await userEvent.click(screen.getByRole('button', { name: 'Done' }));
  await userEvent.click(screen.getByRole('button', { name: 'Remove filter: Overdue' }));
  expect(onRemove).toHaveBeenCalled();
});
