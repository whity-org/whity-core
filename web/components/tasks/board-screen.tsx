'use client';

/**
 * Boards (TplBoard, T.2). A board is a view of tasks: each board is a Taskaat project, its columns
 * are To do, Doing, Awaiting review (the gate, amber) and Done. A card opens the task panel, where
 * it moves between columns; it reaches Awaiting review when its evidence is submitted and leaves
 * it when a person accepts it or sends it back.
 */

import Link from 'next/link';
import { useState } from 'react';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Board, BoardCard, BoardColumn } from '@whity/ui/board';
import { Button } from '@whity/ui/button';
import { EmptyState } from '@whity/ui/empty-state';
import { Skeleton } from '@whity/ui/skeleton';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { useActionPermission } from '@/hooks/useActionPermission';
import { api } from '@/lib/api/client';
import { NewTaskDialog } from './new-task-dialog';
import { TaskaatConnection } from './taskaat-connection';
import { TaskPanel } from './task-panel';
import { GateBadge } from './task-status';
import { tasksResult, type TaskBoard, type TaskBoardSummary, type TaskColumn } from './task-types';
import { useTaskaat } from './use-taskaat';

function UpdateNote({ message }: { message: string }) {
  const t = useTranslation('tasks');
  return (
    <Alert variant="info">
      <AlertDescription>
        {message}{' '}
        <a className="underline" href="https://app.taskaat.dev" target="_blank" rel="noopener noreferrer">{t('connected.open', 'Open in Taskaat')}</a>
      </AlertDescription>
    </Alert>
  );
}

/** /boards: every board, with how many tasks are open and done. */
export function BoardsScreen() {
  const t = useTranslation('tasks');
  const loaded = useTaskaat<TaskBoardSummary[]>(async () =>
    tasksResult(await api.GET('/api/v1/tasks/boards'), t('error.boards', 'Could not load the boards. Try again.')).boards,
  [t]);

  if (loaded.data?.gap === 'connect') return <TaskaatConnection />;

  return (
    <div className="mx-auto max-w-5xl space-y-6 px-4 pb-16 md:px-0">
      <AdminHeader
        title={t('boards.title', 'Boards')}
        description={t('boards.description', 'Each board is a Taskaat project. The same tasks show in Tasks and on the board.')}
        action={<Button asChild variant="outline"><Link href="/tasks">{t('boards.list', 'Task list')}</Link></Button>}
      />
      {loaded.data?.gap === 'update' && <UpdateNote message={loaded.data.message} />}
      {loaded.error && <Alert variant="destructive"><AlertDescription>{loaded.error}</AlertDescription></Alert>}
      {loaded.loading && !loaded.data && <div className="space-y-2" role="status" aria-live="polite">{Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="h-12 w-full" />)}</div>}
      {loaded.data?.value && loaded.data.value.length === 0 && (
        <EmptyState title={t('boards.empty', 'No boards yet')} description={t('boards.emptyBody', 'Create a project in Taskaat and it shows here as a board.')} />
      )}
      {loaded.data?.value && loaded.data.value.length > 0 && (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {loaded.data.value.map((board) => (
            <li key={board.id}>
              <Link
                href={`/boards/${encodeURIComponent(board.prefix || board.slug)}`}
                className="block rounded-card border border-border bg-card p-4 outline-none hover:shadow-plane focus-visible:ring-2 focus-visible:ring-ring/30"
              >
                <span className="block font-semibold text-foreground">{board.name}</span>
                <span className="mt-1 block text-sm text-muted-foreground">
                  {t('boards.counts', '{open} open · {done} done', { open: String(board.open_count), done: String(board.done_count) })}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/** /boards/[board]: the board's columns and cards. */
export function BoardScreen({ board }: { board: string }) {
  const t = useTranslation('tasks');
  const dates = useDateDisplay();
  const write = useActionPermission('tasks:write');
  const [open, setOpen] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);

  const loaded = useTaskaat<TaskBoard>(async () =>
    tasksResult(
      await api.GET('/api/v1/tasks/boards/{board}', { params: { path: { board } } }),
      t('error.board', 'Could not load this board. Try again.'),
    ),
  [board, t]);

  if (loaded.data?.gap === 'connect') return <TaskaatConnection />;
  const value = loaded.data?.value ?? null;

  const titles: Record<TaskColumn, string> = {
    todo: t('column.todo', 'To do'),
    doing: t('column.doing', 'Doing'),
    review: t('column.review', 'Awaiting review'),
    done: t('column.done', 'Done'),
  };
  const columns: TaskColumn[] = ['todo', 'doing', 'review', 'done'];

  return (
    <div className="space-y-6 px-4 pb-16">
      <AdminHeader
        title={value?.project.name ?? t('boards.title', 'Boards')}
        breadcrumb={<Link href="/boards" className="hover:underline">{t('boards.title', 'Boards')}</Link>}
        action={
          <div className="flex flex-wrap gap-2">
            <Button asChild variant="outline"><Link href="/tasks">{t('boards.list', 'Task list')}</Link></Button>
            {write.allowed && value && <Button type="button" onClick={() => setCreating(true)}>{t('new.action', 'New task')}</Button>}
          </div>
        }
      />
      {loaded.data?.gap === 'update' && <UpdateNote message={loaded.data.message} />}
      {loaded.error && <Alert variant="destructive"><AlertDescription>{loaded.error}</AlertDescription></Alert>}
      {loaded.loading && !loaded.data && <div className="space-y-2" role="status" aria-live="polite">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-12 w-full" />)}</div>}

      {value && (
        <Board aria-label={value.project.name}>
          {columns.map((column) => {
            const tasks = value.tasks.filter((task) => task.column === column);
            return (
              <BoardColumn key={column} title={titles[column]} count={tasks.length} gate={column === 'review'}>
                {tasks.map((task) => (
                  <BoardCard
                    key={task.id}
                    title={task.title}
                    badge={column === 'done' || column === 'review' ? <GateBadge gate={task.gate} column={task.column} /> : undefined}
                    labels={(task.labels ?? []).map((label) => ({ name: label.name, color: label.color ?? undefined }))}
                    checks={task.checks ? { met: task.checks.met, total: task.checks.total, label: t('card.checks', '{met} of {total} checks met') } : undefined}
                    due={task.due_date ? (dates.date(task.due_date) ?? undefined) : undefined}
                    assignee={task.assignee ? { name: task.assignee.name, kind: task.assignee.kind } : undefined}
                    onOpen={() => setOpen(task.id)}
                  />
                ))}
              </BoardColumn>
            );
          })}
        </Board>
      )}

      <TaskPanel taskId={open} onClose={() => setOpen(null)} onChanged={loaded.refetch} />
      {creating && value && (
        <NewTaskDialog board={value.project.prefix || value.project.slug} onClose={() => setCreating(false)} onCreated={() => { setCreating(false); loaded.refetch(); }} />
      )}
    </div>
  );
}
