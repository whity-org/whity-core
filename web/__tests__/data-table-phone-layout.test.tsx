/**
 * WHIT-1001: below 640px a DataTable shows each row as stacked lines instead
 * of a grid that scrolls sideways. jsdom has no layout, so these tests pin the
 * markup the phone CSS reads: the first column is the row's title, every
 * other column carries its header as `data-label`, and the checkbox and
 * actions keep their own roles. `phoneLayout="scroll"` and tables with totals
 * keep the plain grid.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import { DataTable } from '@whity/ui/data-table';

type Row = { id: number; name: string; role: string; unit: string };
const data: Row[] = [{ id: 1, name: 'Amro Saleh', role: 'Owner', unit: 'Example Bakery' }];
const columns = [
  { accessorKey: 'name' as const, header: 'Name' },
  { accessorKey: 'role' as const, header: 'Role' },
  { accessorKey: 'unit' as const, header: 'Unit' },
];

function cellsOfFirstRow(): HTMLElement[] {
  const rows = screen.getAllByRole('row');
  return Array.from(rows[rows.length - 1].querySelectorAll('td'));
}

it('stacks rows on phones by default: title first, other columns labelled', () => {
  render(<DataTable data={data} getRowId={(r) => String(r.id)} columns={columns} rowActions={() => <button>Edit</button>} />);

  expect(screen.getByRole('table')).toHaveAttribute('data-phone-layout', 'stack');
  const [title, role, unit, actions] = cellsOfFirstRow();
  expect(title).toHaveTextContent('Amro Saleh');
  expect(title).not.toHaveAttribute('data-label');
  expect(title.className).toContain('max-sm:flex-1');
  expect(role).toHaveAttribute('data-label', 'Role');
  expect(unit).toHaveAttribute('data-label', 'Unit');
  expect(unit.className).toContain('max-sm:before:content-[attr(data-label)]');
  expect(actions).not.toHaveAttribute('data-label');
  expect(actions.className).toContain('max-sm:ms-auto');
  // The header row is hidden on phones; each line names itself instead.
  expect(screen.getByRole('table').querySelector('thead')?.className).toContain('max-sm:hidden');
});

it('keeps the checkbox cell out of the title when rows can be selected', () => {
  render(
    <DataTable
      data={data}
      getRowId={(r) => String(r.id)}
      columns={columns}
      selection={{ selected: new Set<string>(), onSelectedChange: () => {} }}
    />,
  );
  const [select, title] = cellsOfFirstRow();
  expect(select.className).toContain('max-sm:order-1');
  expect(select).not.toHaveAttribute('data-label');
  expect(title).toHaveTextContent('Amro Saleh');
});

it('leaves the grid alone with phoneLayout="scroll"', () => {
  render(<DataTable data={data} getRowId={(r) => String(r.id)} columns={columns} phoneLayout="scroll" />);
  expect(screen.getByRole('table')).not.toHaveAttribute('data-phone-layout');
  for (const cell of cellsOfFirstRow()) expect(cell).not.toHaveAttribute('data-label');
});
