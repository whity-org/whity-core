'use client';

/**
 * You in [workspace] › Your role (WHIT-1081). The role used to sit on Profile
 * without saying which workspace it was for; a role belongs to one workspace,
 * so it lives here, with your roles in your other workspaces underneath.
 */

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { TenantLogo } from '@whity/ui/tenant-logo';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { roleLabel } from '@whity/features/roles/role-label';
import { useAuth } from '@/lib/auth-context';
import { localizedName } from '@/lib/localized-name';
import { roleNames } from '@/lib/workspace-memberships';
import { MySettingsHeader, useCurrentWorkspace } from '@/components/settings/scope';

export default function RoleSettingsPage() {
  const t = useTranslation('auth');
  const language = useLanguageCode() ?? 'en';
  const { user, memberships } = useAuth();
  const { id: currentId, name: workspace } = useCurrentWorkspace();

  // A person may hold several roles in one workspace (WHIT-1079): `roles`
  // lists them, and an older answer may still send one entry per role.
  const here = memberships.filter((m) => m.tenant_id === currentId);
  const heldHere = [...new Set(here.flatMap((m) => roleNames(m)))];
  const roles = heldHere.length > 0 ? heldHere : user?.role ? [user.role] : [];
  const others = new Map<number, { name: string; roles: string[] }>();
  for (const m of memberships) {
    if (m.tenant_id === currentId) continue;
    const entry = others.get(m.tenant_id) ?? {
      name: localizedName(m.tenant_names, language, m.tenant_name_language, m.tenant_name),
      roles: [],
    };
    for (const name of roleNames(m)) if (!entry.roles.includes(name)) entry.roles.push(name);
    others.set(m.tenant_id, entry);
  }
  const isCustomer = here.some((m) => m.kind === 'customer');

  return (
    <div className="space-y-6">
      <MySettingsHeader
        title={t('settings.role.title', 'Your role')}
        description={t('settings.role.pageDescription', 'What you can see and change is set by your role.')}
        scope="workspace"
      />
      <Card>
        <CardHeader>
          <CardTitle>
            {workspace
              ? t('settings.role.inWorkspace', 'In {workspace}').replace('{workspace}', workspace)
              : t('settings.role.inThisWorkspace', 'In this workspace')}
          </CardTitle>
          <CardDescription>
            {isCustomer
              ? t('settings.role.customer', 'You joined as a customer, so you see what this workspace offers its customers.')
              : t('settings.role.change', 'An owner of this workspace can change your role.')}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <ul className="flex flex-wrap gap-2" data-testid="role-list">
            {roles.map((role) => (
              <li key={role} className="rounded-full bg-muted px-3 py-1 text-sm font-medium">
                {roleLabel(role)}
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>
      {others.size > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>{t('settings.role.others', 'In your other workspaces')}</CardTitle>
            <CardDescription>
              {t('settings.role.othersHelp', 'Switch workspace to see or change anything there.')}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <ul className="divide-y divide-border" data-testid="other-workspace-roles">
              {[...others.entries()].map(([id, entry]) => (
                <li key={id} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                  <TenantLogo name={entry.name} size={24} />
                  <span className="text-muted-foreground">{entry.roles.map((role) => roleLabel(role)).join(' · ')}</span>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
