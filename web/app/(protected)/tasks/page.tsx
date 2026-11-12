'use client';

/** /tasks — tasks and boards on Taskaat (A10, WHIT-962). The screen is `TaskaatConnection` until the list and board land. */

import { TaskaatConnection } from '@/components/tasks/taskaat-connection';

export default function TasksPage() {
  return <TaskaatConnection />;
}
