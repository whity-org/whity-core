'use client';

/**
 * Where a setting applies (WHIT-1081). People in more than one workspace could
 * not tell what a change reaches, so every personal settings page says it:
 *
 * - **All your workspaces**: stored on the account (profile, password and
 *   two-step, language, text size).
 * - **[workspace]**: belongs to the workspace you're in (notifications,
 *   sessions, your role), shown with its logo.
 * - **This device**: kept in this browser (light or dark, push on this phone).
 *
 * No design card yet: brand-ask-navigation-settings.md, item 2. Built from the
 * Badge's shape and the tenant logo; it is a label, not a status.
 */

import type { ReactNode } from 'react';
import { Icon } from '@whity/ui/icon';
import { TenantLogo } from '@whity/ui/tenant-logo';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { useAuth } from '@/lib/auth-context';
import { useBranding } from '@/lib/branding-context';
import { localizedText } from '@whity/ui/localized-text';
import type { SettingScope } from '@/lib/settings/my-settings';

/** The workspace the person is in, named in their language. */
export function useCurrentWorkspace(): { id: number | undefined; name: string } {
  const { user, memberships } = useAuth();
  const language = useLanguageCode();
  const membership = memberships.find((m) => m.tenant_id === user?.tenant_id);
  const name = membership
    ? localizedText(membership.tenant_names, language ?? 'en', membership.tenant_name_language, membership.tenant_name)
    : '';
  return { id: user?.tenant_id, name };
}

export function ScopeChip({ scope, className }: { scope: SettingScope; className?: string }) {
  const t = useTranslation('auth');
  const { name } = useCurrentWorkspace();
  const { logoSquareUrl } = useBranding();
  const base =
    'inline-flex max-w-full items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap';

  if (scope === 'workspace') {
    return (
      <span data-scope="workspace" className={`${base} border border-border bg-card text-foreground ${className ?? ''}`}>
        <TenantLogo name={name} src={logoSquareUrl} size={16} showName={false} />
        <span className="min-w-0 truncate">{name || t('settings.scope.thisWorkspace', 'This workspace')}</span>
      </span>
    );
  }
  if (scope === 'device') {
    return (
      <span data-scope="device" className={`${base} bg-muted text-muted-foreground ${className ?? ''}`}>
        <Icon name="desktop" size={14} />
        {t('settings.scope.device', 'This device')}
      </span>
    );
  }
  return (
    <span data-scope="account" className={`${base} bg-primary-tint text-primary-tint-foreground ${className ?? ''}`}>
      <Icon name="world" size={14} />
      {t('settings.scope.account', 'All your workspaces')}
    </span>
  );
}

/** One line saying what the scope means, for the page header. */
function scopeLine(scope: SettingScope, workspace: string, t: ReturnType<typeof useTranslation>): string {
  if (scope === 'account') return t('settings.scope.accountHelp', 'These follow you into every workspace.');
  if (scope === 'device') return t('settings.scope.deviceHelp', 'Kept on this device only.');
  return workspace
    ? t('settings.scope.workspaceHelp', 'Only in {workspace}. Your other workspaces have their own.').replace('{workspace}', workspace)
    : t('settings.scope.workspaceHelpNoName', 'Only in this workspace. Your other workspaces have their own.');
}

/** A personal settings page's title, what it holds, and where it applies. */
export function MySettingsHeader({
  title,
  description,
  scope,
  children,
}: {
  title: string;
  description: string;
  scope: SettingScope;
  children?: ReactNode;
}) {
  const t = useTranslation('auth');
  const { name } = useCurrentWorkspace();
  return (
    <header className="grid gap-2" data-testid="my-settings-header">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
        <h1 className="text-2xl font-semibold">{title}</h1>
        <ScopeChip scope={scope} />
      </div>
      <p className="text-sm text-muted-foreground">
        {description} {scopeLine(scope, name, t)}
      </p>
      {children}
    </header>
  );
}
