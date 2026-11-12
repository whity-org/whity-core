'use client';

/**
 * Starting a chat: a direct chat with a person or one of your agents (one per
 * pair, so this opens it if it exists), or a group with a name and people.
 */

import { useEffect, useState } from 'react';
import { Avatar } from '@whity/ui/avatar';
import { Button } from '@whity/ui/button';
import { Checkbox } from '@whity/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { Input } from '@whity/ui/input';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { useLanguageDirection, useTranslation } from '@whity/features/i18n';
import type { ChatDirectory } from '@/lib/chat-wire';
import { chatErrorText, type ChatApi } from './chat-api';

export function NewChatDialog({
  open,
  onOpenChange,
  api,
  myId,
  onStarted,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  api: ChatApi;
  myId: number;
  onStarted: (id: number) => void;
}) {
  const t = useTranslation('chat');
  const dir = useLanguageDirection();
  const [kind, setKind] = useState<'direct' | 'group'>('direct');
  const [q, setQ] = useState('');
  const [title, setTitle] = useState('');
  const [people, setPeople] = useState<number[]>([]);
  const [directory, setDirectory] = useState<ChatDirectory | null>(null);
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

  const run = async (fn: () => Promise<{ id: number }>) => {
    setBusy(true);
    setError(null);
    try {
      onStarted((await fn()).id);
    } catch (e) {
      setError(chatErrorText(e, ''));
    } finally {
      setBusy(false);
    }
  };

  const others = (directory?.people ?? []).filter((p) => p.id !== myId);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[32rem]" closeLabel={t('action.close', 'Close')}>
        <DialogHeader>
          <DialogTitle>{t('new.title', 'New chat')}</DialogTitle>
          <DialogDescription>{t('new.description', 'Write to one person or agent, or start a group.')}</DialogDescription>
        </DialogHeader>
        <ToggleGroup
          label={t('new.kind', 'Kind of chat')}
          required
          value={kind}
          onChange={(v) => setKind((v as 'direct' | 'group') ?? 'direct')}
          options={[
            { value: 'direct', label: t('new.direct', 'One person or agent') },
            { value: 'group', label: t('new.group', 'A group') },
          ]}
        />
        {kind === 'group' && (
          <Input
            label={t('new.name', 'Group name')}
            value={title}
            maxLength={120}
            onChange={(e) => setTitle(e.target.value)}
            placeholder={t('new.namePlaceholder', 'For example, Ramadan boxes')}
          />
        )}
        <Input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder={t('add.search', 'Search by name')}
          aria-label={t('add.search', 'Search by name')}
        />
        <ul className="grid max-h-72 gap-1 overflow-y-auto">
          {others.map((p) => (
            <li key={`p${p.id}`}>
              {kind === 'direct' ? (
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => void run(() => api.direct({ profile_id: p.id }))}
                  className="flex w-full items-center gap-2.5 rounded-control px-2 py-2 text-start text-sm hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring"
                >
                  <Avatar name={p.name} size={28} />
                  <span className="truncate">{p.name}</span>
                </button>
              ) : (
                <label className="flex items-center gap-2.5 rounded-control px-2 py-2 text-sm hover:bg-muted">
                  <Checkbox
                    checked={people.includes(p.id)}
                    onCheckedChange={(c) => setPeople((prev) => (c ? [...prev, p.id] : prev.filter((x) => x !== p.id)))}
                  />
                  <Avatar name={p.name} size={28} />
                  <span className="truncate">{p.name}</span>
                </label>
              )}
            </li>
          ))}
          {kind === 'direct' &&
            (directory?.agents ?? []).map((a) => (
              <li key={`a${a.id}`}>
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => void run(() => api.direct({ agent_id: a.id }))}
                  className="flex w-full items-center gap-2.5 rounded-control px-2 py-2 text-start text-sm hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring"
                >
                  <Avatar name={a.name} kind="agent" size={28} />
                  <span className="grid min-w-0">
                    <span className="truncate">{dir === 'rtl' && a.name_ar ? a.name_ar : a.name}</span>
                    <small className="truncate text-xs text-muted-foreground">{dir === 'rtl' && a.role_ar ? a.role_ar : a.role}</small>
                  </span>
                </button>
              </li>
            ))}
        </ul>
        {kind === 'group' && (
          <p className="text-xs text-muted-foreground">{t('new.agentsLater', 'Add agents once the group is started, from beside the chat.')}</p>
        )}
        {error !== null && (
          <p role="alert" className="text-sm text-error-text">
            {error || t('error.generic', 'Something went wrong with Chat. Please try again.')}
          </p>
        )}
        {kind === 'group' && (
          <DialogFooter>
            <Button variant="ghost" onClick={() => onOpenChange(false)}>
              {t('action.cancel', 'Cancel')}
            </Button>
            <Button icon="users" loading={busy} disabled={title.trim() === ''} onClick={() => void run(() => api.createGroup(title.trim(), people, null))}>
              {t('new.start', 'Start the group')}
            </Button>
          </DialogFooter>
        )}
      </DialogContent>
    </Dialog>
  );
}
