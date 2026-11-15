'use client';

/**
 * More (`PwNav`, PW.2; the phone app's `TmMore`, M.41): on a phone the
 * sidebar becomes this page.
 *
 * Since WHIT-1083 (navigation proposal, owner-approved 15 Nov 2026) your own
 * settings come first and every one is labelled, so each is two taps from
 * anywhere: More, then the row. Before, they hid behind an unlabelled name
 * card and a Preferences tab. Then the workspace switcher, every group the
 * sidebar has (in the same order), Workspace settings for people who can
 * change any, and Sign out. The quick Dark mode and language rows stay
 * (owner decision).
 *
 * Reachable at any width; only the phone's tab bar links to it. Search is the
 * top bar's field above every phone page (Ctrl+K on a computer).
 */

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Avatar } from '@whity/ui/avatar';
import { Icon, type IconName } from '@whity/ui/icon';
import { useCurrentLanguage, useI18nEnabled, useTranslation } from '@whity/features/i18n';
import { roleLabel } from '@whity/features/roles/role-label';
import { useAuth } from '@/lib/auth-context';
import { useNavigation } from '@/lib/navigation-context';
import { useThemeMode } from '@/lib/theme-mode-context';
import { useShellNav } from '@/components/shell/use-shell-nav';
import { TenantSwitcher } from '@/components/sidebar';
import { InstallOffer } from '@/components/pwa/install-offer';
import { localizedName } from '@/lib/localized-name';
import { rolesLabel } from '@/lib/workspace-memberships';
import { SETTINGS_AREA_NAV_IDS, WORKSPACE_PAGE_NAV_IDS } from '@/components/settings/settings-area';
import { MY_SETTINGS_HREF, WORKSPACE_SETTINGS_MENU_HREF } from '@/lib/settings/my-settings';
import { NOTIFICATIONS_HREF } from '@/lib/pwa/push';

/** Nav items the tab bar already holds, and the Settings entry, which More splits in two. */
const HIDDEN_ITEM_IDS = ['dashboard', 'inbox', 'chat', 'settings-area'];

const ROW =
  'flex min-h-12 w-full items-center gap-3 px-4 py-2 text-start text-base text-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring';
const LIST = 'divide-y divide-border overflow-hidden rounded-xl border border-border bg-card';
const HEADING = 'mb-1.5 flex flex-wrap items-center gap-2 px-1 text-sm font-medium text-muted-foreground';

function RowLink({
  href,
  icon,
  label,
  hint,
  testId,
}: {
  href: string;
  icon: IconName;
  label: string;
  /** For a setting that belongs to one workspace: which one. */
  hint?: string;
  testId: string;
}) {
  return (
    <li>
      <Link href={href} className={ROW} data-testid={testId}>
        <Icon name={icon} size={20} className="text-muted-foreground" />
        <span className="min-w-0 flex-1">
          <span className="block truncate">{label}</span>
          {hint && <span className="block truncate text-sm text-muted-foreground">{hint}</span>}
        </span>
        <Icon name="chevron-right" size={18} className="text-muted-foreground" />
      </Link>
    </li>
  );
}

