/**
 * PwBuilders: under 640px the route template and the role permissions are read,
 * not edited. Pins the sentences, the lists, the note and the Open on a computer
 * sheet; wide windows keep the table (desktop is unchanged).
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PermissionAreaGrid } from '../../packages/features/src/roles/permission-area-grid';
import type { Permission } from '@whity/features/roles';
import type { RouteFlowGraph, RouteFlowStep } from '@whity/ui/route-flow/model';
import { RouteReadOnly } from '@/app/(protected)/admin/document-route-templates/[id]/route-read-only';

jest.mock('@whity/features/i18n', () => ({
  useTranslation: () => (_key: string, fallback?: string, vars?: Record<string, string | number>) =>
    Object.entries(vars ?? {}).reduce((acc, [k, v]) => acc.replaceAll(`{${k}}`, String(v)), fallback ?? _key),
}));

function setWidth(wide: boolean) {
  window.matchMedia = ((query: string) => ({
    matches: wide,
    media: query,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
  })) as unknown as typeof window.matchMedia;
}

const t = (_key: string, fallback?: string, vars?: Record<string, string | number>): string =>
  Object.entries(vars ?? {}).reduce((acc, [k, v]) => acc.replaceAll(`{${k}}`, String(v)), fallback ?? _key);

const step = (position: number, over: Partial<RouteFlowStep>): RouteFlowStep => ({
  position,
  ruleKind: 'role',
  ruleConfig: { role_id: 1 },
  label: null,
  decision: true,
  decisionQuorum: null,
  satisfiedBy: 'act',
  canvasX: 0,
  canvasY: 0,
  ...over,
});

describe('route template on a phone', () => {
  const graph: RouteFlowGraph = {
    defaultQuorum: 'all',
    edges: [{ from: 2, to: 4, verdict: 'approved' }, { from: 2, to: 3, verdict: 'rejected' }],
    steps: [
      step(1, { label: 'Awss Haddad', decision: false }),
      step(2, { ruleConfig: { role_id: 7 }, decisionQuorum: 'any' }),
      step(3, { label: 'The buyer', decision: false }),
      step(4, { label: 'Filing', decision: false, satisfiedBy: 'delivery' }),
    ],
  };

  it('reads as numbered sentences with where a rejection goes', async () => {
    setWidth(false);
    render(
      <RouteReadOnly
        name="Purchase orders"
        graph={graph}
        rules={[]}
        roles={[{ id: 7, name: 'finance' }]}
        groups={[]}
      />
    );
    const steps = screen.getAllByTestId('route-read-only-step');
    expect(steps).toHaveLength(4);
    expect(steps[0]).toHaveTextContent('Awss Haddad');
    expect(steps[1]).toHaveTextContent('finance approves');
    expect(steps[1]).toHaveTextContent('any one may approve');
    expect(steps[1]).toHaveTextContent('If approved, it goes to step 4');
    expect(steps[1]).toHaveTextContent('If rejected, it goes to step 3');
    expect(steps[3]).toHaveTextContent('Filing');
    expect(screen.getByRole('note')).toHaveTextContent('open it on a computer');

    await userEvent.click(screen.getByRole('button', { name: 'Open on a computer' }));
    expect(await screen.findByRole('link', { name: 'Email me the link' })).toHaveAttribute(
      'href',
      expect.stringContaining('mailto:')
    );
  });
});

describe('role permissions on a phone', () => {
  const catalogue: Permission[] = [
    { id: 1, name: 'invoices:read', description: 'Read invoices', area: 'invoices', areaLabel: 'Invoices', areaGroup: 'documents', actions: ['see'] },
    { id: 2, name: 'invoices:delete', description: 'Delete invoices', area: 'invoices', areaLabel: 'Invoices', areaGroup: 'documents', actions: ['delete'] },
  ];
  const grid = () => (
    <PermissionAreaGrid permissions={catalogue} selectedIds={[1]} onChange={() => undefined} t={t} />
  );

  it('is a read-only list with words, not a table of ticks', () => {
    setWidth(false);
    render(grid());
    const area = screen.getByTestId('perm-list-area-invoices');
    expect(area).toHaveTextContent('See');
    expect(area).toHaveTextContent('Allowed');
    expect(area).toHaveTextContent('Not allowed');
    expect(area.querySelector('input')).toBeNull();
    expect(screen.queryByRole('table')).toBeNull();
    expect(screen.getByRole('note')).toHaveTextContent('open this role on a computer');
    expect(screen.getByRole('button', { name: 'Open on a computer' })).toBeInTheDocument();
  });

  it('keeps the editable table on a wide window', () => {
    setWidth(true);
    render(grid());
    expect(screen.getByRole('table')).toBeInTheDocument();
    expect(screen.queryByTestId('perm-list')).toBeNull();
    expect(screen.queryByRole('note')).toBeNull();
  });
});
