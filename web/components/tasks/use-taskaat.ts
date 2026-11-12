'use client';

import { useFetch } from '@/hooks/useFetch';
import { TasksError } from './task-types';

/**
 * Load something from Taskaat through Whity. Not being connected (409) and a Taskaat without
 * the record tools yet (501) are states the screens show, not errors: they come back as `gap`.
 */
export type TaskaatLoad<T> = { gap: null; value: T } | { gap: 'connect' | 'update'; value: null; message: string };

export function useTaskaat<T>(load: () => Promise<T>, deps: React.DependencyList) {
  return useFetch<TaskaatLoad<T>>(async () => {
    try {
      return { gap: null, value: await load() };
    } catch (error) {
      if (error instanceof TasksError && (error.status === 409 || error.status === 501)) {
        return { gap: error.status === 409 ? 'connect' : 'update', value: null, message: error.message };
      }
      throw error;
    }
  }, deps);
}
