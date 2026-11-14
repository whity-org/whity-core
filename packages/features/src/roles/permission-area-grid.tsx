'use client';

/**
 * Injected-translator keys this file renders through `t`, for the i18n catalogue
 * extractor. See the note in role-record-screen.tsx.
 *
 * @i18n-keys admin
 *   permissions.action.approve = Approve
 *   permissions.action.change = Change
 *   permissions.action.create = Create
 *   permissions.action.delete = Delete
 *   permissions.action.see = See
 *   permissions.area.agents = Agents
 *   permissions.area.approvals = Approval gating
 *   permissions.area.audit = Audit log
 *   permissions.area.billing = Billing and plans
 *   permissions.area.connected_ai = Connected AI apps
 *   permissions.area.convening = Meetings and decisions
 *   permissions.area.delegations = Delegations
 *   permissions.area.documents = Documents
 *   permissions.area.forms = Forms
 *   permissions.area.groups = User groups
 *   permissions.area.languages = Languages and translations
 *   permissions.area.licensing = Licensing
 *   permissions.area.messages = Messages
 *   permissions.area.notifications = Notifications
 *   permissions.area.plugins = Plugins
 *   permissions.area.relations = Family relations
 *   permissions.area.roles = Roles
 *   permissions.area.routes = Routes
 *   permissions.area.security = Security
 *   permissions.area.settings = Settings
 *   permissions.area.sign_in = Sign-in
 *   permissions.area.system = System
 *   permissions.area.tags = Tags
 *   permissions.area.time_windows = Time windows
 *   permissions.area.units = Organisational units
 *   permissions.area.users = Users
 *   permissions.area.workspaces = Workspaces
 *   permissions.group.ai = AI
 *   permissions.group.apps = Apps from plugins
 *   permissions.group.documents = Documents
 *   permissions.group.people = People
 *   permissions.group.workspace = Workspace
 *   roles.areaGrid.allowed = allowed
 *   roles.areaGrid.area = Area
 *   roles.areaGrid.more = Also in this area
 *   roles.areaGrid.none = No permissions are defined on this installation.
 *   roles.areaGrid.noMatch = No areas match “{query}”.
 *   roles.areaGrid.notAllowed = not allowed
 *   roles.areaGrid.phone.allowed = Allowed
 *   roles.areaGrid.phone.close = Close
 *   roles.areaGrid.phone.copied = Link copied
 *   roles.areaGrid.phone.copy = Copy link
 *   roles.areaGrid.phone.email = Email me the link
 *   roles.areaGrid.phone.emailSubject = A Whity role to change on a computer
 *   roles.areaGrid.phone.notAllowed = Not allowed
 *   roles.areaGrid.phone.notNow = Not now
 *   roles.areaGrid.phone.note = You can read permissions here. To change them, open this role on a computer.
 *   roles.areaGrid.phone.open = Open on a computer
 *   roles.areaGrid.phone.sheet = Open this role on a computer to change what it can do. Copy the link, or email it to yourself.
 *   roles.areaGrid.onlyAllowed = Only allowed
 *   roles.areaGrid.search = Search areas
 *   roles.areaGrid.summary = {selected} of {total} allowed
 */

import { useMemo, useState } from 'react';
import { Input } from '@whity/ui/input';
import { Switch } from '@whity/ui/switch';
import { IconCheck, IconCircle, IconMinus, IconSearch } from '@tabler/icons-react';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import { OpenOnComputerBar, PhoneReadOnlyNote } from '@whity/ui/open-on-computer';
import { useViewportAtLeast } from '@whity/ui/screen-too-small';
import { groupOf, titleCase } from './permission-groups';
import type { Permission, RolesTranslate } from './types';

const ACTIONS = ['see', 'create', 'change', 'delete', 'approve'] as const;
type Action = (typeof ACTIONS)[number];
const GROUP_ORDER = ['people', 'documents', 'ai', 'workspace', 'apps'];

interface Area {
  key: string;
  label: string;
  group: string;
  all: Permission[];
  cells: Partial<Record<Action, Permission[]>>;
  /** Permissions no grid column describes. Listed under the area so none is unreachable. */
  other: Permission[];
}

