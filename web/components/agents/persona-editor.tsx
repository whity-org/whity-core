'use client';

/**
 * An agent's persona: its role name, role and instructions in English and
 * Arabic, tone, language, toolsets and where a person's grant starts (WHIT-820;
 * docs/design/AGENT-PERSONAS-AND-WHITY-AGENT.md §4.2 to §4.4).
 *
 * The persona is guidance for a model. What the agent can do stays with the
 * server: the person's own permissions, their grant and proposals.
 *
 * No design card exists yet (DESIGN-ASKS.md, WHIT-821), so this is built from
 * existing components and kept apart from the pages, to swap when one lands.
 */

import { useState, type FormEvent } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Checkbox } from '@whity/ui/checkbox';
import { Input } from '@whity/ui/input';
import { ScopeGrant, type ScopeGrantMode } from '@whity/ui/scope-grant';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { Textarea } from '@whity/ui/textarea';
import { useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import {
  refusal,
  templateGroups,
  toolsetsFromSplits,
  type AgentLanguage,
  type AgentTone,
  type WorkspaceAgent,
} from './workspace-agents';

const NAME_MAX = 60;
const ROLE_MAX = 200;
const INSTRUCTIONS_MAX = 4000;

export function PersonaEditor({ agent }: { agent: WorkspaceAgent | null }) {
  const t = useTranslation('admin');
  const router = useRouter();
  const { apiClient } = useAuth();
  const { addToast } = useToast();

  const [name, setName] = useState(agent?.name ?? '');
  const [nameAr, setNameAr] = useState(agent?.nameAr ?? '');
  const [role, setRole] = useState(agent?.role ?? '');
  const [roleAr, setRoleAr] = useState(agent?.roleAr ?? '');
  const [instructions, setInstructions] = useState(agent?.instructions ?? '');
  const [instructionsAr, setInstructionsAr] = useState(agent?.instructionsAr ?? '');
  const [tone, setTone] = useState<AgentTone>(agent?.tone ?? 'plain');
  const [language, setLanguage] = useState<AgentLanguage>(agent?.language ?? 'auto');
  const [toolsets, setToolsets] = useState<string[]>(agent?.toolsets ?? []);
  const [grant, setGrant] = useState<Record<string, ScopeGrantMode>>(agent?.grant ?? {});
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const splits = useFetch<{ toolsets: string[] }[]>(async () => {
    const response = await apiClient('/api/v1/mcp/splits');
    if (!response.ok) return [];
    const body = (await response.json()) as { data?: { toolsets: string[] }[] };
    return body.data ?? [];
  }, [apiClient]);
  const offered = toolsetsFromSplits(splits.data ?? [], toolsets);

  const toggleToolset = (toolset: string, on: boolean) =>
    setToolsets((current) => (on ? [...current, toolset] : current.filter((id) => id !== toolset)));

  const save = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const response = await apiClient(agent === null ? '/api/v1/agents' : `/api/v1/agents/${agent.id}`, {
        method: agent === null ? 'POST' : 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, nameAr, role, roleAr, instructions, instructionsAr, tone, language, toolsets, grant }),
      });
      const fallback = t('agents.error.save', 'This agent could not be saved. Try again.');
      if (!response.ok) {
        setError(await refusal(response, fallback));
        return;
      }
      addToast(t('agents.saved', 'Saved'), 'success');
      if (agent === null) {
        const body = (await response.json()) as { data: WorkspaceAgent };
        router.push(`/admin/agents/${body.data.id}`);
      }
    } catch {
      setError(t('agents.error.save', 'This agent could not be saved. Try again.'));
    } finally {
      setSaving(false);
    }
  };

  const toneWords: Record<AgentTone, string> = {
    plain: t('agents.tone.plain', 'Plain'),
    formal: t('agents.tone.formal', 'Formal'),
    brief: t('agents.tone.brief', 'Brief'),
  };
  const languageWords: Record<AgentLanguage, string> = {
    auto: t('agents.language.auto', "The person's language"),
    en: t('agents.language.en', 'English'),
    ar: t('agents.language.ar', 'Arabic'),
  };

  return (
    <form onSubmit={save} className="space-y-8">
      <AdminHeader
        title={agent === null ? t('agents.new.title', 'New agent') : agent.name}
        description={t(
          'agents.editor.description',
          'Name an agent by its role, such as Finance clerk. These instructions guide the model. They never change what an agent is allowed to do.',
        )}
        action={
          <Button variant="outline" asChild>
            <Link href="/admin/agents">{t('agents.back', 'Agents')}</Link>
          </Button>
        }
      />

      <section className="grid gap-6 md:grid-cols-2">
        <div className="space-y-4">
          <h2 className="text-sm font-semibold">{t('agents.editor.english', 'English')}</h2>
          <div className="space-y-2">
            <label htmlFor="agent-name" className="block text-sm font-medium">{t('agents.field.name', 'Role name')}</label>
            <Input id="agent-name" dir="ltr" required maxLength={NAME_MAX} value={name} onChange={(e) => setName(e.target.value)} />
          </div>
          <div className="space-y-2">
            <label htmlFor="agent-role" className="block text-sm font-medium">{t('agents.field.role', 'What it does')}</label>
            <Input id="agent-role" dir="ltr" maxLength={ROLE_MAX} value={role} onChange={(e) => setRole(e.target.value)} />
          </div>
          <div className="space-y-2">
            <label htmlFor="agent-instructions" className="block text-sm font-medium">{t('agents.field.instructions', 'Instructions')}</label>
            <Textarea id="agent-instructions" dir="ltr" rows={8} maxLength={INSTRUCTIONS_MAX} value={instructions} onChange={(e) => setInstructions(e.target.value)} />
          </div>
        </div>
        <div className="space-y-4">
          <h2 className="text-sm font-semibold">{t('agents.editor.arabic', 'Arabic')}</h2>
          <div className="space-y-2">
            <label htmlFor="agent-name-ar" className="block text-sm font-medium">{t('agents.field.name', 'Role name')}</label>
            <Input id="agent-name-ar" dir="rtl" maxLength={NAME_MAX} value={nameAr} onChange={(e) => setNameAr(e.target.value)} />
          </div>
          <div className="space-y-2">
            <label htmlFor="agent-role-ar" className="block text-sm font-medium">{t('agents.field.role', 'What it does')}</label>
            <Input id="agent-role-ar" dir="rtl" maxLength={ROLE_MAX} value={roleAr} onChange={(e) => setRoleAr(e.target.value)} />
          </div>
          <div className="space-y-2">
            <label htmlFor="agent-instructions-ar" className="block text-sm font-medium">{t('agents.field.instructions', 'Instructions')}</label>
            <Textarea id="agent-instructions-ar" dir="rtl" rows={8} maxLength={INSTRUCTIONS_MAX} value={instructionsAr} onChange={(e) => setInstructionsAr(e.target.value)} />
          </div>
        </div>
      </section>

      <section className="grid gap-6 md:grid-cols-2">
        <div className="space-y-2">
          <span className="block text-sm font-medium">{t('agents.field.tone', 'Tone')}</span>
          <Select value={tone} onValueChange={(value) => setTone(value as AgentTone)}>
            <SelectTrigger aria-label={t('agents.field.tone', 'Tone')} className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {(Object.keys(toneWords) as AgentTone[]).map((id) => (
                <SelectItem key={id} value={id}>
                  {toneWords[id]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-2">
          <span className="block text-sm font-medium">{t('agents.field.language', 'Language')}</span>
          <Select value={language} onValueChange={(value) => setLanguage(value as AgentLanguage)}>
            <SelectTrigger aria-label={t('agents.field.language', 'Language')} className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {(Object.keys(languageWords) as AgentLanguage[]).map((id) => (
                <SelectItem key={id} value={id}>
                  {languageWords[id]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </section>

      <section className="space-y-3">
        <h2 className="text-sm font-semibold">{t('agents.field.toolsets', 'What it works with')}</h2>
        <p className="text-sm text-muted-foreground">
          {t('agents.toolsets.help', 'Choosing none means everything the person using it can reach.')}
        </p>
        <div className="grid gap-2 sm:grid-cols-2">
          {offered.map((toolset) => (
            <label key={toolset} className="flex items-center gap-2 text-sm">
              <Checkbox checked={toolsets.includes(toolset)} onCheckedChange={(on) => toggleToolset(toolset, on === true)} />
              <span dir="ltr" className="font-mono text-xs">
                {toolset}
              </span>
            </label>
          ))}
        </div>
      </section>

      <section className="space-y-3">
        <h2 className="text-sm font-semibold">{t('agents.field.grant', 'Where a person’s grant starts')}</h2>
        <p className="text-sm text-muted-foreground">
          {t(
            'agents.grant.help',
            'Each person who uses this agent starts here, and can change it within what they hold. Permissions not listed start at Allowed for reads and Ask first for changes.',
          )}
        </p>
        {Object.keys(grant).length > 0 && (
          <ScopeGrant
            agent={name}
            groups={templateGroups(grant, t('agents.grant.writeHint', 'A change starts at Ask first or Off. Only the person using the agent can allow it.'))}
            modes={grant}
            onChange={(permission, mode) => setGrant((current) => ({ ...current, [permission]: mode }))}
            labels={{
              off: t('agentGrant.mode.off', 'Off'),
              ask: t('agentGrant.mode.ask', 'Ask first'),
              allow: t('agentGrant.mode.allow', 'Allowed'),
              for: t('agentGrant.for', 'for'),
              note: t('agentGrant.note', 'The agent can never do more than you can.'),
            }}
          />
        )}
      </section>

      {error !== null && (
        <Alert variant="destructive">
          <AlertDescription className="whitespace-pre-line">{error}</AlertDescription>
        </Alert>
      )}

      <Button type="submit" disabled={saving || name.trim() === ''}>
        {saving ? t('agents.saving', 'Saving…') : t('agents.save', 'Save')}
      </Button>
    </form>
  );
}
