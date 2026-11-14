import type { components } from '@whity/api-client';

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
    /** A stable code for a state the screens label themselves, e.g. `taskaat_update_needed`. */
    public readonly code: string | null = null,
  ) {
    super(message);
  }
}

/** The 501 a Taskaat without the record tools answers with (TaskaatException::UPDATE_NEEDED). */
export const TASKAAT_UPDATE_NEEDED = 'taskaat_update_needed';

/** Throw a TasksError for an openapi-fetch result that failed. */
export function tasksResult<T>(result: { data?: { data: T }; error?: { error?: string; details?: unknown }; response: Response }, fallback: string): T {
  if (result.data) return result.data.data;
  const details = result.error?.details;
  const code = details && typeof details === 'object' && typeof (details as { code?: unknown }).code === 'string' ? (details as { code: string }).code : null;
  throw new TasksError(result.error?.error || fallback, result.response.status, code);
}