function buildAreas(permissions: Permission[], t: RolesTranslate): Area[] {
  const areas = new Map<string, Area>();
  for (const permission of permissions) {
    const key = permission.area ?? groupOf(permission.name ?? '');
    let area = areas.get(key);
    if (area === undefined) {
      area = {
        key,
        // The fallback is the server's English, or the plugin's own name.
        label: t(`permissions.area.${key}`, permission.areaLabel ?? titleCase(key)),
        group: permission.areaGroup ?? 'apps',
        all: [],
        cells: {},
        other: [],
      };
      areas.set(key, area);
    }
    area.all.push(permission);
    const actions = (permission.actions ?? []) as Action[];
    if (actions.length === 0) area.other.push(permission);
    for (const action of actions) {
      (area.cells[action] ??= []).push(permission);
    }
  }
  return [...areas.values()].sort((a, b) => a.label.localeCompare(b.label));
}

interface PermissionAreaGridProps {
  permissions: Permission[];
  selectedIds: number[];
  /** The ids as last saved, so a changed row can be marked (P.3). */
  savedIds?: number[];
  onChange: (ids: number[]) => void;
  t: RolesTranslate;
  readOnly?: boolean;
}

/**
 * The role page's Access tab (design 2.9, A12): areas by See, Create, Change, Delete
 * and Approve. A cell can hold several permissions (the approvals area has three
 * kinds of Approve), and one permission can sit in two cells (`users:write` is what
 * both creating and changing need), so ticking a cell sets everything in it. Codes
 * appear only with Show technical details on (A.3). Read-only is a state, not a
 * disabled form: marks instead of inputs.
 */
