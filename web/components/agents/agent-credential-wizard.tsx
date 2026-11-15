'use client';

/**
 * A key for an agent that runs on its own, like a nightly stock check
 * (TplAgentCredential; WHIT-830, WHIT-1040). Four short steps: name it by its
 * job, who it acts for, what it may do to start, and the key, shown once.
 *
 * It always acts for the person who makes it, never as more. The starting
 * grant reads from `GET /api/mcp/tokens/starting-grant`: what the person holds
 * starts at the default (reads Allowed, everything else Ask first, nothing
 * without undo Allowed), and what they don't hold can't be chosen. The server
 * checks the same rule again when the key is made (422 `grant_above_issuer`),
 * and RBAC checks every call after that.
 */

import { useMemo, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Alert, AlertDescription, AlertTitle } from '@whity/ui/alert';
import { Avatar } from '@whity/ui/avatar';
import { Button } from '@whity/ui/button';
import { Card } from '@whity/ui/card';
import { Checkbox } from '@whity/ui/checkbox';
import { Icon } from '@whity/ui/icon';
import { Input } from '@whity/ui/input';
import { AreaError, LoadingRows } from '@whity/ui/page-states';
import { ScopeGrant, type ScopeGrantMode } from '@whity/ui/scope-grant';
import { Stepper } from '@whity/ui/stepper';
import { useDateDisplay } from '@whity/features/datetime';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { roleLabel } from '@whity/features/roles';
import { AdminHeader } from '@/components/admin/admin-header';
import { useFetch } from '@/hooks/useFetch';
import { apiFetch } from '@/lib/api/client';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { grantGroups, shownMode, type GrantMode, type GrantRow } from './agent-grant';

/** What `GET /api/mcp/tokens/starting-grant` answers (not in openapi.json, like the grant routes). */
export interface StartingGrant {
  /** The capabilities every agent key carries. */
  scope: string[];
  /** What the person holds, at the default. */
  permissions: GrantRow[];
  /** What an agent could use that the person doesn't hold. */
  notHeld: string[];
}

interface IssuedKey {
  jti: string;
  token: string;
  name: string;
  expires_at: string;
}

const LIST = '/admin/ai-principals';

/** The `grant:` entries for the rows the person moved off their default. */
export function startingEntries(rows: GrantRow[], chosen: Record<string, GrantMode>): string[] {
  return rows
    .filter((row) => chosen[row.permission] !== undefined && chosen[row.permission] !== shownMode(row))
    .map((row) => `grant:${row.permission}=${chosen[row.permission]}`);
}

/** A permission's last part in words, as the grant rows show it: `payments:make` → "Make". */
function permissionWords(permission: string): string {
  const [resource, ...rest] = permission.split(':');
  const text = (rest.join(' ') || resource).replace(/[_:-]+/g, ' ').trim().toLowerCase();
  const group = resource.replace(/[_-]+/g, ' ').trim().toLowerCase();
  const words = rest.length > 0 ? `${group}: ${text}` : text;
  return words.charAt(0).toUpperCase() + words.slice(1);
}

