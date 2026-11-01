'use client';

/**
 * One agent credential: what it may do for the person who issued it (screen
 * A21, TplAgentScope; WHIT-810). Each permission is Off, Ask first or
 * Allowed. Ask first means the agent proposes and the person approves in
 * Approvals; Allowed means it acts. The server offers only the modes the
 * person may give (never more than they hold), says why one is withheld, and
 * applies a change to the token the agent already holds from its next call.
 *
 * Only the issuer sees it: anyone else gets the server's 404, shown here as
 * "not yours to set".
 */

import { useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { ScopeGrant, type ScopeGrantMode } from '@whity-org/ui/scope-grant';
import { Button } from '@whity-org/ui/button';
import { EmptyState } from '@whity-org/ui/empty-state';
import { Icon } from '@whity-org/ui/icon';
import { useTranslation } from '@whity-org/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import {
  grantGroups,
  grantPath,
  shownMode,
  type AgentGrant,
  type GrantMode,
  type GrantRow,
} from '@/components/agents/agent-grant';

/** useFetch keeps only a message, so the 404 travels as this one. */
const NOT_YOURS = 'agent-grant:not-yours';

export default function AgentGrantPage() {
  const t = useTranslation('admin');
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const params = useParams<{ jti: string }>();
  const jti = params?.jti ?? '';
  const [saved, setSaved] = useState<AgentGrant | null>(null);
  const [saving, setSaving] = useState(false);

  const loaded = useFetch<AgentGrant>(async () => {
    const response = await apiClient(grantPath(jti));
    if (response.status === 404) throw new Error(NOT_YOURS);
    if (!response.ok) throw new Error(t('agentGrant.error.load', "This agent's permissions could not be loaded."));
    const body = (await response.json()) as { data: AgentGrant };
    return body.data;
  }, [apiClient, jti]);

  const grant = saved ?? loaded.data;

  const modeWords: Record<GrantMode, string> = {
    off: t('agentGrant.mode.off', 'Off'),
    ask: t('agentGrant.mode.ask', 'Ask first'),
    allow: t('agentGrant.mode.allow', 'Allowed'),
  };

  // Withheld reasons by their stable key; the server's English is the fallback.
  const reasonWords: Record<string, string> = {
    not_held: t('agentGrant.withheld.notHeld', "You don't have this permission, so an agent acting for you can't either."),
    outside_credential: t('agentGrant.withheld.outsideCredential', 'This credential is limited to other permissions.'),
    always_asks: t('agentGrant.withheld.alwaysAsks', "What this permission changes can't be undone yet, so the agent always asks first."),
    refused_for_agents: t('agentGrant.withheld.refused', 'Nothing this permission allows is open to agents.'),
  };

  const hint = (row: GrantRow): string | undefined => {
    const parts: string[] = [];
    if (row.mode === null) parts.push(t('agentGrant.default', 'Default'));
    const effective: string[] = [];
    if (row.effective.read !== null) {
      effective.push(t('agentGrant.effective.read', 'Reads: {mode}').replace('{mode}', modeWords[row.effective.read]));
    }
    if (row.effective.write !== null) {
      effective.push(t('agentGrant.effective.write', 'Changes: {mode}').replace('{mode}', modeWords[row.effective.write]));
    }
    if (effective.length > 0) parts.push(effective.join(' · '));
    const withheld = Object.values(row.withheld)[0];
    if (withheld) parts.push(reasonWords[withheld.reason] ?? withheld.message);
    return parts.length > 0 ? parts.join(' — ') : undefined;
  };

  const change = async (permission: string, mode: ScopeGrantMode) => {
    setSaving(true);
    try {
      const response = await apiClient(grantPath(jti), {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ [permission]: mode }),
      });
      const body = (await response.json().catch(() => null)) as { data?: AgentGrant; error?: string } | null;
      if (!response.ok || !body?.data) {
        addToast(body?.error ?? t('agentGrant.error.save', 'That change could not be saved. Try again.'), 'error', 6000);
        return;
      }
      setSaved(body.data);
      addToast(
        t('agentGrant.saved', '{permission}: {mode}')
          .replace('{permission}', permission)
          .replace('{mode}', modeWords[mode]),
        'success',
      );
    } catch {
      addToast(t('agentGrant.error.save', 'That change could not be saved. Try again.'), 'error', 6000);
    } finally {
      setSaving(false);
    }
  };

  const back = (
    <Button variant="outline" asChild>
      <Link href="/admin/ai-principals">
        <Icon name="chevron-left" size={16} className="rtl:-scale-x-100" />
        {t('agentGrant.back', 'AI principals')}
      </Link>
    </Button>
  );

  if (loaded.error !== null) {
    const notYours = loaded.error === NOT_YOURS;
    return (
      <div>
        <AdminHeader title={t('agentGrant.title', 'What this agent may do')} action={back} />
        <EmptyState
          title={
            notYours
              ? t('agentGrant.notYours.title', 'Not yours to set')
              : t('agentGrant.error.title', 'Could not load')
          }
          description={
            notYours
              ? t(
                  'agentGrant.notYours.description',
                  'Only the person who issued an agent credential decides what it may do, because it acts for them.',
                )
              : loaded.error
          }
        />
      </div>
    );
  }

  return (
    <div>
      <AdminHeader
        title={grant?.name ?? t('agentGrant.title', 'What this agent may do')}
        description={t(
          'agentGrant.description',
          'Off: it can’t. Ask first: it proposes and you approve in Approvals. Allowed: it acts, and you can see what it did.',
        )}
        action={back}
      />
      {!grant ? (
        <p className="text-sm text-muted-foreground">{t('agentGrant.loading', 'Loading…')}</p>
      ) : grant.permissions.length === 0 ? (
        <EmptyState
          title={t('agentGrant.empty.title', 'Nothing to grant')}
          description={t('agentGrant.empty.description', 'You hold no permissions this agent could use.')}
        />
      ) : (
        <ScopeGrant
          agent={grant.name}
          onBehalfOf={t('agentGrant.you', 'you')}
          groups={grantGroups(grant.permissions, hint)}
          modes={Object.fromEntries(grant.permissions.map((row) => [row.permission, shownMode(row)]))}
          onChange={(permission, mode) => void change(permission, mode)}
          disabled={saving}
          labels={{
            off: modeWords.off,
            ask: modeWords.ask,
            allow: modeWords.allow,
            for: t('agentGrant.for', 'for'),
            note: t('agentGrant.note', 'The agent can never do more than you can.'),
          }}
        />
      )}
    </div>
  );
}