export function PermissionAreaGrid({
  permissions,
  selectedIds,
  savedIds,
  onChange,
  t,
  readOnly = false,
}: PermissionAreaGridProps) {
  const [query, setQuery] = useState('');
  const [onlyAllowed, setOnlyAllowed] = useState(false);
  // Under 640px the grid is a list per area, read-only (PwBuilders): five
  // columns of ticks do not fit a phone, and the owner answered that reading
  // is enough there. Not measured yet counts as wide, so nothing is gated.
  const phone = useViewportAtLeast(640) === false;

  const selected = useMemo(() => new Set(selectedIds), [selectedIds]);
  const saved = useMemo(() => new Set(savedIds ?? selectedIds), [savedIds, selectedIds]);
  const areas = useMemo(() => buildAreas(permissions, t), [permissions, t]);

  const needle = query.trim().toLowerCase();
  const visible = areas.filter((area) => {
    if (onlyAllowed && !area.all.some((p) => selected.has(p.id))) return false;
    if (needle === '') return true;
    return (
      area.label.toLowerCase().includes(needle) ||
      area.all.some(
        (p) => (p.description ?? '').toLowerCase().includes(needle) || (p.name ?? '').toLowerCase().includes(needle)
      )
    );
  });

  const setAll = (cell: Permission[], on: boolean) => {
    const ids = new Set(cell.map((p) => p.id));
    onChange(on ? [...new Set([...selectedIds, ...ids])] : selectedIds.filter((id) => !ids.has(id)));
  };

  if (permissions.length === 0) {
    return (
      <p className="py-6 text-center text-sm text-muted-foreground" data-testid="perm-grid-empty">
        {t('roles.areaGrid.none', 'No permissions are defined on this installation.')}
      </p>
    );
  }

  /** A read-only cell: what the role holds, in a mark and in words for a screen reader. */
  const mark = (label: string, held: boolean) => (
    <span
      role="img"
      aria-label={`${label}: ${held ? t('roles.areaGrid.allowed', 'allowed') : t('roles.areaGrid.notAllowed', 'not allowed')}`}
      className="inline-flex"
    >
      {held ? (
        <IconCheck size={16} className="text-primary" aria-hidden="true" />
      ) : (
        <IconCircle size={16} className="text-muted-foreground/50" aria-hidden="true" />
      )}
    </span>
  );

  const actionLabel = (action: Action): string =>
    t(`permissions.action.${action}`, action.charAt(0).toUpperCase() + action.slice(1));

  const groups = GROUP_ORDER.map((group) => ({ group, areas: visible.filter((area) => area.group === group) })).filter(
    (entry) => entry.areas.length > 0
  );

  const phoneAreas = (rows: Area[]) =>
    rows.map((area) => {
      const rowsOf: Array<{ key: string; label: string; held: boolean }> = [
        ...ACTIONS.filter((action) => area.cells[action] !== undefined).map((action) => ({
          key: action,
          label: actionLabel(action),
          held: (area.cells[action] ?? []).some((p) => selected.has(p.id)),
        })),
        ...area.other.map((p) => ({
          key: `other-${p.id}`,
          label: p.description || p.name || '',
          held: selected.has(p.id),
        })),
      ];
      return (
        <section key={area.key} data-testid={`perm-list-area-${area.key}`} aria-label={area.label}>
          <h3 className="px-1 pb-2 text-sm font-medium text-muted-foreground">{area.label}</h3>
          <TechnicalDetails>
            <span className="block px-1 pb-2 font-mono text-xs text-muted-foreground">
              {area.all.map((p) => p.name).join(', ')}
            </span>
          </TechnicalDetails>
          <ul className="divide-y divide-border rounded-card border border-border bg-card">
            {rowsOf.map((row) => (
              <li key={row.key} className="flex min-h-12 items-center justify-between gap-3 px-4 py-2 text-base text-foreground">
                <span className="min-w-0">{row.label}</span>
                <span
                  className={
                    row.held
                      ? 'flex shrink-0 items-center gap-1.5 text-sm text-primary'
                      : 'flex shrink-0 items-center gap-1.5 text-sm text-muted-foreground'
                  }
                >
                  {row.held ? (
                    <IconCheck size={18} aria-hidden="true" />
                  ) : (
                    <IconMinus size={18} aria-hidden="true" />
                  )}
                  {row.held
                    ? t('roles.areaGrid.phone.allowed', 'Allowed')
                    : t('roles.areaGrid.phone.notAllowed', 'Not allowed')}
                </span>
              </li>
            ))}
          </ul>
        </section>
      );
    });

  return (
    <div className="space-y-4" data-testid="perm-grid">
      {phone && (
        <PhoneReadOnlyNote>
          {t('roles.areaGrid.phone.note', 'You can read permissions here. To change them, open this role on a computer.')}
        </PhoneReadOnlyNote>
      )}
      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-48 flex-1">
          <IconSearch
            size={16}
            aria-hidden="true"
            className="pointer-events-none absolute inset-s-3 top-1/2 -translate-y-1/2 text-muted-foreground"
          />
          <Input
            data-testid="perm-grid-search"
            aria-label={t('roles.areaGrid.search', 'Search areas')}
            placeholder={t('roles.areaGrid.search', 'Search areas')}
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            className="ps-9"
          />
        </div>
        <label className="flex items-center gap-2 text-sm text-foreground">
          <Switch checked={onlyAllowed} onCheckedChange={setOnlyAllowed} data-testid="perm-grid-only-allowed" />
          {t('roles.areaGrid.onlyAllowed', 'Only allowed')}
        </label>
        <span className="text-sm text-muted-foreground" data-testid="perm-grid-summary">
          {t('roles.areaGrid.summary', '{selected} of {total} allowed', {
            selected: selected.size,
            total: permissions.length,
          })}
        </span>
      </div>

      {groups.length === 0 ? (
        <p className="py-6 text-center text-sm text-muted-foreground" data-testid="perm-grid-no-match">
          {t('roles.areaGrid.noMatch', 'No areas match “{query}”.', { query: query.trim() })}
        </p>
      ) : phone ? (
        <div className="space-y-5" data-testid="perm-list">
          {groups.map(({ group, areas: rows }) => (
            <div key={group} className="space-y-5">
              {phoneAreas(rows)}
            </div>
          ))}
        </div>
      ) : (
        groups.map(({ group, areas: rows }) => (
          <section key={group} className="overflow-x-auto rounded-card border border-border" data-testid={`perm-grid-group-${group}`}>
            <table className="w-full text-sm">
              <caption className="border-b border-border bg-muted/40 px-4 py-2 text-start font-semibold text-foreground">
                {t(`permissions.group.${group}`, titleCase(group))}
              </caption>
              <thead>
                <tr className="border-b border-border text-muted-foreground">
                  <th scope="col" className="px-4 py-2 text-start font-medium">
                    {t('roles.areaGrid.area', 'Area')}
                  </th>
                  {ACTIONS.map((action) => (
                    <th key={action} scope="col" className="w-20 px-2 py-2 text-center font-medium">
                      {actionLabel(action)}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-border/60">
                {rows.map((area) => {
                  const changed = area.all.some((p) => selected.has(p.id) !== saved.has(p.id));
                  return (
                    <tr key={area.key} data-testid={`perm-grid-area-${area.key}`} className={changed ? 'border-s-2 border-s-primary' : undefined}>
                      <td className="px-4 py-3 align-top text-foreground">
                        <span className="font-medium">{area.label}</span>
                        {area.other.length > 0 && (
                          <ul className="mt-2 space-y-1" aria-label={t('roles.areaGrid.more', 'Also in this area')}>
                            {area.other.map((permission) => (
                              <li key={permission.id} data-permission={permission.name}>
                                {readOnly ? (
                                  <span className="flex items-start gap-2">
                                    {mark(permission.description || permission.name, selected.has(permission.id))}
                                    <span className="min-w-0 text-muted-foreground">
                                      {permission.description || permission.name}
                                    </span>
                                  </span>
                                ) : (
                                  <label className="flex cursor-pointer items-start gap-2">
                                    <input
                                      type="checkbox"
                                      checked={selected.has(permission.id)}
                                      onChange={(event) => setAll([permission], event.target.checked)}
                                      className="mt-0.5 size-4 rounded border-border text-primary"
                                    />
                                    <span className="min-w-0 text-muted-foreground">
                                      {permission.description || permission.name}
                                    </span>
                                  </label>
                                )}
                              </li>
                            ))}
                          </ul>
                        )}
                        <TechnicalDetails>
                          <span className="mt-1 block font-mono text-xs text-muted-foreground">
                            {area.all.map((p) => p.name).join(', ')}
                          </span>
                        </TechnicalDetails>
                      </td>
                      {ACTIONS.map((action) => {
                        const cell = area.cells[action];
                        if (cell === undefined) {
                          return (
                            <td key={action} className="px-2 py-3 text-center align-top text-muted-foreground">
                              <span aria-hidden="true">–</span>
                            </td>
                          );
                        }
                        const held = cell.filter((p) => selected.has(p.id)).length;
                        const all = held === cell.length;
                        return (
                          <td key={action} className="px-2 py-3 text-center align-top" data-permission={cell.map((p) => p.name).join(' ')}>
                            {readOnly ? (
                              mark(`${area.label}: ${actionLabel(action)}`, held > 0)
                            ) : (
                              <input
                                type="checkbox"
                                aria-label={`${area.label}: ${actionLabel(action)}`}
                                title={cell.map((p) => p.description || p.name).join('; ')}
                                checked={all}
                                ref={(element) => {
                                  if (element) element.indeterminate = held > 0 && !all;
                                }}
                                onChange={() => setAll(cell, !all)}
                                className="size-4 rounded border-border text-primary"
                              />
                            )}
                          </td>
                        );
                      })}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </section>
        ))
      )}
      {phone && (
        <OpenOnComputerBar
          labels={{
            open: t('roles.areaGrid.phone.open', 'Open on a computer'),
            description: t(
              'roles.areaGrid.phone.sheet',
              'Open this role on a computer to change what it can do. Copy the link, or email it to yourself.'
            ),
            copy: t('roles.areaGrid.phone.copy', 'Copy link'),
            copied: t('roles.areaGrid.phone.copied', 'Link copied'),
            email: t('roles.areaGrid.phone.email', 'Email me the link'),
            notNow: t('roles.areaGrid.phone.notNow', 'Not now'),
            close: t('roles.areaGrid.phone.close', 'Close'),
            emailSubject: t('roles.areaGrid.phone.emailSubject', 'A Whity role to change on a computer'),
          }}
        />
      )}
    </div>
  );
}
