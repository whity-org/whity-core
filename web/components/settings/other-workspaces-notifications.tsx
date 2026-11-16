'use client';

/**
 * Your notifications in your other workspaces, read-only (WHIT-1081; owner
 * decision 15 Nov 2026: show them, editing comes later). Choices and push
 * devices are stored per workspace, so a person in several could not tell
 * where they would hear from. One row each: push devices, then whether email
 * and push are on, off or on for some kinds. To change one, switch to it.
 *
 * Shown only to someone in more than one workspace. A failed read shows
 * nothing rather than a wrong answer.
 */

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { TenantLogo } from '@whity/ui/tenant-logo';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import type { components } from '@whity/api-client';
import { api } from '@/lib/api/client';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { localizedText } from '@whity/ui/localized-text';

type Row = components['schemas']['NotificationSummaryWorkspace'];

export function OtherWorkspacesNotifications() {
  const t = useTranslation('auth');
  const language = useLanguageCode() ?? 'en';
  const { memberships } = useAuth();
  const several = new Set(memberships.map((m) => m.tenant_id)).size > 1;

  const { data } = useFetch<Row[]>(async () => {
    if (!several) return [];
    const { data: body } = await api.GET('/api/v1/me/notification-summary');
    return body?.data ?? [];
  }, [several]);

  const others = (data ?? []).filter((row) => !row.current);
  if (!several || others.length === 0) return null;

  const state = (value: Row['email']) =>
    value === 'on'
      ? t('settings.otherWorkspaces.on', 'on')
      : value === 'off'
      ? t('settings.otherWorkspaces.off', 'off')
      : t('settings.otherWorkspaces.some', 'some kinds');

  return (
    <Card data-testid="other-workspaces-notifications">
      <CardHeader>
        <CardTitle>{t('settings.otherWorkspaces.title', 'In your other workspaces')}</CardTitle>
        <CardDescription>
          {t(
            'settings.otherWorkspaces.description',
            'Each workspace keeps its own notifications. Switch workspace to change them there.',
          )}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <ul className="divide-y divide-border">
          {others.map((row) => {
            const membership = memberships.find((m) => m.tenant_id === row.tenant_id);
            const name = membership
              ? localizedText(membership.tenant_names, language, membership.tenant_name_language, membership.tenant_name)
              : '';
            const devices =
              row.push_devices === 1
                ? t('settings.otherWorkspaces.oneDevice', 'Push on 1 device')
                : row.push_devices > 1
                ? t('settings.otherWorkspaces.devices', 'Push on {count} devices').replace('{count}', String(row.push_devices))
                : t('settings.otherWorkspaces.noDevices', 'No device with push');
            return (
              <li key={row.tenant_id} className="flex flex-wrap items-center gap-x-4 gap-y-1 py-3 text-sm" data-testid={`other-workspace-${row.tenant_id}`}>
                <span className="min-w-0 flex-1">
                  <TenantLogo name={name} size={24} />
                </span>
                <span className="text-muted-foreground">
                  {[
                    devices,
                    t('settings.otherWorkspaces.email', 'Email: {state}').replace('{state}', state(row.email)),
                    t('settings.otherWorkspaces.push', 'Push: {state}').replace('{state}', state(row.push)),
                  ].join(' · ')}
                </span>
              </li>
            );
          })}
        </ul>
      </CardContent>
    </Card>
  );
}
