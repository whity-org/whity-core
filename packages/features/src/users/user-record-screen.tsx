'use client';

/**
 * Injected-translator keys this file renders through `t`. Declared here for the
 * i18n catalogue extractor: it cannot infer a domain from a prop-injected
 * translator (see UsersTranslate — deliberately NOT typed `TranslateFn`, so
 * these files stay unscanned the way the roles slice does), so the keys are
 * enumerated below instead. All of this screen's copy resolves in the `admin`
 * domain.
 *
 * @i18n-keys admin
 *   users.record.activity.empty = Nothing has been recorded against this person yet.
 *   users.record.activity.error = Failed to load this person's history
 *   users.record.activity.heldSince = Held since {date}
 *   users.record.activity.ou = in unit {id}
 *   users.record.activity.role = role {role}
 *   users.record.back = Back to users
 *   users.record.badge.deactivated = Deactivated
 *   users.record.badge.deactivated.hint = The account is switched off globally, so this person cannot sign in to any tenant.
 *   users.record.badge.invited = Invited
 *   users.record.badge.suspended = Suspended
 *   users.record.details.subtitle = Who this person is here, and what they may do.
 *   users.record.details.title = Details
 *   users.record.email.hint = The email is this person's identity across every tenant and cannot be changed here.
 *   users.record.email.label = Email
 *   users.record.error.load = Failed to load this person
 *   users.record.error.notFound = This person is no longer in your tenant.
 *   users.record.error.save = Failed to save this person
 *   users.record.error.title = This person could not be loaded
 *   users.record.loading = Loading person…
 *   users.record.memberships.empty = This person belongs to no workspace.
 *   users.record.memberships.error = Failed to load this person's workspaces and roles
 *   users.record.memberships.primary = Primary
 *   users.record.name.hint = The name people see for this person, in every workspace they belong to.
 *   users.record.changed.name = name
 *   users.record.fact.lastActive = Active {date}
 *   users.record.fact.neverActive = Not active yet
 *   users.record.twoStep.label = Two-step
 *   users.record.twoStep.on = On
 *   users.record.twoStep.off = Off
 *   users.record.twoStep.offRequired = Off, required
 *   users.record.twoStep.require = Require two-step
 *   users.record.twoStep.requireStop = Stop requiring two-step
 *   users.record.twoStep.required = Two-step is now required of {name}.
 *   users.record.twoStep.notRequired = {name} no longer has a requirement of their own.
 *   users.record.twoStep.error = Two-step could not be changed.
 *   users.record.twoStep.confirm.title = Require two-step of {name}?
 *   users.record.twoStep.confirm.description = If they do not have it on, they have to set it up the next time they sign in.
 *   users.record.twoStep.confirm.cancel = Cancel
 *   users.record.name.label = Name
 *   users.record.ou.error = Failed to load the organisational units
 *   users.record.ou.incomplete = The organisational units could not be listed in full, so the picker is withheld — a short list would hide units that exist.
 *   users.record.ou.label = Organisational unit
 *   users.record.ou.none = None (root)
 *   users.record.ou.placeholder = Select an organisational unit
 *   users.record.ou.unknown = Unit #{id}
 *   users.record.password.action = Send reset link
 *   users.record.password.error = Failed to send the password-reset link
 *   users.record.password.sending = Sending…
 *   users.record.password.success = A password-reset link has been sent to {email}
 *   users.record.readOnly.noPermission = You don't have permission to edit users, so this record is read-only.
 *   users.record.role.error = Failed to load the roles that can be assigned
 *   users.record.role.label = Role
 *   users.record.role.loading = Loading roles…
 *   users.record.role.placeholder = Select a role
 *   users.record.save = Save changes
 *   users.record.save.success = User updated successfully
 *   users.record.subtitle = User record
 *   users.record.activity.subtitle = Everything that changed about this person, newest first.
 *   users.record.activity.title = What changed
 *   users.record.changed.ou = unit
 *   users.record.changed.role = role
 *   users.record.changed.summary = Changed: {fields}
 *   users.record.deactivate = Deactivate
 *   users.record.deactivated = {name} was deactivated
 *   users.record.delete = Delete
 *   users.record.delete.cancel = Cancel
 *   users.record.delete.confirm = Delete person
 *   users.record.delete.description = They lose access to this workspace and their role here is removed. This cannot be undone.
 *   users.record.delete.error = Failed to delete this person
 *   users.record.delete.success = {name} was deleted
 *   users.record.delete.title = Delete {name}?
 *   users.record.discard = Discard
 *   users.record.fact.noUnit = No unit
 *   users.record.history.by = by {id}
 *   users.record.history.created = Added to the workspace
 *   users.record.history.deleted = Removed from the workspace
 *   users.record.history.other = Something changed
 *   users.record.history.roleAdded = Given the role {role}
 *   users.record.history.roleRemoved = Role {role} removed
 *   users.record.history.updated = Details changed
 *   users.record.memberships.subtitle = Every workspace this person belongs to, and the role they hold in each.
 *   users.record.memberships.title = Workspaces and roles
 *   users.record.menu.label = More actions
 *   users.record.reactivate = Reactivate
 *   users.record.reactivated = {name} was reactivated
 *   users.record.status.error = Failed to update this person
 *   users.record.tab.access = Access
 *   users.record.tab.history = History
 *   users.record.tab.overview = Overview
 *   users.record.undo = Undo
 *   users.record.was = Was {value}
 */

