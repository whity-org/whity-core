import type { Metadata } from 'next';
import { CustomerAreaFrame } from '@/components/customer-area/customer-area-frame';

/**
 * A workspace's customer area (WHIT-1416, design section 41), served at
 * `/@handle/...` through the rewrite in proxy.ts. One person's own records,
 * so it is never indexed.
 */
export const metadata: Metadata = {
  title: 'Your account',
  robots: { index: false, follow: false },
};

export default async function Layout({ children, params }: { children: React.ReactNode; params: Promise<{ handle: string }> }) {
  const { handle } = await params;
  return <CustomerAreaFrame handle={handle}>{children}</CustomerAreaFrame>;
}
