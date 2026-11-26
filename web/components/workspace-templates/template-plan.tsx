'use client';

import type { components } from '@whity/api-client';
import { useTranslation } from '@whity/features/i18n';
import { Icon } from '@whity/ui/icon';
import type { IconName } from '@whity/ui/icon-map';

export type TemplateItem = components['schemas']['WorkspaceTemplateItem'];

type T = ReturnType<typeof useTranslation>;

/** The order parts are listed in, as a person meets them. */
const PARTS = ['settings', 'theme', 'calendar', 'services', 'roles', 'forms', 'pipelines', 'documents', 'examples'] as const;

/**
 * What a template adds, keeps or can't add, in plain words (WHIT-1429,
 * decision WT.1): one group per part, each item by its name with what
 * happens to it. Built from the dry run (`POST .../preview`), so it is exactly
 * what applying would do. Keys and raw errors never show.
 *
 * No card yet: brand ask brand-ask-workspace-templates.md §4.
 */
export function TemplatePlan({
  items,
  only,
  renderAction,
}: {
  items: TemplateItem[];
  /** Only these actions, such as `['add']` for what an update adds. */
  only?: TemplateItem['action'][];
  /** Something to put after an item, such as "Use the template's version". */
  renderAction?: (item: TemplateItem) => React.ReactNode;
}) {
  const t = useTranslation('admin');
  const shown = only ? items.filter((item) => only.includes(item.action)) : items;
  const groups = PARTS.map((part) => ({ part, items: shown.filter((item) => item.part === part) })).filter((group) => group.items.length > 0);

  if (groups.length === 0) {
    return <p className="text-sm text-muted-foreground">{t('templates.plan.nothing', 'Nothing to add: your workspace already has all of it.')}</p>;
  }

  return (
    <div className="space-y-4">
      {groups.map((group) => (
        <section key={group.part} className="space-y-1">
          <h3 className="text-xs text-muted-foreground">{partTitle(t, group.part)}</h3>
          <ul className="divide-y divide-border rounded-md border border-border">
            {group.items.map((item) => (
              <li key={`${item.part}:${item.key}`} className="flex min-h-12 flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm">
                <Icon name={actionIcon(item.action)} className={actionTone(item.action)} aria-hidden />
                <span className="min-w-0 flex-1">
                  <span className="block text-foreground">{itemName(t, item)}</span>
                  <span className="block text-muted-foreground">{actionText(t, item)}</span>
                </span>
                {renderAction ? renderAction(item) : null}
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  );
}

function partTitle(t: T, part: string): string {
  switch (part) {
    case 'settings':
      return t('templates.part.settings', 'Workspace settings');
    case 'theme':
      return t('templates.part.theme', 'Look');
    case 'calendar':
      return t('templates.part.calendar', 'Booking rules and hours');
    case 'services':
      return t('templates.part.services', 'Services');
    case 'roles':
      return t('templates.part.roles', 'Roles');
    case 'forms':
      return t('templates.part.forms', 'Forms');
    case 'pipelines':
      return t('templates.part.pipelines', 'Review routes');
    case 'documents':
      return t('templates.part.documents', 'Documents');
    default:
      return t('templates.part.examples', 'Examples');
  }
}

/** The item's own name; settings, the look and the calendar are worded here. */
export function itemName(t: T, item: TemplateItem): string {
  if (item.label) {
    return item.label;
  }
  if (item.part === 'theme') {
    return t('templates.item.theme', 'The template’s colours, shapes and fonts');
  }
  if (item.part === 'calendar') {
    return item.key === 'hours'
      ? t('templates.item.hours', 'Your opening hours')
      : t('templates.item.bookingRules', 'Booking rules');
  }
  switch (item.key) {
    case 'timezone':
      return t('templates.item.timezone', 'Time zone');
    case 'locale':
      return t('templates.item.locale', 'Language');
    case 'billing.default_currency':
      return t('templates.item.currency', 'Currency');
    case 'calendar.week_start':
      return t('templates.item.weekStart', 'First day of the week');
    case 'calendar.weekend_days':
      return t('templates.item.weekendDays', 'Weekend days');
    default:
      return t('templates.item.other', 'Another setting');
  }
}

function actionText(t: T, item: TemplateItem): string {
  switch (item.action) {
    case 'add':
      return t('templates.action.add', 'Will be added');
    case 'keep':
      return t('templates.action.keep', 'Already there: kept as you have it');
    case 'done':
      return t('templates.action.done', 'Added before');
    case 'changed':
      return t('templates.action.changed', 'Changed in the template since. Yours stays as it is.');
    case 'left':
      return t('templates.action.left', 'Not added: choose it in the setup if you want it');
    default:
      return skipReason(t, item.reason);
  }
}

/** The engine's reasons, in the reader's language; any other is said plainly. */
export function skipReason(t: T, reason: string | undefined): string {
  if (!reason) {
    return t('templates.reason.other', 'Can’t add this now.');
  }
  if (reason.startsWith('You may not design review routes') || reason === 'Forbidden' || reason.includes('(403)')) {
    return t('templates.reason.permission', 'Can’t add: you don’t have permission for this.');
  }
  if (reason.startsWith('Its form is not in your workspace')) {
    return t('templates.reason.noForm', 'Can’t add: its form isn’t in your workspace.');
  }
  if (reason.startsWith('Its form already sends')) {
    return t('templates.reason.formHasRoute', 'Made, but its form keeps the review route you chose.');
  }
  if (reason.startsWith('The role for')) {
    return t('templates.reason.noRole', 'Can’t add: one of its roles isn’t in your workspace.');
  }
  if (reason.startsWith('Its service or contact was not made')) {
    return t('templates.reason.noExampleParts', 'Can’t add: its service or customer wasn’t made.');
  }
  if (reason.startsWith('Some permissions were left out')) {
    return t('templates.reason.somePermissions', 'Added without the permissions you don’t hold yourself.');
  }
  if (reason.startsWith('It is no longer in your workspace')) {
    return t('templates.reason.gone', 'It’s no longer in your workspace.');
  }
  if (reason.startsWith('This template did not make that item here')) {
    return t('templates.reason.notMade', 'The template didn’t make this here, so it’s yours to change.');
  }
  return t('templates.reason.other', 'Can’t add this now.');
}

function actionIcon(action: TemplateItem['action']): IconName {
  switch (action) {
    case 'add':
      return 'plus';
    case 'keep':
    case 'done':
      return 'check';
    case 'changed':
      return 'sync';
    case 'left':
      return 'minus';
    default:
      return 'alert';
  }
}

function actionTone(action: TemplateItem['action']): string {
  switch (action) {
    case 'add':
      return 'text-primary';
    case 'skip':
      return 'text-warning';
    case 'changed':
      return 'text-info';
    default:
      return 'text-muted-foreground';
  }
}