export default function MorePage() {
  const t = useTranslation('common');
  const ta = useTranslation('auth');
  const router = useRouter();
  const { user, memberships, logout } = useAuth();
  const { items: navItems } = useNavigation();
  const { groups: allGroups } = useShellNav();
  // Home, Inbox and Chat are tabs already; More lists everything else.
  const groups = allGroups
    .map((group) => ({ ...group, items: group.items.filter((item) => !HIDDEN_ITEM_IDS.includes(item.id)) }))
    .filter((group) => group.items.length > 0);
  const { resolved, toggle } = useThemeMode();
  const isI18nEnabled = useI18nEnabled();
  const { currentLanguage, availableLanguages, setLanguage } = useCurrentLanguage();

  const displayName = user?.display_name || user?.email || '';
  const membership = memberships.find((m) => m.tenant_id === user?.tenant_id);
  const workspace = membership
    ? localizedName(membership.tenant_names, currentLanguage ?? 'en', membership.tenant_name_language, membership.tenant_name)
    : '';
  // Every role held here (WHIT-1079), already in words.
  const role = (membership ? rolesLabel(membership) : '') || (user?.role ? roleLabel(user.role) : '');

  // Workspace settings only for someone the server shows at least one of them to.
  const workspaceIds = [...SETTINGS_AREA_NAV_IDS, ...Object.keys(WORKSPACE_PAGE_NAV_IDS)] as string[];
  const canChangeWorkspace = navItems.some((item) => item.group === 'settings' || workspaceIds.includes(item.id));

  return (
    <div className="mx-auto flex max-w-xl flex-col gap-5 pb-4">
      <h1 className="text-2xl font-semibold">{t('phoneNav.more', 'More')}</h1>

      {memberships.length > 1 && (
        <TenantSwitcher memberships={memberships} activeTenantId={user?.tenant_id} collapsed={false} />
      )}

      <section aria-labelledby="more-mine" data-testid="more-my-settings">
        {/* No scope chip on the heading: Notifications and sessions belong to one
            workspace, so those rows name it instead. */}
        <h2 id="more-mine" className={HEADING}>
          {ta('settings.title', 'My settings')}
        </h2>
        <ul className={LIST}>
          <li>
            <Link href={MY_SETTINGS_HREF.profile} className={`${ROW} min-h-16`} data-testid="more-account">
              <Avatar name={displayName} size={40} />
              <span className="min-w-0 flex-1">
                <span className="block truncate text-base font-medium">{displayName}</span>
                {/* The card used to carry no label at all (the owner's failure path). */}
                <span className="block truncate text-sm text-muted-foreground">
                  {[ta('settings.profile.title', 'Profile'), role, workspace].filter(Boolean).join(' · ')}
                </span>
              </span>
              <Icon name="chevron-right" size={18} className="text-muted-foreground" />
            </Link>
          </li>
          {/* WHIT-1078: push on this phone and which kinds, one tap away. */}
          <RowLink
            href={NOTIFICATIONS_HREF}
            icon="notification"
            label={t('phoneNav.notifications', 'Notifications')}
            hint={workspace}
            testId="more-notifications"
          />
          <RowLink
            href={MY_SETTINGS_HREF.display}
            icon="text-size"
            label={ta('settings.tab.preferences', 'Language and display')}
            testId="more-display"
          />
          <RowLink
            href={MY_SETTINGS_HREF.password}
            icon="two-step"
            label={ta('settings.tab.security', 'Password and two-step')}
            testId="more-password"
          />
          <RowLink
            href={MY_SETTINGS_HREF['signed-in']}
            icon="desktop"
            label={ta('settings.tab.sessions', 'Where you’re signed in')}
            hint={workspace}
            testId="more-signed-in"
          />
          {/* Quick switches, kept by the owner: one tap, no page. */}
          <li>
            <button type="button" onClick={toggle} className={ROW} data-testid="more-theme">
              <Icon name={resolved === 'dark' ? 'sun' : 'moon'} size={20} className="text-muted-foreground" />
              <span className="flex-1">
                {resolved === 'dark' ? t('sidebar.theme.light', 'Light mode') : t('sidebar.theme.dark', 'Dark mode')}
              </span>
            </button>
          </li>
          {isI18nEnabled &&
            availableLanguages.length > 1 &&
            availableLanguages
              .filter((language) => language.code !== (currentLanguage || 'en'))
              .map((language) => (
                <li key={language.code}>
                  <button
                    type="button"
                    onClick={() => void setLanguage(language.code)}
                    className={ROW}
                    lang={language.code}
                  >
                    <Icon name="language" size={20} className="text-muted-foreground" />
                    <span className="flex-1">{language.name}</span>
                  </button>
                </li>
              ))}
        </ul>
      </section>

      <InstallOffer />

      {groups.map((group) => (
        <section key={group.id} aria-labelledby={group.label ? `more-${group.id}` : undefined}>
          {group.label && (
            <h2 id={`more-${group.id}`} className={HEADING}>
              {group.label}
            </h2>
          )}
          <ul className={LIST}>
            {group.items.map((item) => (
              <li key={item.id}>
                <Link href={item.href} className={ROW} aria-current={item.active ? 'page' : undefined}>
                  <span className="text-muted-foreground [&_svg]:size-5">{item.icon}</span>
                  <span className="min-w-0 flex-1 truncate">{item.label}</span>
                  {item.count ? (
                    <b
                      className="grid h-6 min-w-6 place-items-center rounded-full bg-destructive px-1.5 text-xs font-semibold text-destructive-foreground"
                      aria-label={item.countLabel}
                    >
                      <span aria-hidden={item.countLabel ? true : undefined}>{item.count > 99 ? '99+' : item.count}</span>
                    </b>
                  ) : null}
                  <Icon name="chevron-right" size={18} className="text-muted-foreground" />
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ))}

      <ul className={LIST}>
        {canChangeWorkspace && (
          <li>
            <Link href={WORKSPACE_SETTINGS_MENU_HREF} className={ROW} data-testid="more-workspace-settings">
              <Icon name="settings" size={20} className="text-muted-foreground" />
              <span className="min-w-0 flex-1">
                <span className="block truncate">{t('sidebar.item.websiteSettings', 'Workspace settings')}</span>
                {workspace && <span className="block truncate text-sm text-muted-foreground">{workspace}</span>}
              </span>
              <Icon name="chevron-right" size={18} className="text-muted-foreground" />
            </Link>
          </li>
        )}
        <li>
          <button
            type="button"
            onClick={() => {
              logout();
              router.push('/login');
            }}
            className={`${ROW} text-destructive`}
            data-testid="more-sign-out"
          >
            <Icon name="logout" size={20} />
            <span className="flex-1">{t('sidebar.logout', 'Sign out')}</span>
          </button>
        </li>
      </ul>
    </div>
  );
}
