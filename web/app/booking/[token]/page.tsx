'use client';

/**
 * The customer's own booking, from the link in their email (WHIT-945): see
 * it, add it to a calendar, move it or cancel it. Outside `(protected)`, like
 * /join and /f: the token in the address is the only key.
 */

import { use } from 'react';
import { ManageBookingScreen } from '@/components/booking/manage-booking';

export default function ManageBookingPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = use(params);
  return <ManageBookingScreen token={token} />;
}
