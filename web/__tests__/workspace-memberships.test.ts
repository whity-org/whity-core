import { oneEntryPerWorkspace, roleNames, rolesLabel } from '@/lib/workspace-memberships';

describe('oneEntryPerWorkspace', () => {
  test('folds one row per role into one entry per workspace, keeping order', () => {
    const folded = oneEntryPerWorkspace([
      { tenant_id: 1, tenant_name: 'Example Bakery', role: 'admin' },
      { tenant_id: 4, tenant_name: 'Example Clinic', role: 'doctor' },
      { tenant_id: 1, tenant_name: 'Example Bakery', role: 'baker' },
    ]);

    expect(folded.map((m) => m.tenant_id)).toEqual([1, 4]);
    expect(folded[0].role).toBe('admin');
    expect(folded[0].roles).toEqual(['admin', 'baker']);
    expect(folded[1].roles).toEqual(['doctor']);
  });

  test('keeps an already-folded entry from the server as it is', () => {
    const folded = oneEntryPerWorkspace([
      { tenant_id: 1, tenant_name: 'Example Bakery', role: 'admin', roles: ['admin', 'baker'] },
    ]);

    expect(folded).toHaveLength(1);
    expect(folded[0].roles).toEqual(['admin', 'baker']);
  });

  test('a member row makes the person a member; customer-only stays customer', () => {
    const folded = oneEntryPerWorkspace([
      { tenant_id: 1, role: 'client', kind: 'customer' as const },
      { tenant_id: 1, role: 'admin', kind: 'member' as const },
      { tenant_id: 2, role: 'client', kind: 'customer' as const },
      { tenant_id: 2, role: 'vip', kind: 'customer' as const },
    ]);

    expect(folded[0].kind).toBe('member');
    expect(folded[1].kind).toBe('customer');
  });
});

describe('roleNames and rolesLabel', () => {
  test('list the primary role first, without repeats or blanks', () => {
    expect(roleNames({ role: 'admin', roles: ['admin', 'baker', ''] })).toEqual(['admin', 'baker']);
    expect(roleNames({ role: '' })).toEqual([]);
  });

  test('read plugin role names as a person would', () => {
    expect(rolesLabel({ role: 'admin', roles: ['admin', 'hello_viewer'] })).toBe('admin, Hello viewer');
  });
});
