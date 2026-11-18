'use client';

import dynamic from 'next/dynamic';
import { Skeleton } from '@whity/ui/skeleton';

/**
 * The heavy renderers, each in its own chunk (WHIT-1245).
 *
 * The dashboard and the plugin block renderer imported these statically, so
 * recharts (with d3 and its store), katex and the document viewer rode in the
 * first load of every page that shows a widget, about 180 KB gzipped, whether
 * or not anything on it draws a chart or a formula. Loaded on first use now,
 * the way `FlowCanvas` already was. Still rendered on the server, so the page
 * keeps its shape; a skeleton holds the place while the chunk arrives.
 */
export const Chart = dynamic(() => import('@whity/ui/chart').then((m) => m.Chart), {
  loading: () => <Skeleton className="h-64 w-full rounded-lg" />,
});

export const MathText = dynamic(() => import('@whity/ui/math-text').then((m) => m.MathText), {
  loading: () => <Skeleton className="inline-block h-5 w-24 align-middle" />,
});

export const DocumentViewer = dynamic(
  () => import('@/components/plugin/blocks/document-viewer').then((m) => m.DocumentViewer),
  { loading: () => <Skeleton className="h-96 w-full rounded-lg" /> },
);
