import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { RoleSummary, roleSummaryText, uniqueRoles } from '@whity/ui/role-summary';
import { TenantSwitcher } from '@whity/ui/tenant-switcher';
import { roleLabels } from '@/lib/workspace-memberships';

// WHIT-1175: a person holding many roles used to get a subtitle as long as the
// list, which wrapped and pushed rows apart. Eight long names, as in the report.
const EIGHT = [
  'Head of finance and accounts',
  'Branch manager for the north region',
  'Document approver',
  'Inventory controller',
  'Customer service supervisor',
  'Payroll officer',
  'Marketing coordinator',
  'Quality assurance reviewer',
];

describe('RoleSummary', () => {
  test('shows the first role and "+N more" on one line that cannot wrap', () => {
    const { container } = render(<RoleSummary roles={EIGHT} />);
    const root = container.querySelector('[data-slot="role-summary"]') as HTMLElement;

    expect(root.className).toContain('whitespace-nowrap');
    expect(root.className).toContain('overflow-hidden');
    expect(root.className).toContain('min-w-0');
    expect(root.querySelector('.truncate')?.textContent).toBe('Head of finance and accounts');
    const more = root.querySelector('[data-slot="role-summary-more"]') as HTMLElement;
    expect(more.textContent).toBe('+7 more');
    // The count never shrinks away: the first role truncates instead.
    expect(more.className).toContain('shrink-0');
  });

  test('keeps the whole list in the title and for screen readers', () => {
    const { container } = render(<RoleSummary roles={EIGHT} />);
    const root = container.querySelector('[data-slot="role-summary"]') as HTMLElement;

    expect(root.title).toBe(EIGHT.join(', '));
    expect(container.querySelector('.sr-only')?.textContent).toBe(EIGHT.join(', '));
    // The short form is not read twice.
    for (const visual of root.querySelectorAll(':scope > :not(.sr-only)')) {
      expect(visual.getAttribute('aria-hidden')).toBe('true');
    }
  });

  test('one role is just the role, with no count and no title', () => {
    const { container } = render(<RoleSummary roles={['admin']} />);
    expect(container.textContent).toContain('admin');
    expect(container.querySelector('[data-slot="role-summary-more"]')).toBeNull();
    expect((container.querySelector('[data-slot="role-summary"]') as HTMLElement).title).toBe('');
  });

  test('nothing to show renders nothing', () => {
    const { container } = render(<RoleSummary roles={[]} />);
    expect(container.firstChild).toBeNull();
  });

  test('words before and after share the line and the full text', () => {
    const { container } = render(
      <RoleSummary roles={['admin', 'baker', 'cashier']} before="Profile" after="Example Bakery" />,
    );
    expect(container.querySelector('.sr-only')?.textContent).toBe('Profile · admin, baker, cashier · Example Bakery');
    expect(container.textContent).toContain('Profile · admin');
    expect(container.textContent).toContain('+2 more');
    expect(container.textContent).toContain('· Example Bakery');
  });

  test('Arabic labels: the Arabic comma and count words', () => {
    const { container } = render(
      <RoleSummary
        roles={['مدير', 'محاسب', 'موظف استقبال']}
        labels={{ more: (count) => `+${count} أخرى`, separator: '، ' }}
      />,
    );
    // The count stays in Western digits.
    expect(container.querySelector('[data-slot="role-summary-more"]')?.textContent).toBe('+2 أخرى');
    expect(container.querySelector('.sr-only')?.textContent).toBe('مدير، محاسب، موظف استقبال');
  });

  test('interactive: a focusable "+N more" names and shows the full list', async () => {
    const user = userEvent.setup();
    render(<RoleSummary roles={EIGHT} interactive labels={{ all: 'All roles: {roles}' }} />);

    const more = screen.getByRole('button', { name: `All roles: ${EIGHT.join(', ')}` });
    expect(more.textContent).toBe('+7 more');
    await user.tab();
    expect(more).toHaveFocus();
    // Keyboard focus opens the tooltip at once.
    expect((await screen.findAllByText(EIGHT.join(', '))).length).toBeGreaterThan(0);
  });

  test('helpers drop repeats and blanks', () => {
    expect(uniqueRoles(['a', '', 'b', 'a', ' '])).toEqual(['a', 'b']);
    expect(roleSummaryText(['a', 'b'], { before: 'Owner' })).toBe('Owner · a, b');
  });
});

describe('roleLabels', () => {
  test('every role in words, the primary first', () => {
    expect(roleLabels({ role: 'hello_viewer', roles: ['admin', 'hello_viewer'] })).toEqual(['Hello viewer', 'admin']);
  });
});

describe('TenantSwitcher with many roles', () => {
  test('a row shows Owner, the first role and "+N more"', async () => {
    const user = userEvent.setup();
    render(
      <TenantSwitcher
        workspaces={[
          { id: '1', name: 'Example Bakery', roles: EIGHT, roleBefore: 'Owner' },
          { id: '2', name: 'Example Clinic', role: 'Doctor' },
        ]}
        currentId="1"
        onChange={() => {}}
      />,
    );
    await user.click(screen.getByRole('button', { name: /Switch workspace/ }));

    const option = screen.getAllByRole('option')[0];
    expect(option.textContent).toContain('Owner · Head of finance and accounts');
    expect(option.textContent).toContain('+7 more');
    expect(screen.getAllByRole('option')[1].textContent).toContain('Doctor');
  });
});
