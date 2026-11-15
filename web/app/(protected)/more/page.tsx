'use client';

/**
 * More (`PwNav`, PW.2; the phone app's `TmMore`, M.41): on a phone the
 * sidebar becomes this page. Your card first, then every group the sidebar
 * has, in the same order, then your own settings: appearance, language and
 * signing out, which on a computer live in the account menu (WHIT-827).
 *
 * Reachable at any width; only the phone's tab bar links to it.
 */

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Avatar } from '@whity/ui/avatar';
import { Icon } from '@whity/ui/icon';
import { useCurrentLanguage, useI18nEnabled, useTranslation } from '@whity/features/i18n';
import { roleLabel } from '@whity/features/roles/role-label';
import { useAuth } from '@/lib/auth-context';
import { useThemeMode } from '@/lib/theme-mode-context';
import { useShellNav } from '@/components/shell/use-shell-nav';
import { TenantSwitcher } from '@/components/sidebar';
import { InstallOffer } from '@/components/pwa/install-offer';
import { localizedName } from '@/lib/localized-name';
import { rolesLabel } from '@/lib/workspace-memberships';
import { SETTINGS_MENU_HREF } from '@/components/settings/settings-area';
import { NOTIFICATIONS_HREF } from '@/lib/pwa/push';

/** Nav items the tab bar already holds. */
const TAB_ITEM_IDS = ['dashboard', 'inbox', 'chat'];

const ROW =
  'flex min-h-12 w-full items-center gap-3 px-4 py-2 text-start text-base text-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring';

export default function MorePage() {
  const t = useTranslation('common');
  const router = useRouter();
  const { user, memberships, logout } = useAuth();
  const { groups: allGroups } = useShellNav();
  // Home, Inbox and Chat are tabs already; More lists everything else.
  const groups = allGroups
    .map((group) => ({ ...group, items: group.items.filter((item) => !TAB_ITEM_IDS.includes(item.id)) }))
    .filter((group) => group.items.length > 0);
  const { resolved, toggle } = useThemeMode();
  const isI18nEnabled = useI18nEnabled();
  const { currentLanguage, availableLanguages, setLanguage } = useCurrentLanguage();

  const displayName = user?.display_name || user?.email || '';
  const membership = memberships.find((m) => m.tenant_id === user?.tenant_id);
  const workspace = membership
    ? localizedName(membership.tenant_names, currentLanguage ?? 'en', membership.tenant_name_language, membership.tenant_name)
    : '';
  const role = (membership ? rolesLabel(membership) : '') || user?.role || '';

  return (
    <div className="mx-auto flex max-w-xl flex-col gap-5 pb-4">
      <h1 className="text-2xl font-semibold">{t('phoneNav.more', 'More')}</h1>

      <Link
        href="/settings"
        className="flex min-h-16 items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
        data-testid="more-account"
      >
        <Avatar name={displayName} size={40} />
        <span className="min-w-0 flex-1">
          <span className="block truncate text-base font-medium">{displayName}</span>
          <span className="block truncate text-sm text-muted-foreground">
            {[role ? roleLabel(role) : '', workspace].filter(Boolean).join(' · ')}
          </span>
        </span>
        <Icon name="chevron-right" size={18} className="text-muted-foreground" />
      </Link>

      <InstallOffer />

      {memberships.length > 1 && (
        <TenantSwitcher memberships={memberships} activeTenantId={user?.tenant_id} collapsed={false} />
      )}

      {groups.map((group) => (
        <section key={group.id} aria-labelledby={group.label ? `more-${group.id}` : undefined}>
          {group.label && (
            <h2 id={`more-${group.id}`} className="mb-1.5 px-1 text-sm font-medium text-muted-foreground">
              {group.label}
            </h2>
          )}
          <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
            {group.items.map((item) => (
              <li key={item.id}>
                <Link href={item.id === 'settings-area' ? SETTINGS_MENU_HREF : item.href} className={ROW} aria-current={item.active ? 'page' : undefined}>
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

      <section aria-labelledby="more-you">
        <h2 id="more-you" className="mb-1.5 px-1 text-sm font-medium text-muted-foreground">
          {t('phoneNav.you', 'You')}
        </h2>
        <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
          {/* WHIT-1078: push on this phone and which kinds, one tap away. */}
          <li>
            <Link href={NOTIFICATIONS_HREF} className={ROW} data-testid="more-notifications">
              <Icon name="notification" size={20} className="text-muted-foreground" />
              <span className="flex-1">{t('phoneNav.notifications', 'Notifications')}</span>
              <Icon name="chevron-right" size={18} className="text-muted-foreground" />
            </Link>
          </li>
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
      </section>
    </div>
  );
}