import { useCallback, useEffect, useMemo, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import { Input } from '@whity/ui/input';
import { SaveBar, ChangedField } from '@whity/ui/save-bar';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@whity/ui/dropdown-menu';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@whity/ui/select';
import { IconMenu2 } from '@tabler/icons-react';

import {
  RecordCollectionPanel,
  RecordList,
  RecordListItem,
  RecordPageError,
  RecordPageShell,
  RecordPageSkeleton,
  RecordTimeline,
  RecordTimelineItem,
  resolveAccess,
  useRecordResource,
} from '../record';
import { useDateDisplay } from '../datetime';
import type { DateDisplay } from '../datetime';
import type { RecordBadge, RecordFactsFn } from '../record';
import { identityTranslate } from '../nav/types';
import { SECURITY_MANAGE, USERS_DELETE, USERS_WRITE } from './capabilities';
import type {
  OuOptionsResult,
  UserActivityEntry,
  UserRecord,
  UserRecordScreenProps,
  UsersTranslate,
} from './types';

/** How many history entries the record page asks for. */
const ACTIVITY_PAGE_SIZE = 12;

/** The Select's own value for "no organisational unit" — `''` is not selectable. */
const NO_OU = '__none__';

/**
 * What the SERVER says this person is.
 *
 * Two things to notice, both of them #895's lesson at different scales.
 *
 * FIRST: there is no `manageable`, no `canEdit`, no permission flag of any kind.
 * The shell's `RecordFields` check rejects those names outright, so the page
 * physically cannot label a person by what the CALLER may do to them. Whether
 * this record is editable lives in `resolveAccess` below.
 *
 * SECOND, and the same mistake one level down: `accountStatus` and
 * `membershipStatus` are two fields, not one "status". The first is the global
 * account switch and the second is this tenant's membership lifecycle. Reading
 * either as "active" says nothing about the other — an operator looking at an
 * `invited` membership on a globally deactivated profile needs both sentences,
 * and a page that picked one would be confidently wrong about the other.
 */
interface UserRecordFields {
  name: string;
  email: string;
  /** The PRIMARY role held in this tenant. */
  role: string;
  ouId: number | null;
  /** Resolved from `/api/ous`; null when that list is unavailable or incomplete. */
  ouName: string | null;
  createdAt: string | null;
  /** `profiles.status` — the global account switch. */
  accountStatus: string;
  /** `memberships.status` in this tenant. */
  membershipStatus: string;
  /** How many memberships this person holds; null while that request is in flight. */
  membershipCount: number | null;
  /** The formatted last-active date. Empty when the workspace hides dates. */
  lastActive: string;
}

/**
 * What the page SAYS about a person — a pure projection, at module scope, of the
 * record and the dictionary. There is no `can` in scope for it to reach for, and
 * the fields type it receives could not carry one if there were.
 */
const userFacts: RecordFactsFn<UserRecordFields> = (user, t) => {
  const badges: RecordBadge[] = [];
  if (user.accountStatus === 'inactive') {
    badges.push({
      key: 'deactivated',
      label: t('users.record.badge.deactivated', 'Deactivated'),
      tone: 'danger' as const,
      title: t(
        'users.record.badge.deactivated.hint',
        'The account is switched off globally, so this person cannot sign in to any tenant.'
      ),
    });
  }
  if (user.membershipStatus === 'invited') {
    badges.push({
      key: 'invited',
      label: t('users.record.badge.invited', 'Invited'),
      tone: 'warning' as const,
    });
  }
  if (user.membershipStatus === 'suspended') {
    badges.push({
      key: 'suspended',
      label: t('users.record.badge.suspended', 'Suspended'),
      tone: 'warning' as const,
    });
  }

  return {
    title: user.name,
    subtitle: [
      user.role,
      user.ouId === null
        ? t('users.record.fact.noUnit', 'No unit')
        : (user.ouName ?? ''),
      user.email,
      user.lastActive,
    ]
      .filter((part) => part !== '')
      .join(' · '),
    badges,
  };
};

/** A metadata value read defensively — the wire type is `unknown` by construction. */
function metaString(metadata: Record<string, unknown>, key: string): string | null {
  const value = metadata[key];
  if (typeof value === 'string' && value !== '') return value;
  if (typeof value === 'number') return String(value);
  return null;
}

/** For a removed role: how long it was held, which no other record keeps. */
function heldSince(entry: UserActivityEntry, t: UsersTranslate, dates: DateDisplay): string | undefined {
  if (entry.action !== 'user.membership.removed') return undefined;
  // Dropped, not emptied, when the workspace hides dates (#1068).
  const date = dates.date(metaString(entry.metadata, 'granted_at'));
  return date === null ? undefined : t('users.record.activity.heldSince', 'Held since {date}', { date });
}

/** What an audit entry says in words; the code behind it is for Show technical details. */
function historySentence(entry: UserActivityEntry, t: UsersTranslate): string {
  const role = metaString(entry.metadata, 'role_name') ?? '';
  switch (entry.action) {
    case 'user.created':
    case 'user.created.async':
      return t('users.record.history.created', 'Added to the workspace');
    case 'user.updated':
      return t('users.record.history.updated', 'Details changed');
    case 'user.deleted':
      return t('users.record.history.deleted', 'Removed from the workspace');
    case 'user.membership.added':
      return t('users.record.history.roleAdded', 'Given the role {role}', { role });
    case 'user.membership.removed':
      return t('users.record.history.roleRemoved', 'Role {role} removed', { role });
    default:
      return t('users.record.history.other', 'Something changed');
  }
}

/** A field the API does not accept a change to: shown, explained, never faked. */
function StaticField({ label, value, hint }: { label: string; value: ReactNode; hint?: string }) {
  return (
    <div className="space-y-1.5">
      <span className="block text-sm font-medium text-foreground">{label}</span>
      <p className="text-sm text-foreground">{value}</p>
      {hint !== undefined && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}

/**
 * The user RECORD PAGE (#882) — the record-page shell's SECOND consumer, and the
 * screen that proves it is a shell rather than the roles page with a different
 * name.
 *
 * WHY USERS. It is the most-used record on the platform, and since #889/#890 it
 * is the only one that can show a person's COMPLETE authority history: membership
 * grants and revocations are audited against the USER, so
 * `target_type=user&target_id=N` returns every change to what this person may do
 * in one query — including, for a revocation, what was removed and how long they
 * held it. That trail existed and nothing read it.
 *
 * WHAT IT DOES NOT DO, on purpose. Granting and revoking a membership still
 * happens in the memberships MODAL on the list page, which is untouched and still
 * works. This page is additive exactly the way #885 was: it adds an address for a
 * person and the context a dialog cannot carry, and the modals are retired per
 * screen by #884's audit rather than by this change.
 *
 * Presentational and data-source-agnostic like every screen in this package: data
 * through `adapter`, capabilities through `can`, copy through `t`, navigation
 * through `onBack`. RTL-safe throughout — every inset is logical and no branch
 * here reads the direction.
 */
export function UserRecordScreen({
  adapter,
  userId,
  can,
  t: injectedT,
  onNotify,
  onBack,
  className,
}: UserRecordScreenProps) {
  const t: UsersTranslate = injectedT ?? identityTranslate;
  // #1068: the only sanctioned way to put a date on a screen.
  const dates = useDateDisplay();

  const loaded = useRecordResource<UserRecord>(
    () => adapter.getUser(userId),
    [userId],
    t('users.record.error.load', 'Failed to load this person')
  );

  const memberships = useRecordResource(
    () => adapter.listUserMemberships(userId),
    [userId],
    t('users.record.memberships.error', "Failed to load this person's tenants and roles")
  );

  // `audit:read` is a SEPARATE permission from user administration, so the
  // adapter's `'forbidden'` sentinel becomes an ABSENT panel — clean absence,
  // not an error box about a capability the operator withheld deliberately.
  const activity = useRecordResource(
    () => adapter.getUserActivity(userId, ACTIVITY_PAGE_SIZE),
    [userId],
    t('users.record.activity.error', "Failed to load this person's history")
  );

  // The picker sources. Both are page-independent, so they load once rather than
  // per record — and their failure withholds a PICKER, never the page.
  const roleNames = useRecordResource<string[]>(
    () => adapter.listRoleNames(),
    [],
    t('users.record.role.error', 'Failed to load the roles that can be assigned')
  );
  const ous = useRecordResource<OuOptionsResult>(
    () => adapter.listOus(),
    [],
    t('users.record.ou.error', 'Failed to load the organisational units')
  );

  // The draft, and the record as last SAVED. Both carry the id they belong to.
  //
  // A record page is addressable, so `userId` can change under a mounted
  // component. State seeded for the previous person is stale the instant that
  // happens, and the effect that re-seeds it runs a render LATER — so an id-less
  // draft would paint one person's role into another person's form for a frame.
  // Stamping the id and ignoring a mismatch makes that frame the skeleton.
  const [saved, setSaved] = useState<UserRecord | null>(null);
  const [draft, setDraft] = useState<{ id: number; role: string; ouId: number | null; displayName: string } | null>(
    null
  );
  const [confirmingTwoStep, setConfirmingTwoStep] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [isSendingReset, setIsSendingReset] = useState(false);
  const [confirmingDelete, setConfirmingDelete] = useState(false);

  const record = loaded.status === 'ready' ? loaded.value : null;
  const current = saved !== null && record !== null && saved.id === record.id ? saved : record;

  const resetDraft = useCallback((source: UserRecord) => {
    setDraft({ id: source.id, role: source.role ?? '', ouId: source.ouId, displayName: source.displayName });
  }, []);

  useEffect(() => {
    if (record !== null) {
      resetDraft(record);
      setSaved(record);
    }
  }, [record, resetDraft]);

  const form = draft !== null && current !== null && draft.id === current.id ? draft : null;

  // A complete OU list, or none at all. A SHORT list is indistinguishable from a
  // correct one, and acting on it writes the wrong unit onto a real person — the
  // same rule the users modals follow, and the reason `complete` travels with
  // the options rather than being inferred from their length.
  const ouOptions = ous.status === 'ready' && ous.value.complete ? ous.value.options : null;
  const ouName = useMemo(() => {
    if (current?.ouId == null || ouOptions === null) return null;
    return ouOptions.find((ou) => ou.id === current.ouId)?.name ?? null;
  }, [current?.ouId, ouOptions]);

  const membershipCount = memberships.status === 'ready' ? memberships.value.length : null;

  // ONE gate, and it is the caller's capability — not a property of the person.
  // `users:write` is an EXISTING grant (migration 022); nothing here invents a
  // permission, which would reach only `admin` and silently strip the capability
  // from operators on custom administrative roles (#834).
  const access = resolveAccess([
    {
      allowed: can(USERS_WRITE),
      reason: t(
        'users.record.readOnly.noPermission',
        "You don't have permission to edit users, so this record is read-only."
      ),
    },
  ]);

  const isDirty =
    current !== null &&
    form !== null &&
    (form.role !== (current.role ?? '') ||
      form.ouId !== current.ouId ||
      form.displayName.trim() !== current.displayName);

  const back = { label: t('users.record.back', 'Back to users'), onBack };

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (!current || !form || !access.editable) return;

    try {
      setIsSaving(true);
      const result = await adapter.updateUser(current.id, {
        role: form.role,
        ouId: form.ouId,
        ...(form.displayName.trim() !== current.displayName ? { displayName: form.displayName.trim() } : {}),
      });
      if (result === 'not-found') {
        onNotify?.(
          t('users.record.error.notFound', 'This person is no longer in your tenant.'),
          'error'
        );
        return;
      }
      onNotify?.(t('users.record.save.success', 'User updated successfully'), 'success');
      // Re-seat the record on the saved values so the page stops reading dirty
      // without a refetch nobody asked for — and so the stat strip states what
      // was actually persisted.
      const displayName = form.displayName.trim();
      const next: UserRecord = {
        ...current,
        role: form.role,
        ouId: form.ouId,
        displayName,
        name: displayName !== '' ? displayName : current.name,
      };
      setSaved(next);
      resetDraft(next);
    } catch (error) {
      onNotify?.(
        error instanceof Error && error.message
          ? error.message
          : t('users.record.error.save', 'Failed to save this person'),
        'error'
      );
    } finally {
      setIsSaving(false);
    }
  };

  const changeTwoStep = async (required: boolean) => {
    if (!current || adapter.setTwoStepRequirement === undefined) return;
    try {
      await adapter.setTwoStepRequirement(current.id, current.tenantId, required);
      onNotify?.(
        required
          ? t('users.record.twoStep.required', 'Two-step is now required of {name}.', { name: current.name })
          : t('users.record.twoStep.notRequired', '{name} no longer has a requirement of their own.', { name: current.name }),
        'success'
      );
      setSaved({
        ...current,
        twoStep: {
          ...current.twoStep,
          requiredDirectly: required,
          // A workspace or unit policy may still apply, which only a reload can tell.
          required: required || current.twoStep.required,
        },
      });
    } catch (error) {
      onNotify?.(
        error instanceof Error && error.message
          ? error.message
          : t('users.record.twoStep.error', 'Two-step could not be changed.'),
        'error'
      );
    }
  };

  // Deactivating can be undone, so it happens at once and the toast offers Undo
  // (tier 1). The Undo is the opposite change, offered once.
  const changeStatus = async (next: 'active' | 'inactive', offerUndo: boolean) => {
    if (!current || adapter.setAccountStatus === undefined) return;
    try {
      await adapter.setAccountStatus(current.id, next);
      const message =
        next === 'inactive'
          ? t('users.record.deactivated', '{name} was deactivated', { name: current.name })
          : t('users.record.reactivated', '{name} was reactivated', { name: current.name });
      const previous = current;
      const updated: UserRecord = { ...current, accountStatus: next };
      setSaved(updated);
      onNotify?.(
        message,
        'success',
        offerUndo
          ? {
              label: t('users.record.undo', 'Undo'),
              onClick: () => {
                setSaved({ ...previous });
                void adapter.setAccountStatus?.(previous.id, previous.accountStatus as 'active' | 'inactive');
              },
            }
          : undefined
      );
    } catch (error) {
      onNotify?.(
        error instanceof Error && error.message
          ? error.message
          : t('users.record.status.error', 'Failed to update this person'),
        'error'
      );
    }
  };

  const handleDelete = async () => {
    if (!current || adapter.deleteUser === undefined) return;
    try {
      await adapter.deleteUser(current.id);
      onNotify?.(t('users.record.delete.success', '{name} was deleted', { name: current.name }), 'success');
      onBack();
    } catch (error) {
      onNotify?.(
        error instanceof Error && error.message
          ? error.message
          : t('users.record.delete.error', 'Failed to delete this person'),
        'error'
      );
    }
  };

  const handleSendResetLink = async () => {
    if (!current) return;
    try {
      setIsSendingReset(true);
      await adapter.sendPasswordResetLink(current.id);
      onNotify?.(
        t('users.record.password.success', 'A password-reset link has been sent to {email}', {
          email: current.email,
        }),
        'success'
      );
    } catch (error) {
      onNotify?.(
        error instanceof Error && error.message
          ? error.message
          : t('users.record.password.error', 'Failed to send the password-reset link'),
        'error'
      );
    } finally {
      setIsSendingReset(false);
    }
  };

  if (loaded.status === 'error') {
    return (
      <RecordPageError
        testId="user-record-error"
        title={t('users.record.error.title', 'This person could not be loaded')}
        description={loaded.detail ?? t('users.record.error.load', 'Failed to load this person')}
        back={back}
        className={className}
      />
    );
  }

  if (loaded.status !== 'ready' || current === null || form === null) {
    return (
      <RecordPageSkeleton
        testId="user-record-loading"
        back={back}
        label={t('users.record.loading', 'Loading person…')}
        className={className}
      />
    );
  }

  const fields: UserRecordFields = {
    name: current.name,
    email: current.email,
    role: current.role,
    ouId: current.ouId,
    ouName,
    createdAt: current.createdAt,
    accountStatus: current.accountStatus,
    membershipStatus: current.status,
    membershipCount,
    lastActive: dates.hidden
      ? ''
      : current.lastActiveAt !== null
        ? t('users.record.fact.lastActive', 'Active {date}', { date: dates.date(current.lastActiveAt) ?? '' })
        : t('users.record.fact.neverActive', 'Not active yet'),
  };

  const twoStepLabel = current.twoStep.enabled
    ? t('users.record.twoStep.on', 'On')
    : current.twoStep.required
      ? t('users.record.twoStep.offRequired', 'Off, required')
      : t('users.record.twoStep.off', 'Off');

  const primaryMembership =
    memberships.status === 'ready'
      ? (memberships.value.find((m) => m.isPrimary) ?? memberships.value[0] ?? null)
      : null;

  const ouLabelFor = (id: number | null): string =>
    id === null
      ? t('users.record.ou.none', 'None (root)')
      : (ouOptions?.find((ou) => ou.id === id)?.name ??
        t('users.record.ou.unknown', 'Unit #{id}', { id }));

  const detailsCard = (children: ReactNode) => (
    <Card>
      <CardHeader>
        <CardTitle>{t('users.record.details.title', 'Details')}</CardTitle>
        <CardDescription>
          {t('users.record.details.subtitle', 'Who this person is here, and what they may do.')}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">{children}</CardContent>
    </Card>
  );

  const identityFields = (
    <>
      {/* Not a disabled input. `email` is this person's identity across every
          tenant, so it is STATED with the reason — a greyed-out box invites the
          reader to look for the permission that would ungrey it. */}
      <StaticField
        label={t('users.record.email.label', 'Email')}
        value={current.email}
        hint={t(
          'users.record.email.hint',
          "The email is this person's identity across every tenant and cannot be changed here."
        )}
      />
      <ChangedField
        changed={form.displayName.trim() !== current.displayName}
        wasLabel={t('users.record.was', 'Was {value}', { value: current.displayName })}
        className="space-y-1.5"
      >
        <label htmlFor="user-record-name" className="block text-sm font-medium text-foreground">
          {t('users.record.name.label', 'Name')}
        </label>
        <Input
          id="user-record-name"
          data-testid="user-record-name"
          value={form.displayName}
          placeholder={current.name}
          maxLength={255}
          onChange={(event) => setDraft({ ...form, displayName: event.target.value })}
        />
        <p className="text-sm text-muted-foreground">
          {t('users.record.name.hint', 'The name people see for this person, in every workspace they belong to.')}
        </p>
      </ChangedField>
    </>
  );

  const editor = (
    <div className="space-y-6">
      <form id="user-record-form" onSubmit={handleSubmit}>
        {detailsCard(
          <>
            {identityFields}

            <ChangedField
              changed={form.role !== (current.role ?? '')}
              wasLabel={t('users.record.was', 'Was {value}', { value: current.role ?? '' })}
              className="space-y-1.5"
            >
              <label
                htmlFor="user-record-role"
                className="block text-sm font-medium text-foreground"
              >
                {t('users.record.role.label', 'Role')}
              </label>
              <Select
                value={form.role}
                onValueChange={(role) => setDraft({ ...form, role })}
              >
                <SelectTrigger id="user-record-role" data-testid="user-record-role">
                  <SelectValue
                    placeholder={
                      roleNames.status === 'loading'
                        ? t('users.record.role.loading', 'Loading roles…')
                        : t('users.record.role.placeholder', 'Select a role')
                    }
                  />
                </SelectTrigger>
                <SelectContent>
                  {(roleNames.status === 'ready' ? roleNames.value : []).map((name) => (
                    <SelectItem key={name} value={name}>
                      {name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </ChangedField>

            <ChangedField
              changed={form.ouId !== current.ouId}
              wasLabel={t('users.record.was', 'Was {value}', { value: ouLabelFor(current.ouId) })}
              className="space-y-1.5"
            >
              <label htmlFor="user-record-ou" className="block text-sm font-medium text-foreground">
                {t('users.record.ou.label', 'Organisational unit')}
              </label>
              {ouOptions === null ? (
                // Withheld, not offered short — and the current unit is stated so
                // the operator still knows where this person is.
                <>
                  <p className="text-sm text-foreground">{ouLabelFor(form.ouId)}</p>
                  <p className="text-xs text-muted-foreground" data-testid="user-record-ou-withheld">
                    {t(
                      'users.record.ou.incomplete',
                      'The organisational units could not be listed in full, so the picker is withheld — a short list would hide units that exist.'
                    )}
                  </p>
                </>
              ) : (
                <Select
                  value={form.ouId === null ? NO_OU : String(form.ouId)}
                  onValueChange={(value) =>
                    setDraft({ ...form, ouId: value === NO_OU ? null : Number(value) })
                  }
                >
                  <SelectTrigger id="user-record-ou" data-testid="user-record-ou">
                    <SelectValue
                      placeholder={t('users.record.ou.placeholder', 'Select an organisational unit')}
                    />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NO_OU}>{t('users.record.ou.none', 'None (root)')}</SelectItem>
                    {ouOptions.map((ou) => (
                      <SelectItem key={ou.id} value={String(ou.id)}>
                        {ou.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </ChangedField>
          </>
        )}
      </form>
    </div>
  );

  const readOnly = (
    <div className="space-y-6">
      {detailsCard(
        <dl className="space-y-4">
          <div className="space-y-1.5">
            <dt className="text-sm font-medium text-foreground">
              {t('users.record.email.label', 'Email')}
            </dt>
            <dd className="text-sm text-foreground">{current.email}</dd>
          </div>
          <div className="space-y-1.5">
            <dt className="text-sm font-medium text-foreground">
              {t('users.record.name.label', 'Name')}
            </dt>
            <dd className="text-sm text-foreground">{current.name}</dd>
          </div>
          <div className="space-y-1.5">
            <dt className="text-sm font-medium text-foreground">
              {t('users.record.role.label', 'Role')}
            </dt>
            <dd className="text-sm text-foreground">{current.role}</dd>
          </div>
          <div className="space-y-1.5">
            <dt className="text-sm font-medium text-foreground">
              {t('users.record.ou.label', 'Organisational unit')}
            </dt>
            <dd className="text-sm text-foreground">{ouLabelFor(current.ouId)}</dd>
          </div>
          <div className="space-y-1.5">
            <dt className="text-sm font-medium text-foreground">
              {t('users.record.twoStep.label', 'Two-step')}
            </dt>
            <dd className="text-sm text-foreground">{twoStepLabel}</dd>
          </div>
        </dl>
      )}
    </div>
  );

  const changedFields = [
    form.role !== (current.role ?? '') ? t('users.record.changed.role', 'role') : null,
    form.ouId !== current.ouId ? t('users.record.changed.ou', 'unit') : null,
    form.displayName.trim() !== current.displayName ? t('users.record.changed.name', 'name') : null,
  ].filter((part): part is string => part !== null);

  const canDeactivate = can(USERS_WRITE) && adapter.setAccountStatus !== undefined;
  const canDelete = can(USERS_DELETE) && adapter.deleteUser !== undefined;
  const canRequireTwoStep = can(SECURITY_MANAGE) && adapter.setTwoStepRequirement !== undefined;

  const accessTab = (
    <RecordCollectionPanel
      testId="user-record-memberships"
      title={t('users.record.memberships.title', 'Workspaces and roles')}
      subtitle={t(
        'users.record.memberships.subtitle',
        'Every workspace this person belongs to, and the role they hold in each.'
      )}
      resource={memberships}
      emptyLabel={t('users.record.memberships.empty', 'This person belongs to no workspace.')}
    >
      {(items) => (
        <RecordList>
          {items.map((membership) => (
            <RecordListItem
              key={membership.id}
              primary={membership.tenantName}
              secondary={membership.role}
              action={
                membership.isPrimary ? (
                  <Badge variant="outline">{t('users.record.memberships.primary', 'Primary')}</Badge>
                ) : undefined
              }
            />
          ))}
        </RecordList>
      )}
    </RecordCollectionPanel>
  );

  const historyTab = (
    <RecordCollectionPanel
      testId="user-record-activity"
      title={t('users.record.activity.title', 'What changed')}
      subtitle={t(
        'users.record.activity.subtitle',
        'Everything that changed about this person, newest first.'
      )}
      resource={activity}
      emptyLabel={t(
        'users.record.activity.empty',
        'Nothing has been recorded against this person yet.'
      )}
      placeholderRows={3}
    >
      {(entries) => (
        <RecordTimeline>
          {entries.map((entry) => (
            <RecordTimelineItem
              key={entry.id}
              title={historySentence(entry, t)}
              detail={heldSince(entry, t, dates)}
              // The WHEN survives unless the workspace hides dates (#1068). The
              // WHO is an id, so it and the event code are technical details.
              meta={
                <>
                  {!dates.hidden && (dates.dateTime(entry.createdAt) ?? '—')}
                  <TechnicalDetails>
                    {' · '}
                    <code className="font-mono text-xs">{entry.action}</code>
                    {entry.actorUserId !== null &&
                      ` · ${t('users.record.history.by', 'by {id}', { id: entry.actorUserId })}`}
                  </TechnicalDetails>
                </>
              }
            />
          ))}
        </RecordTimeline>
      )}
    </RecordCollectionPanel>
  );

  return (
    <>
      <RecordPageShell
        testId="user-record"
        fields={fields}
        facts={userFacts}
        t={t}
        access={access}
        back={back}
        icon={<Avatar name={current.name || current.email} size={40} />}
        className={className}
        actions={
          <div className="flex items-center gap-2">
            <Button
              type="button"
              variant="outline"
              data-testid="user-record-send-reset-link"
              disabled={isSendingReset}
              onClick={() => void handleSendResetLink()}
            >
              {isSendingReset
                ? t('users.record.password.sending', 'Sending…')
                : t('users.record.password.action', 'Send reset link')}
            </Button>
            {canRequireTwoStep && (
              <Button
                type="button"
                variant="outline"
                data-testid="user-record-two-step"
                onClick={() =>
                  current.twoStep.requiredDirectly ? void changeTwoStep(false) : setConfirmingTwoStep(true)
                }
              >
                {current.twoStep.requiredDirectly
                  ? t('users.record.twoStep.requireStop', 'Stop requiring two-step')
                  : t('users.record.twoStep.require', 'Require two-step')}
              </Button>
            )}
            {(canDeactivate || canDelete) && (
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label={t('users.record.menu.label', 'More actions')}
                  >
                    <IconMenu2 />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  {canDeactivate && (
                    <DropdownMenuItem
                      onClick={() =>
                        void changeStatus(current.accountStatus === 'inactive' ? 'active' : 'inactive', true)
                      }
                    >
                      {current.accountStatus === 'inactive'
                        ? t('users.record.reactivate', 'Reactivate')
                        : t('users.record.deactivate', 'Deactivate')}
                    </DropdownMenuItem>
                  )}
                  {canDelete && (
                    <DropdownMenuItem variant="destructive" onClick={() => setConfirmingDelete(true)}>
                      {t('users.record.delete', 'Delete')}
                    </DropdownMenuItem>
                  )}
                </DropdownMenuContent>
              </DropdownMenu>
            )}
          </div>
        }
        main={{ editor, readOnly }}
        tabs={{
          overviewLabel: t('users.record.tab.overview', 'Overview'),
          items: [
            { key: 'access', label: t('users.record.tab.access', 'Access'), content: accessTab },
            ...(activity.status !== 'forbidden'
              ? [{ key: 'history', label: t('users.record.tab.history', 'History'), content: historyTab }]
              : []),
          ],
        }}
        saveBar={
          <SaveBar
            dirty={isDirty}
            summary={t('users.record.changed.summary', 'Changed: {fields}', {
              fields: changedFields.join(', '),
            })}
            discardLabel={t('users.record.discard', 'Discard')}
            saveLabel={t('users.record.save', 'Save changes')}
            saving={isSaving}
            onDiscard={() => resetDraft(current)}
            onSave={() => void handleSubmit({ preventDefault: () => undefined } as FormEvent)}
          />
        }
      />
      <ConfirmAction
        open={confirmingTwoStep}
        onOpenChange={setConfirmingTwoStep}
        variant="default"
        title={t('users.record.twoStep.confirm.title', 'Require two-step of {name}?', { name: current.name })}
        description={t(
          'users.record.twoStep.confirm.description',
          'If they do not have it on, they have to set it up the next time they sign in.'
        )}
        confirmLabel={t('users.record.twoStep.require', 'Require two-step')}
        cancelLabel={t('users.record.twoStep.confirm.cancel', 'Cancel')}
        onConfirm={() => changeTwoStep(true)}
      />
      <ConfirmAction
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        title={t('users.record.delete.title', 'Delete {name}?', { name: current.name })}
        description={t(
          'users.record.delete.description',
          'They lose access to this workspace and their role here is removed. This cannot be undone.'
        )}
        confirmLabel={t('users.record.delete.confirm', 'Delete person')}
        cancelLabel={t('users.record.delete.cancel', 'Cancel')}
        onConfirm={handleDelete}
      />
    </>
  );
}
