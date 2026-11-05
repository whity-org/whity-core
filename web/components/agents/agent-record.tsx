'use client';

/**
 * A workspace agent's record page (A21, AI.3; TplAgentRecord, TplAgentInstructions,
 * TplAgentScope): header with who it is, See activity and ⋯, then four tabs
 * (Overview, Instructions, What it may do, Activity). Edits collect in a save
 * bar and nothing is saved until Save changes (P.3).
 *
 * "Where it works" holds what it knows: how it writes. The card's apps and
 * channels are left out, because an agent has no list of them to read.
 */

import { useState } from 'react';
import Link from 'next/link';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Avatar } from '@whity/ui/avatar';
import { Button } from '@whity/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@whity/ui/dropdown-menu';
import { Icon } from '@whity/ui/icon';
import { Input } from '@whity/ui/input';
import { SaveBar, ChangedField } from '@whity/ui/save-bar';
import { ScopeGrant, type ScopeGrantMode } from '@whity/ui/scope-grant';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { Textarea } from '@whity/ui/textarea';
import { useDateDisplay } from '@whity/features/datetime';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { ToolsetPicker } from '@/components/agents/toolset-picker';
import { AgentStatusBadge } from '@/components/agents/agent-status';
import { MayReadPicker } from '@/components/agents/may-read-picker';
import { WorkspaceActivityPanel } from '@/components/agents/workspace-activity-panel';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import {
  localized,
  refusal,
  startOfToday,
  templateGroups,
  toolsetsFromSplits,
  type AgentLanguage,
  type AgentPersona,
  type AgentTone,
  type WorkspaceAgent,
} from './workspace-agents';

const NAME_MAX = 60;
const ROLE_MAX = 200;
const INSTRUCTIONS_MAX = 4000;

type AgentDraft = AgentPersona & { mayRead: number[] };

const personaOf = (agent: WorkspaceAgent): AgentDraft => ({
  name: agent.name,
  nameAr: agent.nameAr ?? '',
  role: agent.role ?? '',
  roleAr: agent.roleAr ?? '',
  instructions: agent.instructions ?? '',
  instructionsAr: agent.instructionsAr ?? '',
  tone: agent.tone,
  language: agent.language,
  toolsets: [...agent.toolsets],
  grant: { ...agent.grant },
  mayRead: agent.mayRead.map((source) => source.id),
});

const same = (a: unknown, b: unknown): boolean => JSON.stringify(a) === JSON.stringify(b);

