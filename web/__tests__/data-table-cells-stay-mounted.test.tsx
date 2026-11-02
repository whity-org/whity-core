/**
 * WHIT-800: a row's menu stays open and attached while the page re-renders.
 *
 * Callers build `columns` and `rowActions` inline, so they are new on every
 * render. The table renders a cell's function as a React component, so a new
 * function is a new component type: React unmounted and remounted every cell on
 * every parent render, closing an open row menu and detaching its items from the
 * DOM mid-click. That was the E2E flake on the users screen.
 */

import React, { useEffect, useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { DataTable } from '@whity/ui/data-table';

const mounts = jest.fn();

function Probe({ label }: { label: string }) {
  useEffect(() => {
    mounts(label);
  }, [label]);
  return <span>{label}</span>;
}

function Page({ prefix }: { prefix: string }) {
  const [, setTick] = useState(0);
  return (
    <>
      <button onClick={() => setTick((n) => n + 1)}>Re-render</button>
      <DataTable
        data={[{ id: 1, name: 'Rana' }]}
        getRowId={(row) => String(row.id)}
        columns={[{ accessorKey: 'name', header: 'Name', cell: (row) => <Probe label={`${prefix} cell ${row.name}`} /> }]}
        rowActions={(row) => <Probe label={`${prefix} menu ${row.name}`} />}
      />
    </>
  );
}

beforeEach(() => mounts.mockClear());

it('keeps cells and row menus mounted when the parent re-renders', () => {
  render(<Page prefix="a" />);
  expect(mounts).toHaveBeenCalledTimes(2);

  const menu = screen.getByText('a menu Rana');
  fireEvent.click(screen.getByRole('button', { name: 'Re-render' }));
  fireEvent.click(screen.getByRole('button', { name: 'Re-render' }));

  expect(mounts).toHaveBeenCalledTimes(2);
  expect(screen.getByText('a menu Rana')).toBe(menu);
});

it('still renders with the latest closures', () => {
  const { rerender } = render(<Page prefix="a" />);
  rerender(<Page prefix="b" />);
  expect(screen.getByText('b cell Rana')).toBeInTheDocument();
  expect(screen.getByText('b menu Rana')).toBeInTheDocument();
});
