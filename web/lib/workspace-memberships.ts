/**
 * One entry per workspace for every workspace picker.
 *
 * A person may hold several roles in one workspace, and the server keeps one
 * membership row per role. The API already folds them into one entry with a
 * `roles` list; this folds again on the client so a picker never shows the same
 * workspace twice, whatever answered. Permissions in the workspace are the union
 * of all the roles, so the entry names every one.
 */

import { roleLabel } from '@whity/features/roles/role-label';

export interface WorkspaceMembershipLike {
  tenant_id: number;
  role: string;
  roles?: string[];
  kind?: 'member' | 'customer';
  is_owner?: boolean;
}

/** Every role the person holds in this workspace, the primary one first. */
export function roleNames(membership: Pick<WorkspaceMembershipLike, 'role' | 'roles'>): string[] {
  const names = Array.isArray(membership.roles) ? membership.roles : [];
  const all = membership.role ? [membership.role, ...names] : names;
  return all.filter((name, index) => name !== '' && all.indexOf(name) === index);
}

/** The roles as one label, e.g. "admin, Hello viewer". Empty when there are none. */
export function rolesLabel(membership: Pick<WorkspaceMembershipLike, 'role' | 'roles'>): string {
  return roleNames(membership).map(roleLabel).join(', ');
}

/**
 * Fold memberships into one entry per workspace, in first-seen order. The first
 * entry keeps its own fields; later ones add their roles, and a member entry
 * makes the person a member there (a customer-only workspace stays customer).
 */
export function oneEntryPerWorkspace<T extends WorkspaceMembershipLike>(memberships: readonly T[]): T[] {
  const byTenant = new Map<number, T>();
  for (const membership of memberships) {
    const seen = byTenant.get(membership.tenant_id);
    if (!seen) {
      byTenant.set(membership.tenant_id, { ...membership, roles: roleNames(membership) });
      continue;
    }
    const merged: T = { ...seen, roles: roleNames({ role: seen.role, roles: [...roleNames(seen), ...roleNames(membership)] }) };
    if (seen.kind === 'customer' && membership.kind !== 'customer') {
      merged.kind = membership.kind ?? 'member';
    }
    if (membership.is_owner === true) {
      merged.is_owner = true;
    }
    byTenant.set(membership.tenant_id, merged);
  }
  return [...byTenant.values()];
}
