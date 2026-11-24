/**
 * A workspace paused because its plan expired or was canceled (2026-11-22).
 * See `components/billing/workspace-pause.tsx`.
 */
import { api } from '@/lib/api/client';
import type { components } from '@whity/api-client';

export type WorkspacePause = components['schemas']['WorkspacePauseState'];

export async function fetchWorkspacePause(): Promise<WorkspacePause | null> {
  const { data, response } = await api.GET('/api/v1/billing/pause');
  if (!response.ok || !data) {
    throw new Error(`billing/pause ${response.status}`);
  }

  return data.data ?? null;
}

/**
 * The pages an owner can still use when the wall refuses reads too: Billing
 * (the plan picker and checkout), their own account pages, and the pages for
 * creating or taking over a workspace. Everything else goes to Billing.
 */
const OPEN_WHILE_PAUSED = ['/billing', '/settings', '/workspaces', '/ownership-offers', '/setup'];

export function isOpenWhilePaused(pathname: string): boolean {
  return OPEN_WHILE_PAUSED.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`));
}
