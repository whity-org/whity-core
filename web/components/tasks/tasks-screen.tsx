'use client';

/**
 * Work › Tasks (TplTasks, A10). Tasks live in the workspace's Taskaat (T.3); this reads and writes
 * them. Tabs: My tasks, Assigned by me, Agents' tasks, All. Groups: Overdue, Today, This week,
 * Later, Done today. Each row: the title and who it's with, a state where it matters, the board,
 * the assignee and the due date. A row opens the task panel.
 *
 * Not connected: the connect page. A Taskaat without the record tools yet: a note and the link.
 */

import Link from 'next/link';
import { useMemo, useState } from 'react';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Avatar } from '@whity/ui/avatar';
import { Button } from '@whity/ui/button';
import { EmptyState } from '@whity/ui/empty-state';
import { LabelChip } from '@whity/ui/board';
import { Skeleton } from '@whity/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { useActionPermission } from '@/hooks/useActionPermission';
import { api } from '@/lib/api/client';
import { NewTaskDialog } from './new-task-dialog';
import { TaskaatConnection } from './taskaat-connection';
import { TaskPanel } from './task-panel';
import { GateBadge } from './task-status';
import { tasksResult, type TaskRecord } from './task-types';
import { useTaskaat } from './use-taskaat';

type View = 'mine' | 'by_me' | 'agents' | 'all';
type Group = 'overdue' | 'today' | 'week' | 'later' | 'none' | 'doneToday';

/** Today and a week from today as YYYY-MM-DD, in the viewer's own day. */
function days(): { today: string; week: string } {
  const iso = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  const now = new Date();
  const week = new Date(now);
  week.setDate(now.getDate() + 7);
  return { today: iso(now), week: iso(week) };
}

function groupOf(task: TaskRecord, today: string, week: string): Group | null {
  if (task.column === 'done') {
    return task.completed_at && task.completed_at.slice(0, 10) === today ? 'doneToday' : null;
  }
  const due = task.due_date ?? null;
  if (!due) return 'none';
  if (due < today) return 'overdue';
  if (due === today) return 'today';
  if (due <= week) return 'week';
  return 'later';
}

