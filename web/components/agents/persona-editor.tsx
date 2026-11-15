'use client';

/**
 * An agent's persona: its role name, role and instructions in English and
 * Arabic, tone, language, toolsets and where a person's grant starts (WHIT-820;
 * docs/design/AGENT-PERSONAS-AND-WHITY-AGENT.md §4.2 to §4.4).
 *
 * The persona is guidance for a model. What the agent can do stays with the
 * server: the person's own permissions, their grant and proposals.
 *
 * Card `TplAgentPersona` (WHIT-821): who it is, the instructions side by side
 * with a 4,000-character count each, then how it writes and what it uses.
 */

import { useState, type FormEvent } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Card } from '@whity/ui/card';
import { Icon } from '@whity/ui/icon';
import { Input } from '@whity/ui/input';
import { ScopeGrant, type ScopeGrantMode } from '@whity/ui/scope-grant';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { Textarea } from '@whity/ui/textarea';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { useTranslation } from '@whity/features/i18n';
import { ToolsetPicker } from '@/components/agents/toolset-picker';
import { AdminHeader } from '@/components/admin/admin-header';
import { useFetch } from '@/hooks/useFetch';
import { api, apiFetch } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import {
  refusalText,
  templateGroups,
  toolsetsFromSplits,
  type AgentLanguage,
  type AgentTone,
  type WorkspaceAgent,
} from './workspace-agents';

const NAME_MAX = 60;
const ROLE_MAX = 200;
/** Counted, not cut off: the person sees how far over they are. */
const INSTRUCTIONS_MAX = 4000;