export function AgentCredentialWizard() {
  const t = useTranslation('admin');
  const arabic = useFormattingLocale() === 'ar';
  const dates = useDateDisplay();
  const router = useRouter();
  const { user } = useAuth();
  const { addToast } = useToast();

  const [step, setStep] = useState(0);
  const [name, setName] = useState('');
  const [nameError, setNameError] = useState<string | null>(null);
  const [chosen, setChosen] = useState<Record<string, GrantMode>>({});
  const [refused, setRefused] = useState<string | null>(null);
  const [making, setMaking] = useState(false);
  const [issued, setIssued] = useState<IssuedKey | null>(null);
  const [safe, setSafe] = useState(false);
  const [copied, setCopied] = useState(false);

  const copyKey = async (token: string) => {
    try {
      await navigator.clipboard.writeText(token);
      setCopied(true);
    } catch {
      addToast(t('agentKey.key.copyFailed', 'It could not be copied. Select it and copy it yourself.'), 'error');
    }
  };

  const starting = useFetch<StartingGrant>(async () => {
    const response = await apiFetch('/api/v1/mcp/tokens/starting-grant');
    if (!response.ok) throw new Error(t('agentKey.error.load', 'What you can give could not be loaded.'));
    return ((await response.json()) as { data: StartingGrant }).data;
  }, []);

  const modeWords: Record<GrantMode, string> = {
    off: t('agentGrant.mode.off', 'Off'),
    ask: t('agentGrant.mode.ask', 'Ask first'),
    allow: t('agentGrant.mode.allow', 'Allowed'),
  };

  const rows = useMemo<GrantRow[]>(() => {
    if (!starting.data) return [];
    const notHeld: GrantRow[] = starting.data.notHeld.map((permission) => ({
      permission,
      mode: 'off',
      modes: ['off'],
      withheld: {},
      effective: { read: null, write: null },
    }));
    return [...starting.data.permissions, ...notHeld].sort((a, b) => a.permission.localeCompare(b.permission));
  }, [starting.data]);
  const notHeld = useMemo(() => new Set(starting.data?.notHeld ?? []), [starting.data]);

  const hint = (row: GrantRow): string | undefined => {
    if (notHeld.has(row.permission)) return t('agentKey.grant.notHeld', 'You don’t have this, so you can’t give it.');
    if (!row.modes.includes('allow') && row.modes.includes('ask')) {
      return t('agentKey.grant.noUndo', 'Can’t be undone, so it always asks first.');
    }
    return undefined;
  };

  const modes = Object.fromEntries(rows.map((row) => [row.permission, chosen[row.permission] ?? shownMode(row)]));

  const person = user?.display_name || user?.email || '';
  const role = user?.role ? roleLabel(user.role) : '';

  const steps = [
    { label: t('agentKey.step.name', 'Name it') },
    { label: t('agentKey.step.actsFor', 'Who it acts for') },
    { label: t('agentKey.step.grant', 'What it may do') },
    { label: t('agentKey.step.key', 'Copy the key') },
  ];

  const next = () => {
    if (step === 0 && name.trim() === '') {
      setNameError(t('agentKey.name.required', 'Give it a name, so people know what it does.'));
      return;
    }
    setNameError(null);
    setStep(step + 1);
  };

  const make = async () => {
    if (!starting.data) return;
    setMaking(true);
    setRefused(null);
    try {
      const response = await apiFetch('/api/v1/mcp/tokens', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: name.trim(),
          agent: true,
          scope: [...starting.data.scope, ...startingEntries(starting.data.permissions, chosen)],
        }),
      });
      const body = (await response.json().catch(() => null)) as (IssuedKey & { error?: string; code?: string }) | null;
      if (!response.ok || body === null || !body.token) {
        if (response.status === 422 && body?.code === 'grant_above_issuer') {
          setRefused(body.error ?? null);
          return;
        }
        addToast(body?.error ?? t('agentKey.error.make', 'The key could not be made. Try again.'), 'error', 6000);
        return;
      }
      setIssued(body);
      setStep(3);
    } catch {
      addToast(t('agentKey.error.make', 'The key could not be made. Try again.'), 'error', 6000);
    } finally {
      setMaking(false);
    }
  };

  const unheld = [...notHeld];
  const shownUnheld = unheld.slice(0, 4).map(permissionWords);
  const footer = (main: React.ReactNode, back = true) => (
    <div className="flex flex-wrap items-center gap-2">
      {back ? (
        <Button variant="ghost" onClick={() => setStep(step - 1)} disabled={making}>
          {t('agentKey.back', 'Back')}
        </Button>
      ) : (
        <Button variant="ghost" asChild>
          <Link href={LIST}>{t('agentKey.cancel', 'Cancel')}</Link>
        </Button>
      )}
      <span className="flex-1" />
      {main}
    </div>
  );

  let body: React.ReactNode;
  if (step === 0) {
    body = (
      <>
        <h2 className="text-lg font-semibold">{t('agentKey.name.title', 'Name it by its job')}</h2>
        <Input
          label={t('agentKey.name.label', 'Name')}
          value={name}
          maxLength={255}
          autoFocus
          placeholder={t('agentKey.name.placeholder', 'Nightly stock check')}
          onChange={(event) => setName(event.target.value)}
          onKeyDown={(event) => event.key === 'Enter' && next()}
          helperText={t('agentKey.name.help', 'People see this name next to everything it does.')}
          errorText={nameError ?? undefined}
        />
        <p className="text-sm text-muted-foreground">
          {t('agentKey.expires', 'It stops working after 90 days. Make a new key before then.')}
        </p>
        {footer(<Button onClick={next}>{t('agentKey.next', 'Next')}</Button>, false)}
      </>
    );
  } else if (step === 1) {
    body = (
      <>
        <h2 className="text-lg font-semibold">{t('agentKey.actsFor.title', 'Who it acts for')}</h2>
        <div className="flex items-center gap-3">
          <Avatar name={person} size={40} />
          <div className="min-w-0">
            <p className="font-semibold">{t('agentKey.actsFor.you', 'You, {name}', { name: person })}</p>
            <p className="text-sm text-muted-foreground">
              {role
                ? t('agentKey.actsFor.roleLine', '{role} · it can never do more than you can', { role })
                : t('agentKey.actsFor.line', 'It can never do more than you can')}
            </p>
          </div>
        </div>
        <p className="text-sm text-muted-foreground">
          {t('agentKey.actsFor.help', 'Keys always act for the person who makes them. To act for someone else, they make the key.')}
        </p>
        {footer(<Button onClick={next}>{t('agentKey.next', 'Next')}</Button>)}
      </>
    );
  } else if (step === 2) {
    body = (
      <>
        <h2 className="text-lg font-semibold">{t('agentKey.grant.title', 'What it may do to start')}</h2>
        <p className="text-muted-foreground">
          {t(
            'agentKey.grant.description',
            'Reads you hold start Allowed. Everything else starts at Ask first. Anything that can’t be undone can never be Allowed.',
          )}
        </p>
        {starting.error !== null ? (
          <AreaError message={starting.error} retryLabel={t('agents.retry', 'Try again')} onRetry={starting.refetch} />
        ) : starting.loading || !starting.data ? (
          <LoadingRows headers={[t('agentKey.grant.permission', 'Permission')]} label={t('agents.loading', 'Loading…')} />
        ) : (
          <>
            <ScopeGrant
              className="max-w-none"
              agent={name.trim()}
              onBehalfOf={person}
              groups={grantGroups(rows, hint)}
              modes={modes}
              onChange={(permission, mode: ScopeGrantMode) => setChosen({ ...chosen, [permission]: mode })}
              disabled={making}
              labels={{
                off: modeWords.off,
                ask: modeWords.ask,
                allow: modeWords.allow,
                for: t('agentGrant.for', 'for'),
                note: t('agentKey.grant.note', 'It can never do more than you can.'),
              }}
            />
            {unheld.length > 0 && (
              <Alert variant="warning">
                <AlertTitle>
                  {unheld.length === 1
                    ? t('agentKey.notHeld.titleOne', 'You can’t give “{name}”', { name: shownUnheld[0] })
                    : t('agentKey.notHeld.titleMany', 'You can’t give {n} of these', { n: unheld.length })}
                </AlertTitle>
                <AlertDescription>
                  {unheld.length > 1 && (
                    <span className="block">
                      {unheld.length > shownUnheld.length
                        ? t('agentKey.notHeld.listMore', '{list} and {n} more.', {
                            list: shownUnheld.join(arabic ? '، ' : ', '),
                            n: unheld.length - shownUnheld.length,
                          })
                        : `${shownUnheld.join(arabic ? '، ' : ', ')}.`}
                    </span>
                  )}
                  {t(
                    'agentKey.notHeld.description',
                    'You don’t have these yourself, so this agent can’t either. An owner, or someone who manages roles, can give them to you first.',
                  )}
                </AlertDescription>
              </Alert>
            )}
            {refused !== null && (
              <Alert variant="destructive">
                <AlertTitle>{t('agentKey.refused.title', 'That’s more than you can give')}</AlertTitle>
                <AlertDescription>{refused}</AlertDescription>
              </Alert>
            )}
          </>
        )}
        {footer(
          <Button onClick={() => void make()} loading={making} disabled={making || !starting.data}>
            {t('agentKey.make', 'Make the key')}
          </Button>,
        )}
      </>
    );
  } else if (issued !== null) {
    const made = dates.date(new Date().toISOString()) ?? '';
    const expires = dates.date(issued.expires_at) ?? '';
    body = (
      <>
        <h2 className="text-lg font-semibold">{t('agentKey.key.title', 'Copy the key now')}</h2>
        <Alert variant="warning">
          <AlertTitle>{t('agentKey.key.onceTitle', 'You’ll only see it once')}</AlertTitle>
          <AlertDescription>
            {t('agentKey.key.onceDescription', 'Put it where the agent runs. If you lose it, revoke it and make a new one.')}
          </AlertDescription>
        </Alert>
        <div className="flex flex-wrap items-center gap-3 rounded-control border border-border bg-muted p-3">
          <code dir="ltr" className="min-w-0 flex-1 font-mono text-sm break-all text-foreground">
            {issued.token}
          </code>
          <Button size="sm" variant="outline" onClick={() => void copyKey(issued.token)}>
            <Icon name={copied ? 'check' : 'copy'} size={16} />
            {copied ? t('aiPrincipals.connect.copied', 'Copied') : t('agentKey.key.copy', 'Copy key')}
          </Button>
        </div>
        <p className="text-sm text-muted-foreground">
          {t('agentKey.key.summary', '{name} · acts for {person} · made {made} · stops working {expires}', {
            name: issued.name,
            person,
            made,
            expires,
          })}
        </p>
        <label className="flex items-center gap-3">
          <Checkbox checked={safe} onCheckedChange={(value) => setSafe(value === true)} />
          <span>{t('agentKey.key.safe', 'I’ve put the key somewhere safe')}</span>
        </label>
        <div className="flex items-center gap-2">
          <span className="flex-1" />
          <Button disabled={!safe} onClick={() => router.push(LIST)}>
            {t('agentKey.key.done', 'Done')}
          </Button>
        </div>
      </>
    );
  }

  return (
    <div className="space-y-6">
      <AdminHeader
        breadcrumb={
          <nav aria-label={t('agentKey.breadcrumb', 'Breadcrumb')} className="flex flex-wrap items-center gap-1">
            <Link href={LIST} className="hover:underline">
              {t('aiPrincipals.title', 'Connected AI apps')}
            </Link>
            <Icon name={arabic ? 'chevron-left' : 'chevron-right'} size={12} />
            <span aria-current="page" className="text-foreground">
              {t('agentKey.crumb', 'New key')}
            </span>
          </nav>
        }
        title={t('agentKey.title', 'New key for an unattended agent')}
        description={t(
          'agentKey.description',
          'For an agent that runs on its own, like a nightly stock check. It acts as the person who makes the key, never as more.',
        )}
      />
      <div className="grid items-start gap-6 md:grid-cols-[220px_minmax(0,1fr)]">
        <Stepper
          label={t('agentKey.steps', 'New key')}
          orientation="vertical"
          steps={steps}
          current={step}
          stepOfLabel={(n, total) => t('aiPrincipals.connect.stepOf', 'Step {n} of {total}', { n, total })}
        />
        <Card className="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-4 p-6">{body}</Card>
      </div>
    </div>
  );
}
