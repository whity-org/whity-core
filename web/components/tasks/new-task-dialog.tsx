'use client';

/**
 * New task (TplTasks): a board, a title, an optional due date, and who it's with — you, one of
 * the workspace's agents (then it's done for you), or nobody yet. Saved in Taskaat.
 */

import { useState } from 'react';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { Input } from '@whity/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { useTranslation } from '@whity/features/i18n';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';
import { api } from '@/lib/api/client';
import { tasksResult } from './task-types';

interface AgentOption {
  id: number;
  name: string;
}

export function NewTaskDialog({ board, onClose, onCreated }: { board?: string; onClose: () => void; onCreated: () => void }) {
  const t = useTranslation('tasks');
  const { user } = useAuth();
  const [boardId, setBoardId] = useState(board ?? '');
  const [title, setTitle] = useState('');
  const [due, setDue] = useState('');
  const [who, setWho] = useState('me');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const boards = useFetch(async () =>
    tasksResult(await api.GET('/api/v1/tasks/boards'), t('error.boards', 'Could not load the boards. Try again.')).boards,
  [t]);
  const agents = useFetch<AgentOption[]>(async () => {
    // Agents are optional here: someone who can't see them can still give a task to themselves.
    const { data } = await api.GET('/api/v1/agents');
    const list: unknown = (data as { data?: unknown } | undefined)?.data;
    return Array.isArray(list)
      ? list.filter((a): a is AgentOption => typeof a?.id === 'number' && typeof a?.name === 'string').map((a) => ({ id: a.id, name: a.name }))
      : [];
  }, []);

  const profileId = typeof user?.id === 'number' ? user.id : null;

  const save = async () => {
    setBusy(true);
    setError(null);
    const assignee =
      who === 'me' && profileId !== null
        ? { kind: 'person' as const, id: profileId }
        : who.startsWith('agent:')
          ? { kind: 'agent' as const, id: Number(who.slice(6)) }
          : null;
    try {
      tasksResult(
        await api.POST('/api/v1/tasks', {
          body: { board: boardId, title: title.trim(), ...(due ? { due_date: due } : {}), ...(assignee ? { assignee } : {}) },
        }),
        t('error.create', 'Taskaat did not create the task. Try again.'),
      );
      onCreated();
    } catch (e) {
      setError(e instanceof Error ? e.message : t('error.create', 'Taskaat did not create the task. Try again.'));
      setBusy(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('new.title', 'New task')}</DialogTitle>
          <DialogDescription>{t('new.body', 'It is saved in Taskaat, on the board you choose.')}</DialogDescription>
        </DialogHeader>
        <div className="space-y-4">
          <div className="space-y-1.5">
            <label className="text-sm font-medium" htmlFor="new-task-board">{t('new.board', 'Board')}</label>
            <Select value={boardId} onValueChange={setBoardId}>
              <SelectTrigger id="new-task-board"><SelectValue placeholder={t('new.boardPick', 'Choose a board')} /></SelectTrigger>
              <SelectContent>
                {(boards.data ?? []).map((b) => (
                  <SelectItem key={b.id} value={b.prefix || b.slug}>{b.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <Input label={t('new.titleField', 'Title')} value={title} onChange={(event) => setTitle(event.target.value)} />
          <Input label={t('new.due', 'Due date')} type="date" value={due} onChange={(event) => setDue(event.target.value)} />
          <div className="space-y-1.5">
            <label className="text-sm font-medium" htmlFor="new-task-who">{t('new.who', 'Who it\'s with')}</label>
            <Select value={who} onValueChange={setWho}>
              <SelectTrigger id="new-task-who"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="me">{t('new.me', 'Me')}</SelectItem>
                <SelectItem value="none">{t('new.nobody', 'Nobody yet')}</SelectItem>
                {(agents.data ?? []).map((agent) => (
                  <SelectItem key={agent.id} value={`agent:${agent.id}`}>{t('new.agent', '{name} (agent, for you)', { name: agent.name })}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>{t('action.cancel', 'Cancel')}</Button>
          <Button type="button" loading={busy} disabled={boardId === '' || title.trim() === ''} onClick={save}>
            {t('new.save', 'Create task')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
