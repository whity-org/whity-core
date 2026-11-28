'use client';

/**
 * The booking page inside a workspace's own website (WHIT-1445, design section
 * 39 §9.1, AP.24): what `embed.js` opens in its overlay. The same page as
 * /book/[workspace]; only this address may be framed, and only by the sites
 * the workspace listed under Settings › Calendar › Your website (proxy.ts sets
 * its frame-ancestors; none by default).
 */

import { use } from 'react';
import { PublicBookingScreen } from '@/components/booking/public-booking';

export default function EmbeddedBookPage({ params }: { params: Promise<{ workspace: string }> }) {
  const { workspace } = use(params);
  return <PublicBookingScreen workspace={workspace} embedded />;
}
