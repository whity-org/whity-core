'use client';

/**
 * A workspace's public booking page (WHIT-945, ADR 0017 section 6): a customer
 * books a service at a free time without signing in.
 *
 * Outside `(protected)`, like /join and /f: no session, no auth gate. The
 * workspace comes from the slug in the address; the server answers the same
 * 404 for an unknown workspace, one with its booking page off, and a host
 * naming another workspace.
 */

import { use } from 'react';
import { PublicBookingScreen } from '@/components/booking/public-booking';

export default function BookPage({ params }: { params: Promise<{ workspace: string }> }) {
  const { workspace } = use(params);
  return <PublicBookingScreen workspace={workspace} />;
}
