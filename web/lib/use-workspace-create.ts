'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import type { components } from '@whity/api-client';
import { useAuth } from '@/lib/auth-context';

/**
 * Whether this person may create another workspace now (WHIT-1169), from
 * `GET /api/v1/workspaces/new`: switched on by the operator, and under the
 * limit of workspaces one person may own.
 *
 * Read once per signed-in person and shared by the workspace switcher and
 * Ctrl+K; {@link invalidateWorkspaceCreateState} drops it after creating one.
 * Null while it loads or when it couldn't be read: nothing is offered then.
 */
export type WorkspaceCreateState = components['schemas']['WorkspaceCreateStateResponse']['data'];

interface Answer {
  profileId: number;
  generation: number;
  state: WorkspaceCreateState | null;
}

let cached: Answer | null = null;
let generation = 0;
let pending: { profileId: number; generation: number; promise: Promise<WorkspaceCreateState | null> } | null = null;
const listeners = new Set<() => void>();

async function fetchState(): Promise<WorkspaceCreateState | null> {
  try {
    const { data, response } = await api.GET('/api/v1/workspaces/new', { cache: 'no-store' });
    if (!response.ok) return null;
    return (data as { data?: WorkspaceCreateState } | undefined)?.data ?? null;
  } catch {
    return null;
  }
}

/** Forget the answer, so every reader asks again. */
export function invalidateWorkspaceCreateState(): void {
  generation += 1;
  cached = null;
  pending = null;
  listeners.forEach((listener) => listener());
}

function load(profileId: number): Promise<WorkspaceCreateState | null> {
  if (pending === null || pending.profileId !== profileId || pending.generation !== generation) {
    const asked = generation;
    const promise = fetchState().then((state) => {
      if (asked === generation) {
        if (state !== null) {
          cached = { profileId, generation: asked, state };
        } else if (pending?.promise === promise) {
          // A failed read (a session refresh mid-switch, a blip) isn't an
          // answer: forget it so the next reader asks again.
          pending = null;
        }
      }
      return state;
    });
    pending = { profileId, generation: asked, promise };
  }
  return pending.promise;
}

export function useWorkspaceCreateState(): WorkspaceCreateState | null {
  const { user } = useAuth();
  const profileId = user?.id;
  const [answer, setAnswer] = useState<Answer | null>(cached);
  const [version, setVersion] = useState(generation);
  // Retries after a failed read, a few times and spaced out.
  const [retry, setRetry] = useState(0);

  useEffect(() => {
    const listener = () => setVersion(generation);
    listeners.add(listener);
    return () => {
      listeners.delete(listener);
    };
  }, []);

  useEffect(() => {
    if (profileId === undefined) return;
    if (cached !== null && cached.profileId === profileId && cached.generation === version) return;
    let live = true;
    let timer: ReturnType<typeof setTimeout> | undefined;
    const asked = version;
    void load(profileId).then((state) => {
      if (!live) return;
      setAnswer({ profileId, generation: asked, state });
      if (state === null && retry < 3) {
        timer = setTimeout(() => setRetry((r) => r + 1), 2000 * (retry + 1));
      }
    });
    return () => {
      live = false;
      if (timer !== undefined) clearTimeout(timer);
    };
  }, [profileId, version, retry]);

  const current = cached !== null && cached.profileId === profileId && cached.generation === version ? cached : answer;
  return current !== null && current.profileId === profileId && current.generation === version ? current.state : null;
}

/**
 * Show "Create workspace" at all: the operator allows it and the person is
 * staff in the workspace they're in (a customer never sees create-workspace
 * surfaces). At the limit it still shows; the page says why it can't.
 */
export function useCanOfferWorkspaceCreate(): boolean {
  const state = useWorkspaceCreateState();
  const { user, memberships } = useAuth();
  const current = memberships.find((m) => m.tenant_id === user?.tenant_id);
  if (state === null || state.reason === 'disabled') return false;
  return current?.kind !== 'customer';
}
