'use client';

/**
 * What the person's apps add to the dashboard (SDK 1.115, H.5): setup points for
 * Finish setting up and widgets for From your apps, in the person's own order.
 * Fetched once by the page and shared by both parts.
 */

import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import type { Block } from '@/lib/plugin-features';

export type WidgetSize = 'small' | 'medium' | 'large';

/** One entry of `setup` in `GET /api/me/dashboard`. */
export interface DashboardSetup {
  plugin: string;
  status: string;
  points: Array<{
    id: string;
    label?: string | null;
    why?: string | null;
    action?: string | null;
    href: string;
    icon?: string | null;
  }>;
}

/** One entry of `widgets` in `GET /api/me/dashboard`. */
export interface DashboardWidget {
  key: string;
  plugin: string;
  kind: 'stat' | 'list' | 'chart' | 'actions';
  title?: string | null;
  description?: string | null;
  icon?: string | null;
  href?: string | null;
  source?: string | null;
  sizes: WidgetSize[];
  size: WidgetSize;
  hidden: boolean;
  position?: number | null;
  blocks: Block[];
}

export interface Dashboard {
  setup: DashboardSetup[];
  widgets: DashboardWidget[];
}

const EMPTY: Dashboard = { setup: [], widgets: [] };

export function useDashboard() {
  return useFetch<Dashboard>(async () => {
    const { data: body, response } = await api.GET('/api/v1/me/dashboard');
    // Not worth an error on the home: the apps' parts just don't appear.
    if (!response.ok) return EMPTY;
    return ((body as { data?: Dashboard } | undefined)?.data) ?? EMPTY;
  }, []);
}
