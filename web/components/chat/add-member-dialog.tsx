'use client';

/**
 * Adding to a chat (CH.2, card TplChatAddAgent). A person is just added. An
 * agent is added with three things said out loud: what it can read (from now
 * on by default), who it works for here, and what it may do. A line in the
 * chat tells everyone.
 */

import { useEffect, useMemo, useState } from 'react';
import { Avatar } from '@whity/ui/avatar';
import { Button } from '@whity/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { Input } from '@whity/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { useLanguageDirection, useTranslation } from '@whity/features/i18n';
import { cn } from '@/lib/utils';
import type { ChatDetail, ChatDirectory, ChatMode, ChatReads } from '@/lib/chat-wire';
import { chatErrorText, type ChatApi } from './chat-api';

export function AddMemberDialog({
  open,
  kind,
  onOpenChange,
  onKind,
  api,
  chat,
  myId,
  onAdded,
}: {
  open: boolean;
  kind: 'person' | 'agent';
  onOpenChange: (open: boolean) => void;
  onKind: (kind: 'person' | 'agent') => void;
  api: ChatApi;
  chat: ChatDetail;
  myId: number;
  onAdded: (detail: ChatDetail) => void;
}) {
  const t = useTranslation('chat');
  const dir = useLanguageDirection();
  const [directory, setDirectory] = useState<ChatDirectory | null>(null);
  const [q, setQ] = useState('');
  const [agentId, setAgentId] = useState<number | null>(null);
  const [reads, setReads] = useState<ChatReads>('now');
  const [worksFor, setWorksFor] = useState<number>(myId);
  const [post, setPost] = useState<ChatMode>('allowed');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    let live = true;
    api.directory(q).then(
      (d) => live && setDirectory(d),
      (e: unknown) => live && setError(chatErrorText(e, ''))
    );
    return () => {
      live = false;
    };
  }, [open, q, api]);

  const inChat = useMemo(() => {
    const people = new Set<number>();
    const agents = new Set<number>();
    for (const m of chat.members) {
      if (m.type === 'agent') agents.add(m.agent_id);
      else people.add(m.profile_id);
    }
    return { people, agents };
  }, [chat.members]);

  const agents = (directory?.agents ?? []).filter((a) => !inChat.agents.has(a.id));
  const people = (directory?.people ?? []).filter((p) => !inChat.people.has(p.id));
  const agent = agents.find((a) => a.id === agentId) ?? agents[0] ?? null;
  const name = (a: { name: string; name_ar: string }) => (dir === 'rtl' && a.name_ar ? a.name_ar : a.name);
  const role = (a: { role: string; role_ar: string }) => (dir === 'rtl' && a.role_ar ? a.role_ar : a.role);
  const chatPeople = chat.members.filter((m) => m.type === 'person');

  const addAgent = async () => {
    if (!agent) return;
    setBusy(true);
    setError(null);
    try {
      onAdded(await api.addAgent(chat.id, agent.id, worksFor, reads, { post }));
    } catch (e) {
      setError(chatErrorText(e, ''));
    } finally {
      setBusy(false);
    }
  };

  const addPerson = async (profileId: number) => {
    setBusy(true);
    setError(null);
    try {
      onAdded(await api.addPerson(chat.id, profileId));
    } catch (e) {
      setError(chatErrorText(e, ''));
    } finally {
      setBusy(false);
    }
  };

  const modes = [
    { value: 'off', label: t('mode.off', 'Off') },
    { value: 'ask', label: t('mode.ask', 'Ask first') },
    { value: 'allowed', label: t('mode.allowed', 'Allowed') },
  ];

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[35rem]" closeLabel={t('action.close', 'Close')}>
        <DialogHeader>
          <DialogTitle>
            {kind === 'agent'
              ? t('add.agentTitle', 'Add an agent to {chat}', {
                  chat: chat.title,
                })
              : t('add.personTitle', 'Add a person to {chat}', {
                  chat: chat.title,
                })}
          </DialogTitle>
          <DialogDescription className="sr-only">{t('add.description', 'Choose who joins this chat.')}</DialogDescription>
        </DialogHeader>
        <ToggleGroup
          label={t('add.kind', 'Add')}
          required
          value={kind}
          onChange={(v) => onKind((v as 'person' | 'agent') ?? 'agent')}
          options={[
            {
              value: 'agent',
              label: t('add.anAgent', 'An agent'),
              icon: 'robot',
            },
            {
              value: 'person',
              label: t('add.aPerson', 'A person'),
              icon: 'user',
            },
          ]}
        />

        {kind === 'person' ? (
          <div className="grid gap-3">
            <Input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder={t('add.search', 'Search by name')}
              aria-label={t('add.search', 'Search by name')}
            />
            <ul className="grid max-h-72 gap-1 overflow-y-auto">
              {people.length === 0 && <li className="p-2 text-sm text-muted-foreground">{t('add.noPeople', 'Everyone who matches is already here.')}</li>}
              {people.map((p) => (
                <li key={p.id}>
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => void addPerson(p.id)}
                    className="flex w-full items-center gap-2.5 rounded-control px-2 py-2 text-start text-sm hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring"
                  >
                    <Avatar name={p.name} size={28} />
                    <span className="flex-1 truncate">{p.name}</span>
                    <span className="text-xs text-primary">{t('add.add', 'Add')}</span>
                  </button>
                </li>
              ))}
            </ul>
          </div>
        ) : directory === null ? (
          <div className="h-48 animate-pulse rounded-card bg-muted" aria-busy="true" />
        ) : agents.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            {t('add.noAgents', 'Every agent in this workspace is already in this chat, or there are none yet. Set agents up in Agents.')}
          </p>
        ) : (
          <div className="grid gap-4">
            <Select value={agent ? String(agent.id) : undefined} onValueChange={(v) => setAgentId(Number(v))}>
              <SelectTrigger aria-label={t('add.agent', 'Agent')} className="h-auto w-full border-primary bg-primary-tint py-2.5">
                <SelectValue>
                  {agent && (
                    <span className="flex items-center gap-2.5 text-start">
                      <Avatar name={agent.name} kind="agent" size={28} />
                      <span className="grid">
                        <b className="text-sm font-semibold">{name(agent)}</b>
                        <small className="text-xs text-muted-foreground">{role(agent)}</small>
                      </span>
                    </span>
                  )}
                </SelectValue>
              </SelectTrigger>
              <SelectContent>
                {agents.map((a) => (
                  <SelectItem key={a.id} value={String(a.id)}>
                    {name(a)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>

            <fieldset className="grid gap-2">
              <legend className="mb-2 text-sm font-semibold">{t('add.reads', 'It can read')}</legend>
              <ToggleGroup
                label={t('add.reads', 'It can read')}
                required
                value={reads}
                onChange={(v) => setReads((v as ChatReads) ?? 'now')}
                options={[
                  { value: 'now', label: t('add.readsNow', 'From now on') },
                  {
                    value: 'week',
                    label: t('add.readsWeek', 'The last 7 days'),
                  },
                  { value: 'all', label: t('add.readsAll', 'Everything') },
                ]}
              />
              {reads !== 'now' && (
                <p className="text-xs text-muted-foreground">{t('add.readsShown', 'Everyone in the chat will see that it can read earlier messages.')}</p>
              )}
            </fieldset>

            <label className="grid gap-2">
              <span className="text-sm font-semibold">{t('add.worksFor', 'Works for, in this chat')}</span>
              <Select value={String(worksFor)} onValueChange={(v) => setWorksFor(Number(v))}>
                <SelectTrigger className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {chatPeople.map((p) =>
                    p.type === 'person' ? (
                      <SelectItem key={p.profile_id} value={String(p.profile_id)}>
                        {p.profile_id === myId ? t('add.you', '{name} (you)', { name: p.name }) : p.name}
                      </SelectItem>
                    ) : null
                  )}
                </SelectContent>
              </Select>
              <small className="text-xs text-muted-foreground">
                {t('add.worksForHint', 'Its proposals go to this person, and it acts as their delegate.')}
              </small>
            </label>

            <fieldset className="grid gap-1">
              <legend className="mb-2 text-sm font-semibold">{t('add.mayDo', 'What it may do here')}</legend>
              <div className={cn('flex items-center justify-between gap-3 border-b border-border py-2 last:border-0')}>
                <span className="grid">
                  <span className="text-sm">{t('add.post', 'Post and reply')}</span>
                  <small className="text-xs text-muted-foreground">{t('add.postHint', 'Write in this chat')}</small>
                </span>
                <ToggleGroup
                  label={t('add.post', 'Post and reply')}
                  required
                  value={post}
                  onChange={(v) => setPost((v as ChatMode) ?? 'allowed')}
                  options={modes}
                />
              </div>
              <p className="text-xs text-muted-foreground">
                {t('add.capped', 'Never more than the person it works for can do; what it may do beyond this chat is set in Agents.')}
              </p>
            </fieldset>

            <p className="text-xs text-muted-foreground">
              {t('add.footer', 'Everyone in the chat sees that it joined and what it may do. It never sees other chats.')}
            </p>
          </div>
        )}

        {error !== null && (
          <p role="alert" className="text-sm text-error-text">
            {error || t('error.generic', 'Something went wrong with Chat. Please try again.')}
          </p>
        )}
        {kind === 'agent' && agent && (
          <DialogFooter>
            <Button variant="ghost" onClick={() => onOpenChange(false)}>
              {t('action.cancel', 'Cancel')}
            </Button>
            <Button icon="robot" loading={busy} onClick={() => void addAgent()}>
              {t('add.confirm', 'Add {name}', { name: name(agent) })}
            </Button>
          </DialogFooter>
        )}
      </DialogContent>
    </Dialog>
  );
}
