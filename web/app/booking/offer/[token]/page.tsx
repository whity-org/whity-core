'use client';

/**
 * A time offered from the waitlist (WHIT-1442), from the link in the email.
 * Outside `(protected)`, like the manage page: the token is the only key.
 */

import { use } from 'react';
import { WaitlistOfferScreen } from '@/components/booking/waitlist-offer';

export default function WaitlistOfferPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = use(params);
  return <WaitlistOfferScreen token={token} />;
}