export function PersonaEditor({ agent }: { agent: WorkspaceAgent | null }) {
  const t = useTranslation('admin');
  const router = useRouter();
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
  const [nameTouched, setNameTouched] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const splits = useFetch<{ toolsets: string[] }[]>(async () => {
    const response = await apiFetch('/api/v1/mcp/splits');
    if (!response.ok) return [];
    const body = (await response.json()) as { data?: { toolsets: string[] }[] };
    return body.data ?? [];
  }, []);
  const offered = toolsetsFromSplits(splits.data ?? [], toolsets);

  const tooLong = (text: string) => text.length > INSTRUCTIONS_MAX;
  const nameMissing = name.trim() === '';
  const invalid = nameMissing || tooLong(instructions) || tooLong(instructionsAr);

  const save = async (event: FormEvent) => {
    event.preventDefault();
    setNameTouched(true);
    if (invalid) return;
    setSaving(true);
    setError(null);
    try {
      const persona = { name, nameAr, role, roleAr, instructions, instructionsAr, tone, language, toolsets, grant } as never;
      const { data, error, response } =
        agent === null
          ? await api.POST('/api/v1/agents', { body: persona })
          : await api.PATCH('/api/v1/agents/{id}', { params: { path: { id: agent.id } }, body: persona });
      const fallback = t('agents.error.save', 'This agent could not be saved. Try again.');
      if (!response.ok) {
        setError(refusalText(error, fallback));
        return;
      }
      addToast(t('agents.saved', 'Saved'), 'success');
      if (agent === null) {
        const body = data as unknown as { data: WorkspaceAgent };
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
    auto: t('agents.language.auto', 'The language of the person using it'),
    en: t('agents.language.en', 'English'),
    ar: t('agents.language.ar', 'Arabic'),
  };

  /** "189 / 4000" under each box; over the limit, a sentence that says what to do. */
  const counter = (text: string, id: string) =>
    tooLong(text) ? (
      <p id={id} className="text-sm text-error-text">
        {t('agents.instructions.tooLong', '{count} of {max} characters. Shorten it a little.')
          .replace('{count}', text.length.toLocaleString('en'))
          .replace('{max}', INSTRUCTIONS_MAX.toLocaleString('en'))}
      </p>
    ) : (
      <p id={id} className="text-end text-sm text-muted-foreground tabular-nums">
        <span dir="ltr">
          {text.length} / {INSTRUCTIONS_MAX}
        </span>
      </p>
    );

  const title = agent === null ? t('agents.new.title', 'New agent') : agent.name;

  return (
    <form onSubmit={save} className="space-y-6" noValidate>
      <AdminHeader
        breadcrumb={
          <nav aria-label={t('agents.breadcrumb', 'Breadcrumb')} className="flex flex-wrap items-center gap-1">
            <Link href="/admin/agents" className="hover:underline">
              {t('agents.back', 'Agents')}
            </Link>
            <Icon name="chevron-right" size={12} className="rtl:-scale-x-100" aria-hidden />
            <span aria-current="page" className="text-foreground">
              {title}
            </span>
          </nav>
        }
        title={title}
        description={t(
          'agents.editor.description',
          'Name an agent by its role, such as Finance clerk. These instructions guide the model. They never change what an agent is allowed to do.',
        )}
        action={
          <Button type="submit" disabled={saving || (nameTouched && invalid)}>
            {saving ? t('agents.saving', 'Saving…') : t('agents.save', 'Save')}
          </Button>
        }
      />

      {error !== null && (
        <Alert variant="destructive">
          <AlertDescription className="whitespace-pre-line">{error}</AlertDescription>
        </Alert>
      )}

      <Card className="gap-4 p-4">
        <h2 className="text-base font-semibold">{t('agents.section.who', 'Who it is')}</h2>
        <div className="grid gap-4 md:grid-cols-2">
          <div className="space-y-1.5">
            <label htmlFor="agent-name" className="block text-sm font-medium">
              {t('agents.field.name', 'Role name')}
            </label>
            <Input
              id="agent-name"
              dir="ltr"
              aria-required="true"
              maxLength={NAME_MAX}
              value={name}
              aria-invalid={nameTouched && nameMissing ? true : undefined}
              aria-describedby="agent-name-help"
              onBlur={() => setNameTouched(true)}
              onChange={(e) => setName(e.target.value)}
            />
            {nameTouched && nameMissing ? (
              <p id="agent-name-help" className="text-sm text-error-text">
                {t('agents.name.required', 'Give it a name people will recognise.')}
              </p>
            ) : (
              <p id="agent-name-help" className="text-sm text-muted-foreground">
                {t('agents.name.help', 'Shown next to everything it does.')}
              </p>
            )}
          </div>
          <div className="space-y-1.5">
            <label htmlFor="agent-role" className="block text-sm font-medium">
              {t('agents.field.roleLine', 'Role, in one line')}
            </label>
            <Input id="agent-role" dir="ltr" maxLength={ROLE_MAX} value={role} onChange={(e) => setRole(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <label htmlFor="agent-name-ar" className="block text-sm font-medium">
              {t('agents.field.nameAr', 'Role name in Arabic')}
            </label>
            <Input id="agent-name-ar" dir="rtl" maxLength={NAME_MAX} value={nameAr} onChange={(e) => setNameAr(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <label htmlFor="agent-role-ar" className="block text-sm font-medium">
              {t('agents.field.roleLineAr', 'Role in Arabic, in one line')}
            </label>
            <Input id="agent-role-ar" dir="rtl" maxLength={ROLE_MAX} value={roleAr} onChange={(e) => setRoleAr(e.target.value)} />
          </div>
        </div>
      </Card>

      <Card className="gap-4 p-4">
        <div>
          <h2 className="text-base font-semibold">{t('agents.field.instructions', 'Instructions')}</h2>
          <p className="text-sm text-muted-foreground">
            {t(
              'agents.instructions.help',
              'What it should do and how, in plain words. Both languages are used: it answers in the person’s.',
            )}
          </p>
        </div>
        <div className="grid gap-4 md:grid-cols-2">
          <div className="space-y-1.5">
            <label htmlFor="agent-instructions" className="block text-sm font-medium">
              {t('agents.editor.english', 'English')}
            </label>
            <Textarea
              id="agent-instructions"
              dir="ltr"
              rows={8}
              value={instructions}
              aria-invalid={tooLong(instructions) || undefined}
              aria-describedby="agent-instructions-count"
              onChange={(e) => setInstructions(e.target.value)}
            />
            {counter(instructions, 'agent-instructions-count')}
          </div>
          <div className="space-y-1.5" dir="rtl">
            <label htmlFor="agent-instructions-ar" className="block text-sm font-medium">
              {t('agents.editor.arabic', 'Arabic')}
            </label>
            <Textarea
              id="agent-instructions-ar"
              dir="rtl"
              rows={8}
              value={instructionsAr}
              aria-invalid={tooLong(instructionsAr) || undefined}
              aria-describedby="agent-instructions-ar-count"
              onChange={(e) => setInstructionsAr(e.target.value)}
            />
            {counter(instructionsAr, 'agent-instructions-ar-count')}
          </div>
        </div>
      </Card>

      <Card className="gap-6 p-4">
        <h2 className="text-base font-semibold">{t('agents.section.how', 'How it writes and what it uses')}</h2>

        <div className="grid gap-6 md:grid-cols-2">
          <div className="space-y-1.5">
            <span className="block text-sm font-medium">{t('agents.field.tone', 'Tone')}</span>
            <ToggleGroup
              required
              label={t('agents.field.tone', 'Tone')}
              value={tone}
              onChange={(value) => {
                if (typeof value === 'string') setTone(value as AgentTone);
              }}
              options={(Object.keys(toneWords) as AgentTone[]).map((id) => ({ value: id, label: toneWords[id] }))}
            />
          </div>
          <div className="space-y-1.5">
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
        </div>

        <ToolsetPicker offered={offered} chosen={toolsets} onChange={setToolsets} />

        <section className="space-y-3">
          <h3 className="text-sm font-medium">{t('agents.field.grant', 'Where a person’s grant starts')}</h3>
          <p className="text-sm text-muted-foreground">
            {t(
              'agents.grant.help',
              'Each person who uses this agent starts here, and can change it within what they hold. Permissions not listed start at Allowed for reads and Ask first for changes.',
            )}{' '}
            {t('agents.grant.noAllowedWrites', 'A write can’t start Allowed. People raise it themselves, one action at a time.')}
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
      </Card>
    </form>
  );
}
