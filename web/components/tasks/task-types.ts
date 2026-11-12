import type { components } from '@/lib/api/schema';

/** A task as Taskaat's record tools return it, through /api/v1/tasks (A10). */
export type TaskRecord = components['schemas']['TaskRecord'];
export type TaskDetail = components['schemas']['TaskDetailResponse']['data'];
export type TaskBoard = components['schemas']['TaskBoardResponse']['data'];
export type TaskBoardSummary = components['schemas']['TaskBoardSummary'];
export type TaskColumn = TaskRecord['column'];

/**
 * Why a tasks request did not answer: not connected (409), a Taskaat too old for the record
 * tools (501), or anything else. The screens show the connect page, "open in Taskaat", or the
 * error in plain words.
 */
export class TasksError extends Error {
  constructor(
    message: string,
    public readonly status: number,
  ) {
    super(message);
  }
}

/** Throw a TasksError for an openapi-fetch result that failed. */
export function tasksResult<T>(result: { data?: { data: T }; error?: { error?: string }; response: Response }, fallback: string): T {
  if (result.data) return result.data.data;
  throw new TasksError(result.error?.error || fallback, result.response.status);
}
