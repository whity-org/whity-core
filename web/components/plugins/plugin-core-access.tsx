'use client';

import { useState } from 'react';
import { api } from '@/lib/api/client';
import type { components } from '@whity/api-client';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { fetchAllPagesTyped } from '@/lib/api/fetch-all-pages';
import { permissionText } from '@/app/(protected)/admin/delegations/permission-words';
import { useTranslation } from '@whity/features/i18n';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import { AreaError, LoadingRows } from '@whity/ui/page-states';

type CoreGrantRow = components['schemas']['PluginCoreGrantsResponse']['data']['grants'][number];
type Permission = { name: string; description?: string | null };

/**
 * Core access a plugin asks for its roles (#2256), in the plugin's detail
 * sheet, for the operator.
 *
 * A plugin may ask for some of Whity's own permissions for its roles (an HR
 * role writing leave to the calendar). Nothing is given until the operator
 * approves it here, per role and per workspace. Approve gives exactly what
 * the plugin asked for that role; Take back removes it. Both can be undone at
 * once, so neither asks to confirm (section 21).
 */
export function PluginCoreAccess({ pluginName, canChange }: { pluginName: string; canChange: boolean }) {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const [pending, setPending] = useState<string | null>(null);

  const { data, loading, error, refetch } = useFetch(async () => {
    const { data: response, error: failed } = await api.GET('/api/v1/plugins/{name}/core-grants', {
      params: { path: { name: pluginName } },
    });
    if (failed || !response) {
      throw new Error('core grants');
    }
    return response.data;
  }, [pluginName]);

  // Plain words for each permission; the code is a technical detail.
  const { data: words } = useFetch(async () => {
    const result = await fetchAllPagesTyped<Permission>((query) => api.GET('/api/v1/permissions', { params: { query } }));
    const map: Record<string, string> = {};
    for (const permission of result.items) {
      map[permission.name] = permissionText(permission.name, permission.description);
    }
    return map;
  }, []);
  const say = (code: string) => words?.[code] ?? permissionText(code);

  if (loading) {
    return <LoadingRows rows={2} label={t('plugins.coreAccess.loading', 'Loading core access')} headers={[t('plugins.coreAccess.title', 'Core access it asks for')]} />;
  }
  if (error) {
    return (
      <AreaError
        message={t('plugins.coreAccess.loadFailed', 'The core access this plugin asks for could not be loaded.')}
        retryLabel={t('plugins.tryAgain', 'Try again')}
        onRetry={() => void refetch()}
      />
    );
  }
  // Nothing asked for (or an answer without the fields): the section isn't shown.
  if (!data?.requests || !Array.isArray(data.grants) || Object.keys(data.requests).length === 0) {
    return null;
  }

  const change = async (row: CoreGrantRow, approve: boolean) => {
    const key = `${row.tenant_id}:${row.role}`;
    setPending(key);
    const path = approve
      ? '/api/v1/plugins/{name}/core-grants/{role}/approve'
      : '/api/v1/plugins/{name}/core-grants/{role}/revoke';
    const { error: failed } = await api.POST(path, {
      params: { path: { name: pluginName, role: row.role } },
      body: { tenant_id: row.tenant_id },
    });
    setPending(null);
    if (failed) {
      addToast(
        approve
          ? t('plugins.coreAccess.approveFailed', 'Could not approve it. You can only approve permissions you hold yourself.')
          : t('plugins.coreAccess.revokeFailed', 'Could not take it back. Try again.'),
        'error',
      );
      return;
    }
    addToast(
      approve
        ? t('plugins.coreAccess.approved', 'Approved for {role}.', { role: row.role })
        : t('plugins.coreAccess.revoked', 'Taken back from {role}.', { role: row.role }),
      'success',
    );
    refetch();
  };

  return (
    <div>
      <h4 className="mb-1.5 font-semibold text-muted-foreground">{t('plugins.coreAccess.title', 'Core access it asks for')}</h4>
      <p className="mb-2 text-muted-foreground">
        {t(
          'plugins.coreAccess.description',
          'This plugin asks for some of Whity\'s own permissions for its roles. Nothing is given until you approve it, for each role in each workspace. Turning the plugin off in a workspace takes it back there.'
        )}
      </p>
      {data.grants.length === 0 ? (
        <p className="text-muted-foreground">
          {t('plugins.coreAccess.noRoles', 'No workspace has these roles yet. They appear here once the plugin is on in a workspace.')}
        </p>
      ) : (
        <ul className="space-y-2">
          {data.grants.map((row) => {
            const key = `${row.tenant_id}:${row.role}`;
            const all = row.requested.every((p) => row.granted.includes(p));
            const none = row.granted.length === 0;
            return (
              <li key={key} className="space-y-2 rounded-card border border-border p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="min-w-0">
                    <p className="font-medium">{row.role}</p>
                    <p className="text-muted-foreground">
                      {row.tenant_id === 0 ? t('plugins.coreAccess.everyWorkspace', 'Every workspace (global role)') : row.workspace}
                    </p>
                  </div>
                  <Badge variant={all ? 'default' : 'outline'}>
                    {all
                      ? t('plugins.coreAccess.statusApproved', 'Approved')
                      : none
                        ? t('plugins.coreAccess.statusWaiting', 'Waiting for you')
                        : t('plugins.coreAccess.statusPart', 'Partly approved')}
                  </Badge>
                </div>
                <ul className="list-inside list-disc text-sm">
                  {row.requested.map((permission) => (
                    <li key={permission} className={row.granted.includes(permission) ? '' : 'text-muted-foreground'}>
                      {say(permission)}
                    </li>
                  ))}
                </ul>
                <TechnicalDetails>
                  <p className="break-all font-mono text-xs">{row.requested.join(', ')}</p>
                </TechnicalDetails>
                {canChange ? (
                  <div className="flex flex-wrap gap-2">
                    {!all ? (
                      <Button variant="outline" size="sm" disabled={pending === key} onClick={() => change(row, true)}>
                        {t('plugins.coreAccess.approve', 'Approve')}
                      </Button>
                    ) : null}
                    {!none ? (
                      <Button variant="outline" size="sm" disabled={pending === key} onClick={() => change(row, false)}>
                        {t('plugins.coreAccess.revoke', 'Take back')}
                      </Button>
                    ) : null}
                  </div>
                ) : null}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