export function TasksScreen() {
  const t = useTranslation('tasks');
  const dates = useDateDisplay();
  const write = useActionPermission('tasks:write');
  const [view, setView] = useState<View>('mine');
  const [open, setOpen] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);

  const loaded = useTaskaat<TaskRecord[]>(async () => {
    const result = tasksResult(
      await api.GET('/api/v1/tasks', { params: { query: { view, status: 'all' } } }),
      t('error.tasks', 'Could not load the tasks. Try again.'),
    );
    return result.tasks;
  }, [view, t]);

  const groups = useMemo(() => {
    const { today, week } = days();
    const byGroup = new Map<Group, TaskRecord[]>();
    for (const task of loaded.data?.value ?? []) {
      const group = groupOf(task, today, week);
      if (group) byGroup.set(group, [...(byGroup.get(group) ?? []), task]);
    }
    return byGroup;
  }, [loaded.data]);

  if (loaded.data?.gap === 'connect') return <TaskaatConnection />;

  const titles: Record<Group, string> = {
    overdue: t('group.overdue', 'Overdue'),
    today: t('group.today', 'Today'),
    week: t('group.week', 'This week'),
    later: t('group.later', 'Later'),
    none: t('group.none', 'No due date'),
    doneToday: t('group.doneToday', 'Done today'),
  };
  const order: Group[] = ['overdue', 'today', 'week', 'later', 'none', 'doneToday'];
  const empty = loaded.data?.value !== null && loaded.data?.value !== undefined && groups.size === 0;

  return (
    <div className="mx-auto max-w-5xl space-y-6 px-4 pb-16 md:px-0">
      <AdminHeader
        title={t('title', 'Tasks')}
        description={t('list.description', 'Tasks live in Taskaat. Whity shows them with your people, agents and approvals.')}
        action={
          <div className="flex flex-wrap gap-2">
            <Button asChild variant="outline">
              <Link href="/boards">{t('list.boards', 'Boards')}</Link>
            </Button>
            <Button asChild variant="outline">
              <Link href="/tasks/connection">{t('list.connection', 'Taskaat connection')}</Link>
            </Button>
            {write.allowed && loaded.data?.gap === null && (
              <Button type="button" onClick={() => setCreating(true)}>{t('new.action', 'New task')}</Button>
            )}
          </div>
        }
      />

      <Tabs value={view} onValueChange={(value) => setView(value as View)}>
        <TabsList>
          <TabsTrigger value="mine">{t('tab.mine', 'My tasks')}</TabsTrigger>
          <TabsTrigger value="by_me">{t('tab.byMe', 'Assigned by me')}</TabsTrigger>
          <TabsTrigger value="agents">{t('tab.agents', 'Agents\' tasks')}</TabsTrigger>
          <TabsTrigger value="all">{t('tab.all', 'All')}</TabsTrigger>
        </TabsList>
      </Tabs>

      {loaded.data?.gap === 'update' && (
        <Alert variant="info">
          <AlertDescription>
            {loaded.data.message}{' '}
            <a className="underline" href="https://app.taskaat.dev" target="_blank" rel="noopener noreferrer">{t('connected.open', 'Open in Taskaat')}</a>
          </AlertDescription>
        </Alert>
      )}
      {loaded.error && <Alert variant="destructive"><AlertDescription>{loaded.error}</AlertDescription></Alert>}
      {loaded.loading && !loaded.data && <div className="space-y-2" role="status" aria-live="polite">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-12 w-full" />)}</div>}

      {empty && (
        <EmptyState
          title={t('list.emptyTitle', 'Nothing here')}
          description={view === 'mine' ? t('list.emptyMine', 'No tasks are with you. Tasks given to you in Taskaat or here show up in this list.') : t('list.empty', 'No tasks in this view.')}
        />
      )}

      {order.map((group) => {
        const tasks = groups.get(group);
        if (!tasks) return null;
        return (
          <section key={group} aria-labelledby={`group-${group}`} className="space-y-2">
            <h2 id={`group-${group}`} className={group === 'overdue' ? 'text-sm font-semibold text-error-text' : 'text-sm font-semibold text-muted-foreground'}>
              {titles[group]} <span className="tabular-nums">{tasks.length}</span>
            </h2>
            <ul className="divide-y divide-border rounded-card border border-border bg-card">
              {tasks.map((task) => (
                <li key={task.id}>
                  <button
                    type="button"
                    onClick={() => setOpen(task.id)}
                    className="flex min-h-12 w-full flex-wrap items-center gap-3 px-4 py-2 text-start outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring/30"
                  >
                    <span className="min-w-0 flex-1">
                      <span className="block truncate font-medium text-foreground">{task.title}</span>
                      {task.assignee && (
                        <span className="block truncate text-sm text-muted-foreground">
                          {task.assignee.for ? t('row.for', '{name} · for {for}', { name: task.assignee.name, for: task.assignee.for.name }) : task.assignee.name}
                        </span>
                      )}
                    </span>
                    <GateBadge gate={task.gate} column={task.column} />
                    {task.project?.name && <LabelChip name={task.project.name} />}
                    {task.assignee && <Avatar name={task.assignee.name} kind={task.assignee.kind} size={24} />}
                    {task.due_date && dates.date(task.due_date) && (
                      <span className={group === 'overdue' ? 'text-sm text-error-text' : 'text-sm text-muted-foreground'}>{dates.date(task.due_date)}</span>
                    )}
                  </button>
                </li>
              ))}
            </ul>
          </section>
        );
      })}

      <TaskPanel taskId={open} onClose={() => setOpen(null)} onChanged={loaded.refetch} />
      {creating && <NewTaskDialog onClose={() => setCreating(false)} onCreated={() => { setCreating(false); loaded.refetch(); }} />}
    </div>
  );
}
