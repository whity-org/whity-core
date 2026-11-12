'use client';

/**
 * One task, from Taskaat (TplTasks panel; T.4). The done-when check in a blue box, the evidence
 * item by item, then the gate: "Awaiting your review", Send back or Accept as done. A task closed
 * without passing evidence stays "Done, unverified". The footer names the Taskaat task.
 */

import { useState } from 'react';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Avatar } from '@whity/ui/avatar';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Skeleton } from '@whity/ui/skeleton';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@whity/ui/sheet';
import { Textarea } from '@whity/ui/textarea';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { useActionPermission } from '@/hooks/useActionPermission';
import { api } from '@/lib/api/client';
import { tasksResult, type TaskColumn, type TaskDetail } from './task-types';
import { useTaskaat } from './use-taskaat';
import { GateBadge } from './task-status';

export function TaskPanel({ taskId, onClose, onChanged }: { taskId: string | null; onClose: () => void; onChanged: () => void }) {
  const t = useTranslation('tasks');
  const dates = useDateDisplay();
  const write = useActionPermission('tasks:write');
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sendingBack, setSendingBack] = useState(false);
  const [note, setNote] = useState('');
  const [changed, setChanged] = useState<TaskDetail | null>(null);

  const loaded = useTaskaat<TaskDetail | null>(async () => {
    if (!taskId) return null;
    return tasksResult(
      await api.GET('/api/v1/tasks/{task}', { params: { path: { task: taskId } } }),
      t('error.task', 'Could not open this task. Try again.'),
    );
  }, [taskId, t]);
  const task = changed?.id && changed.id === loaded.data?.value?.id ? changed : (loaded.data?.value ?? null);

  const act = async (key: string, run: () => Promise<{ data?: { data: TaskDetail }; error?: { error?: string }; response: Response }>) => {
    setBusy(key);
    setError(null);
    try {
      setChanged(tasksResult(await run(), t('error.change', 'Taskaat did not take the change. Try again.')));
      setSendingBack(false);
      setNote('');
      onChanged();
    } catch (e) {
      setError(e instanceof Error ? e.message : t('error.change', 'Taskaat did not take the change. Try again.'));
    } finally {
      setBusy(null);
    }
  };

  const move = (column: Exclude<TaskColumn, 'review'>) =>
    act(column, () => api.PATCH('/api/v1/tasks/{task}', { params: { path: { task: taskId ?? '' } }, body: { column } }));
  const review = (decision: 'accept' | 'send_back') =>
    act(decision, () => api.POST('/api/v1/tasks/{task}/review', { params: { path: { task: taskId ?? '' } }, body: { decision, note } }));

  const columnLabel: Record<TaskColumn, string> = {
    todo: t('column.todo', 'To do'),
    doing: t('column.doing', 'Doing'),
    review: t('column.review', 'Awaiting review'),
    done: t('column.done', 'Done'),
  };
  const met = task?.checks.filter((check) => check.status === 'met').length ?? 0;

  return (
    <Sheet open={taskId !== null} onOpenChange={(open) => !open && onClose()}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
        {loaded.loading && !task && <div className="space-y-2" role="status" aria-live="polite">{Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="h-12 w-full" />)}</div>}
        {loaded.error && <Alert variant="destructive"><AlertDescription>{loaded.error}</AlertDescription></Alert>}
        {task && (
          <>
            <SheetHeader>
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono text-sm text-muted-foreground">{task.short_id}</span>
                <GateBadge gate={task.gate} column={task.column} />
              </div>
              <SheetTitle className="text-xl">{task.title}</SheetTitle>
              <SheetDescription>
                {task.project?.name}
                {task.due_date && dates.date(task.due_date) ? ` · ${t('panel.due', 'Due {date}', { date: dates.date(task.due_date) ?? '' })}` : ''}
              </SheetDescription>
            </SheetHeader>

            <div className="space-y-6 px-4">
              {task.assignee && (
                <div className="flex items-center gap-3">
                  <Avatar name={task.assignee.name} kind={task.assignee.kind} size={32} />
                  <div className="text-sm">
                    <div className="font-medium">{task.assignee.name}</div>
                    {task.assignee.for && (
                      <div className="text-muted-foreground">{t('panel.for', 'for {name}', { name: task.assignee.for.name })}</div>
                    )}
                  </div>
                </div>
              )}

              {task.detail && <p className="whitespace-pre-line text-sm text-foreground">{task.detail}</p>}

              <section aria-labelledby="task-done-when" className="space-y-3">
                <h3 id="task-done-when" className="text-sm font-semibold">{t('panel.doneWhen', 'Done when')}</h3>
                {task.checks.length === 0 ? (
                  <p className="text-sm text-muted-foreground">{t('panel.noCheck', 'This task has no check. Its evidence goes straight to Done.')}</p>
                ) : (
                  <div className="space-y-2 rounded-card bg-info-tint p-3 text-info-text">
                    <p className="text-sm">{t('panel.checksMet', '{met} of {total} met', { met: String(met), total: String(task.checks.length) })}</p>
                    <ul className="space-y-1.5">
                      {task.checks.map((check) => (
                        <li key={check.id} className="flex items-start gap-2 text-sm">
                          <Icon name={check.status === 'met' ? 'check' : check.status === 'not_met' ? 'close' : 'clock'} size={16} />
                          <span>
                            {check.text}
                            {check.note && <span className="block text-muted-foreground">{check.note}</span>}
                          </span>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </section>

              {task.evidence.length > 0 && (
                <section aria-labelledby="task-evidence" className="space-y-2">
                  <h3 id="task-evidence" className="text-sm font-semibold">{t('panel.evidence', 'Evidence')}</h3>
                  <ul className="space-y-2">
                    {task.evidence.map((item) => (
                      <li key={item.id} className="rounded-control border border-border p-3 text-sm">
                        <p className="whitespace-pre-line">{item.excerpt}</p>
                        {item.created_at && dates.dateTime(item.created_at) && (
                          <p className="mt-1 text-xs text-muted-foreground">{dates.dateTime(item.created_at)}</p>
                        )}
                      </li>
                    ))}
                  </ul>
                </section>
              )}

              {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}

              {write.allowed && task.column === 'review' && (
                <section aria-labelledby="task-gate" className="space-y-3 rounded-card border border-dashed border-warning p-3">
                  <h3 id="task-gate" className="text-sm font-semibold">{t('panel.awaiting', 'Awaiting your review')}</h3>
                  {sendingBack && (
                    <>
                    <label htmlFor="task-send-back" className="block text-sm font-medium">{t('panel.sendBackWhy', 'What needs to change')}</label>
                    <Textarea
                      id="task-send-back"
                      value={note}
                      onChange={(event) => setNote(event.target.value)}
                    />
                    </>
                  )}
                  <div className="flex flex-wrap gap-2">
                    {sendingBack ? (
                      <Button type="button" variant="outline" disabled={note.trim() === ''} loading={busy === 'send_back'} onClick={() => review('send_back')}>
                        {t('panel.sendBackConfirm', 'Send back')}
                      </Button>
                    ) : (
                      <Button type="button" variant="outline" onClick={() => setSendingBack(true)}>
                        {t('panel.sendBack', 'Send back')}
                      </Button>
                    )}
                    <Button type="button" loading={busy === 'accept'} onClick={() => review('accept')}>
                      {t('panel.accept', 'Accept as done')}
                    </Button>
                  </div>
                </section>
              )}

              {write.allowed && task.column !== 'review' && (
                <div className="flex flex-wrap items-center gap-2" role="group" aria-label={t('panel.move', 'Move to')}>
                  <span className="text-sm text-muted-foreground">{t('panel.move', 'Move to')}</span>
                  {(['todo', 'doing', 'done'] as const)
                    .filter((column) => column !== task.column)
                    .map((column) => (
                      <Button key={column} type="button" size="sm" variant="outline" loading={busy === column} onClick={() => move(column)}>
                        {columnLabel[column]}
                      </Button>
                    ))}
                </div>
              )}
            </div>

            <SheetFooter className="flex-row items-center justify-between gap-2 border-t border-border">
              <span className="text-xs text-muted-foreground">{t('panel.footer', 'Taskaat task {id}', { id: task.short_id })}</span>
              <Button asChild variant="outline" size="sm">
                <a href={task.url} target="_blank" rel="noopener noreferrer">{t('connected.open', 'Open in Taskaat')}</a>
              </Button>
            </SheetFooter>
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}
