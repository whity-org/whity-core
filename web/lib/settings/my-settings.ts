/**
 * Your own settings (WHIT-1081; navigation proposal approved 15 Nov 2026).
 *
 * They sit at the top of the one Settings area, in two blocks, so a person in
 * several workspaces can see what a change reaches:
 *
 * - **My settings** follow the account into every workspace: profile,
 *   password and two-step, language and display.
 * - **You in [workspace]** belong to the workspace you're in: notifications,
 *   where you're signed in (sessions are per workspace), your role.
 *
 * The old single page with tabs (`/settings?tab=…`) redirects here, and so
 * does the phone's old settings menu (`/admin/settings/menu`).
 */

/** Where a setting applies: your account, this workspace, or this device. */
export type SettingScope = 'account' | 'workspace' | 'device';

export type MySettingsGroup = 'mine' | 'here';

export interface MySettingsPage {
  id: string;
  href: string;
  group: MySettingsGroup;
}

/** The Settings area's own root: the menu on a phone, Profile on a computer. */
export const SETTINGS_HOME_HREF = '/settings';

/** The phone's settings menu, scrolled to the workspace's part (More links here). */
export const WORKSPACE_SETTINGS_ANCHOR = 'workspace-settings';
export const WORKSPACE_SETTINGS_MENU_HREF = `${SETTINGS_HOME_HREF}#${WORKSPACE_SETTINGS_ANCHOR}`;

export const MY_SETTINGS_PAGES: readonly MySettingsPage[] = [
  { id: 'profile', href: '/settings/profile', group: 'mine' },
  { id: 'password', href: '/settings/security', group: 'mine' },
  { id: 'display', href: '/settings/display', group: 'mine' },
  { id: 'notifications', href: '/settings/notifications', group: 'here' },
  { id: 'signed-in', href: '/settings/signed-in', group: 'here' },
  { id: 'role', href: '/settings/role', group: 'here' },
] as const;

export const MY_SETTINGS_HREF: Readonly<Record<string, string>> = Object.fromEntries(
  MY_SETTINGS_PAGES.map((page) => [page.id, page.href]),
);

/**
 * Where an old `/settings?tab=…` link lands now. The Notifications card used to
 * be inside Preferences, reached as `?tab=preferences#notifications` (WHIT-1078).
 */
export function redirectForOldTab(tab: string | null, hash: string): string | null {
  if (tab === null) return null;
  if (tab === 'preferences') {
    return /^#?(notifications|push)$/.test(hash) ? MY_SETTINGS_HREF.notifications : MY_SETTINGS_HREF.display;
  }
  if (tab === 'sessions') return MY_SETTINGS_HREF['signed-in'];
  if (tab === 'security') return MY_SETTINGS_HREF.password;
  return MY_SETTINGS_HREF.profile;
}
