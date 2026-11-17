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

/**
 * One entry of `offers` (WHIT-1202): a paid plugin the workspace's template
 * offers and it can try now, shown as a locked card.
 */
export interface DashboardOffer {
  key: string;
  template: string;
  plugin: string;
  pluginName: string;
  lines: string[];
  trialDays: number;
  hidden: boolean;
  position?: number | null;
}

export interface Dashboard {
  setup: DashboardSetup[];
  widgets: DashboardWidget[];
  /** Absent from a server older than WHIT-1202. */
  offers?: DashboardOffer[];
}

const EMPTY: Dashboard = { setup: [], widgets: [], offers: [] };

export function useDashboard() {
  return useFetch<Dashboard>(async () => {
    const { data: body, response } = await api.GET('/api/v1/me/dashboard');
    // Not worth an error on the home: the apps' parts just don't appear.
    if (!response.ok) return EMPTY;
    return ((body as { data?: Dashboard } | undefined)?.data) ?? EMPTY;
  }, []);
}