export function AgentRecord({ agent: initial, onChanged }: { agent: WorkspaceAgent; onChanged: () => void }) {
  const t = useTranslation('admin');
  const arabic = useFormattingLocale() === 'ar';
  const { apiClient } = useAuth();
  const { addToast } = useToast();

  const dates = useDateDisplay();
  const [saved, setSaved] = useState<AgentDraft>(() => personaOf(initial));
  const [draft, setDraft] = useState<AgentDraft>(() => personaOf(initial));
  const [today] = useState(() => startOfToday());
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState('overview');

  const splits = useFetch<{ toolsets: string[] }[]>(async () => {
    const response = await apiClient('/api/v1/mcp/splits');
    if (!response.ok) return [];
    return ((await response.json()) as { data?: { toolsets: string[] }[] }).data ?? [];
  }, [apiClient]);
  const offered = toolsetsFromSplits(splits.data ?? [], draft.toolsets);

  const set = <K extends keyof AgentDraft>(key: K, value: AgentDraft[K]) => setDraft((current) => ({ ...current, [key]: value }));
  const changed = (...keys: (keyof AgentDraft)[]) => keys.some((key) => !same(draft[key], saved[key]));
  const was = (key: keyof AgentDraft) => (typeof saved[key] === 'string' && saved[key] !== '' ? t('agentRecord.was', 'Was {value}', { value: String(saved[key]) }) : undefined);

  const sections = [
    changed('name', 'nameAr', 'role', 'roleAr') && t('agentRecord.changed.name', 'Name and job'),
    changed('instructions', 'instructionsAr', 'tone', 'language', 'toolsets', 'mayRead') && t('agentRecord.changed.instructions', 'Instructions'),
    changed('grant') && t('agentRecord.changed.grant', 'What it may do'),
  ].filter((name): name is string => typeof name === 'string');
  const dirty = sections.length > 0;

  const save = async () => {
    setSaving(true);
    setError(null);
    try {
      const response = await apiClient(`/api/v1/agents/${initial.id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(draft),
      });
      if (!response.ok) {
        setError(await refusal(response, t('agents.error.save', 'This agent could not be saved. Try again.')));
        return;
      }
      setSaved(draft);
      addToast(t('agents.saved', 'Saved'), 'success');
      onChanged();
    } catch {
      setError(t('agents.error.save', 'This agent could not be saved. Try again.'));
    } finally {
      setSaving(false);
    }
  };

  const setArchived = async (archived: boolean) => {
    const response = await apiClient(`/api/v1/agents/${initial.id}/${archived ? 'archive' : 'restore'}`, { method: 'POST' });
    if (!response.ok) {
      addToast(await refusal(response, t('agents.error.save', 'This agent could not be saved. Try again.')), 'error');
      return;
    }
    const name = localized(saved.name, saved.nameAr, arabic);
    addToast(
      archived ? t('agents.archived', '{name} archived. Everyone using it has stopped.', { name }) : t('agents.restored', '{name} restored.', { name }),
      'success',
      8000,
      archived ? { label: t('agents.undo', 'Undo'), onClick: () => void setArchived(false) } : undefined,
    );
    onChanged();
  };

  const setPaused = async (paused: boolean) => {
    const response = await apiClient(`/api/v1/agents/${initial.id}/${paused ? 'pause' : 'resume'}`, { method: 'POST' });
    if (!response.ok) {
      addToast(await refusal(response, t('agents.error.save', 'This agent could not be saved. Try again.')), 'error');
      return;
    }
    const name = localized(saved.name, saved.nameAr, arabic);
    addToast(
      paused
        ? t('agents.paused', '{name} is paused. It can’t do anything until it is resumed.', { name })
        : t('agents.resumed', '{name} is working again.', { name }),
      'success',
      8000,
      paused ? { label: t('agents.undo', 'Undo'), onClick: () => void setPaused(false) } : undefined,
    );
    onChanged();
  };

  const displayName = localized(saved.name, saved.nameAr, arabic);
  const people = initial.worksFor.map((who) => who.name ?? t('agents.worksFor.unknown', 'Someone'));
  const since = dates.date(initial.createdAt);
  const changedOn = dates.date(initial.updatedAt);
  const toneWords: Record<AgentTone, string> = {
    plain: t('agents.tone.plain', 'Plain'),
    formal: t('agents.tone.formal', 'Formal'),
    brief: t('agents.tone.brief', 'Brief'),
  };
  const languageWords: Record<AgentLanguage, string> = {
    auto: t('agents.language.auto', 'The language of the person using it'),
    en: t('agents.language.en', 'English'),
    ar: t('agents.language.ar', 'Arabic'),
  };
  const grantCount = Object.keys(draft.grant).length;

  return (
    <div className="space-y-6">
      <nav aria-label={t('agentRecord.breadcrumb', 'Breadcrumb')} className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <Link href="/admin/agents" className="hover:text-foreground hover:underline">
          {t('agents.title', 'Agents')}
        </Link>
        <Icon name="chevron-right" size={14} className="rtl:-scale-x-100" />
        <span aria-current="page" className="text-foreground">
          {displayName}
        </span>
      </nav>

      <header className="flex flex-wrap items-center gap-4">
        <Avatar kind="agent" name={displayName} size={56} />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="text-3xl font-semibold">{displayName}</h1>
            <AgentStatusBadge status={initial.status} paused={initial.paused} />
          </div>
          <p className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
            <span className="inline-flex items-center gap-1.5">
              <Icon name="user" size={14} />
              {people.length > 0 ? t('agentRecord.worksFor', 'Works for {names}', { names: people.join(', ') }) : t('agents.worksFor.nobody', 'Nobody yet')}
            </span>
            <span className="inline-flex items-center gap-1.5">
              <Icon name="activity" size={14} />
              {t('agentRecord.today', '{n} actions today', { n: initial.actionsToday })}
            </span>
            {since !== null && (
              <span className="inline-flex items-center gap-1.5">
                <Icon name="clock" size={14} />
                {t('agentRecord.since', 'Since {date}', { date: since })}
              </span>
            )}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" asChild>
            <Link href={`/admin/agent-activity?agent=${initial.id}`}>
              <Icon name="activity" size={16} />
              {t('myAgents.seeActivity', 'See activity')}
            </Link>
          </Button>
          {initial.status === 'active' && (
            <Button variant="outline" onClick={() => void setPaused(!initial.paused)}>
              {initial.paused ? t('agents.action.resume', 'Resume') : t('agents.action.pause', 'Pause')}
            </Button>
          )}
          {initial.status === 'archived' && (
            <Button variant="outline" onClick={() => void setArchived(false)}>
              {t('agents.action.restore', 'Restore')}
            </Button>
          )}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="icon" aria-label={t('agents.row.menu', 'More actions')}>
                <Icon name="more" size={16} />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {initial.status === 'archived' ? (
                <DropdownMenuItem onClick={() => void setArchived(false)}>{t('agents.action.restore', 'Restore')}</DropdownMenuItem>
              ) : (
                <DropdownMenuItem onClick={() => void setArchived(true)}>{t('agents.action.archive', 'Archive')}</DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </header>

      {initial.paused && initial.status === 'active' && (
        <Alert>
          <AlertDescription>
            {initial.pausedBy !== null
              ? t('agentRecord.paused.by', '{name} paused this agent. It is refused on every call, and what it proposed can’t run until it is resumed.', { name: initial.pausedBy })
              : t('agentRecord.paused', 'This agent is paused. It is refused on every call, and what it proposed can’t run until it is resumed.')}
          </AlertDescription>
        </Alert>
      )}

      <Tabs value={tab} onValueChange={setTab}>
        <TabsList aria-label={displayName}>
          <TabsTrigger value="overview">{t('agentRecord.tab.overview', 'Overview')}</TabsTrigger>
          <TabsTrigger value="instructions">{t('agentRecord.tab.instructions', 'Instructions')}</TabsTrigger>
          <TabsTrigger value="scope">
            {t('agentRecord.tab.scope', 'What it may do')}
            {grantCount > 0 && <span className="rounded-full bg-muted px-2 text-xs">{grantCount}</span>}
          </TabsTrigger>
          <TabsTrigger value="activity">{t('agentRecord.tab.activity', 'Activity')}</TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="mt-6 grid gap-4 md:grid-cols-[2fr_1fr]">
          <section className="space-y-3 rounded-card border border-border bg-card p-5">
            <h2 className="text-base font-semibold">{t('agentRecord.does', 'What it does')}</h2>
            <p className="text-sm">{localized(saved.role, saved.roleAr, arabic) || t('agentRecord.does.empty', 'No description yet. Add one in Instructions.')}</p>
          </section>
          <section className="space-y-3 rounded-card border border-border bg-card p-5">
            <h2 className="text-base font-semibold">{t('agentRecord.onItsOwn', 'On its own')}</h2>
            <dl className="divide-y divide-divider text-sm">
              <div className="flex justify-between gap-4 py-2">
                <dt className="text-muted-foreground">{t('agentGrant.mode.allow', 'Allowed')}</dt>
                <dd>{t('agentRecord.onItsOwn.count', '{n} actions', { n: initial.onItsOwn.allowed })}</dd>
              </div>
              <div className="flex justify-between gap-4 py-2">
                <dt className="text-muted-foreground">{t('agentRecord.onItsOwn.ask', 'Asks first')}</dt>
                <dd>{t('agentRecord.onItsOwn.count', '{n} actions', { n: initial.onItsOwn.askFirst })}</dd>
              </div>
              <div className="flex justify-between gap-4 py-2">
                <dt className="text-muted-foreground">{t('agentRecord.onItsOwn.never', 'Never')}</dt>
                <dd>{t('agentRecord.onItsOwn.count', '{n} actions', { n: initial.onItsOwn.off })}</dd>
              </div>
            </dl>
          </section>
          <section className="space-y-3 rounded-card border border-border bg-card p-5">
            <div>
              <h2 className="text-base font-semibold">{t('agentRecord.todayTitle', 'Today')}</h2>
              <p className="text-sm text-muted-foreground">
                {t('agentRecord.todaySummary', '{done} done · {waiting} waiting for a person', { done: initial.actionsToday, waiting: initial.waiting })}
              </p>
            </div>
            <WorkspaceActivityPanel agentId={initial.id} since={today} compact />
          </section>
          <section className="space-y-3 rounded-card border border-border bg-card p-5">
            <h2 className="text-base font-semibold">{t('agentRecord.where', 'How it works')}</h2>
            <dl className="divide-y divide-divider text-sm">
              <div className="flex justify-between gap-4 py-2">
                <dt className="text-muted-foreground">{t('agents.field.tone', 'Tone')}</dt>
                <dd>{toneWords[saved.tone]}</dd>
              </div>
              <div className="flex justify-between gap-4 py-2">
                <dt className="text-muted-foreground">{t('agentRecord.writesIn', 'Writes in')}</dt>
                <dd>{languageWords[saved.language]}</dd>
              </div>
            </dl>
          </section>
        </TabsContent>

        <TabsContent value="instructions" className="mt-6 space-y-6">
          <p className="max-w-prose text-sm text-muted-foreground">
            {t('agentRecord.instructions.help', 'Write as you would to a new colleague. Instructions guide the agent. They never widen what it may do: the What it may do tab decides that.')}
            {initial.updatedBy !== null && changedOn !== null && (
              <span className="mt-1 block">{t('agentRecord.changedBy', 'Changed {date} by {name}', { date: changedOn, name: initial.updatedBy })}</span>
            )}
          </p>
          <div className="grid gap-6 md:grid-cols-2">
            <div className="space-y-4">
              <h2 className="text-sm font-semibold">{t('agents.editor.english', 'English')}</h2>
              <ChangedField changed={changed('name')} wasLabel={was('name')}>
                <label htmlFor="agent-name" className="block text-sm font-medium">{t('agents.field.name', 'Role name')}</label>
                <Input id="agent-name" dir="ltr" maxLength={NAME_MAX} value={draft.name} onChange={(e) => set('name', e.target.value)} />
              </ChangedField>
              <ChangedField changed={changed('role')} wasLabel={was('role')}>
                <label htmlFor="agent-role" className="block text-sm font-medium">{t('agents.field.role', 'What it does')}</label>
                <Input id="agent-role" dir="ltr" maxLength={ROLE_MAX} value={draft.role ?? ''} onChange={(e) => set('role', e.target.value)} />
              </ChangedField>
              <ChangedField changed={changed('instructions')}>
                <label htmlFor="agent-instructions" className="block text-sm font-medium">{t('agents.field.instructions', 'Instructions')}</label>
                <Textarea id="agent-instructions" dir="ltr" rows={10} maxLength={INSTRUCTIONS_MAX} value={draft.instructions ?? ''} onChange={(e) => set('instructions', e.target.value)} />
              </ChangedField>
            </div>
            <div className="space-y-4">
              <h2 className="text-sm font-semibold">{t('agents.editor.arabic', 'Arabic')}</h2>
              <ChangedField changed={changed('nameAr')}>
                <label htmlFor="agent-name-ar" className="block text-sm font-medium">{t('agents.field.name', 'Role name')}</label>
                <Input id="agent-name-ar" dir="rtl" maxLength={NAME_MAX} value={draft.nameAr ?? ''} onChange={(e) => set('nameAr', e.target.value)} />
              </ChangedField>
              <ChangedField changed={changed('roleAr')}>
                <label htmlFor="agent-role-ar" className="block text-sm font-medium">{t('agents.field.role', 'What it does')}</label>
                <Input id="agent-role-ar" dir="rtl" maxLength={ROLE_MAX} value={draft.roleAr ?? ''} onChange={(e) => set('roleAr', e.target.value)} />
              </ChangedField>
              <ChangedField changed={changed('instructionsAr')}>
                <label htmlFor="agent-instructions-ar" className="block text-sm font-medium">{t('agents.field.instructions', 'Instructions')}</label>
                <Textarea id="agent-instructions-ar" dir="rtl" rows={10} maxLength={INSTRUCTIONS_MAX} value={draft.instructionsAr ?? ''} onChange={(e) => set('instructionsAr', e.target.value)} />
              </ChangedField>
            </div>
          </div>

          <section className="grid gap-6 md:grid-cols-2">
            <ChangedField changed={changed('tone')}>
              <span className="block text-sm font-medium">{t('agents.field.tone', 'Tone')}</span>
              <Select value={draft.tone} onValueChange={(value) => set('tone', value as AgentTone)}>
                <SelectTrigger aria-label={t('agents.field.tone', 'Tone')} className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {(Object.keys(toneWords) as AgentTone[]).map((id) => (
                    <SelectItem key={id} value={id}>{toneWords[id]}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </ChangedField>
            <ChangedField changed={changed('language')}>
              <span className="block text-sm font-medium">{t('agents.field.language', 'Language')}</span>
              <Select value={draft.language} onValueChange={(value) => set('language', value as AgentLanguage)}>
                <SelectTrigger aria-label={t('agents.field.language', 'Language')} className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {(Object.keys(languageWords) as AgentLanguage[]).map((id) => (
                    <SelectItem key={id} value={id}>{languageWords[id]}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </ChangedField>
          </section>

          <ChangedField changed={changed('mayRead')}>
            <MayReadPicker chosen={draft.mayRead} known={initial.mayRead} onChange={(ids) => set('mayRead', ids)} />
          </ChangedField>

          <ToolsetPicker offered={offered} chosen={draft.toolsets} onChange={(toolsets) => set('toolsets', toolsets)} />
        </TabsContent>

        <TabsContent value="scope" className="mt-6 space-y-4">
          <p className="max-w-prose text-sm text-muted-foreground">
            {t('agents.grant.help', 'Each person who uses this agent starts here, and can change it within what they hold. Permissions not listed start at Allowed for reads and Ask first for changes.')}
          </p>
          {grantCount === 0 ? (
            <p className="text-sm text-muted-foreground">{t('agentRecord.scope.empty', 'This agent has no starting choices. Reads start at Allowed and changes at Ask first.')}</p>
          ) : (
            <ChangedField changed={changed('grant')}>
              <ScopeGrant
                agent={draft.name}
                groups={templateGroups(draft.grant, t('agents.grant.writeHint', 'A change starts at Ask first or Off. Only the person using the agent can allow it.'))}
                modes={draft.grant}
                onChange={(permission, mode: ScopeGrantMode) => set('grant', { ...draft.grant, [permission]: mode })}
                labels={{
                  off: t('agentGrant.mode.off', 'Off'),
                  ask: t('agentGrant.mode.ask', 'Ask first'),
                  allow: t('agentGrant.mode.allow', 'Allowed'),
                  for: t('agentGrant.for', 'for'),
                  note: t('agentGrant.note', 'The agent can never do more than you can.'),
                }}
              />
            </ChangedField>
          )}
        </TabsContent>

        <TabsContent value="activity" className="mt-6">
          <WorkspaceActivityPanel agentId={initial.id} />
        </TabsContent>
      </Tabs>

      {error !== null && (
        <Alert variant="destructive">
          <AlertDescription className="whitespace-pre-line">{error}</AlertDescription>
        </Alert>
      )}

      <SaveBar
        dirty={dirty}
        summary={t('agentRecord.changed', '{what} changed', { what: sections.join(', ') })}
        discardLabel={t('agentRecord.discard', 'Discard')}
        saveLabel={t('agentRecord.save', 'Save changes')}
        saving={saving}
        onDiscard={() => {
          setDraft(saved);
          setError(null);
        }}
        onSave={() => void save()}
      />
    </div>
  );
}
