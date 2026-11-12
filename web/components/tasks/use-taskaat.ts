'use client';

import { useFetch } from '@/hooks/useFetch';
import { TASKAAT_UPDATE_NEEDED, TasksError } from './task-types';

/**
 * Load something from Taskaat through Whity. Not being connected (409) and a Taskaat without
 * the record tools yet (501, code `taskaat_update_needed`) are states the screens show in their
 * own translated words, not errors: they come back as `gap`.
 */
export type TaskaatLoad<T> = { gap: null; value: T } | { gap: 'connect' | 'update'; value: null };

export function useTaskaat<T>(load: () => Promise<T>, deps: React.DependencyList) {
  return useFetch<TaskaatLoad<T>>(async () => {
    try {
      return { gap: null, value: await load() };
    } catch (error) {
      if (error instanceof TasksError && error.status === 409) {
        return { gap: 'connect', value: null };
      }
      if (error instanceof TasksError && (error.code === TASKAAT_UPDATE_NEEDED || error.status === 501)) {
        return { gap: 'update', value: null };
      }
      throw error;
    }
  }, deps);
}
