'use client';

/**
 * Bookings (WHIT-1416, design section 41 §8.2): Upcoming and Past, each row
 * the time in the workspace's zone, the service, who and the status in words.
 * Book is the main button when online booking is on.
 */

import Link from 'next/link';
import { useState } from 'react';
import { api } from '@/lib/api/client';
import { useCustomerArea } from '@/lib/customer-area';
import { Button } from '@whity/ui/button';
import { Card, CardContent } from '@whity/ui/card';
import { cn } from '@whity/ui/utils';
import { useTranslation } from '@whity/features/i18n';
import type { components } from '@whity/api-client';
import { LoadState, Outcome, PageTitle, useAreaLoad } from '@/components/customer-area/area-parts';
import { BookingLine } from '@/components/customer-area/area-rows';

type Bookings = { time_zone: string; bookings: components['schemas']['CustomerAreaBookingRow'][] };

export default function CustomerBookings() {
  const t = useTranslation('customer');
  const { handle, phase } = useCustomerArea();
  const [when, setWhen] = useState<'upcoming' | 'past'>('upcoming');
  const [state, retry] = useAreaLoad<Bookings>(
    () => api.GET('/api/v1/customer-area/{handle}/bookings', { params: { path: { handle }, query: { when } } }),
    [handle, when],
  );
  const canBook = phase.kind === 'ready' && phase.area.booking;

  return (
    <div className="space-y-5">
      <PageTitle
        action={
          canBook ? (
            <Button asChild size="lg">
              <Link href={`/book/${handle}`}>{t('bookings.book', 'Book')}</Link>
            </Button>
          ) : undefined
        }
      >
        {t('tab.bookings', 'Bookings')}
      </PageTitle>
      <div role="tablist" aria-label={t('bookings.when', 'Which bookings')} className="inline-flex rounded-control border border-input p-1">
        {(['upcoming', 'past'] as const).map((w) => (
          <button
            key={w}
            role="tab"
            type="button"
            aria-selected={when === w}
            onClick={() => setWhen(w)}
            className={cn(
              'h-10 rounded-control px-4 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring',
              when === w ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted',
            )}
          >
            {w === 'upcoming' ? t('bookings.upcoming', 'Upcoming') : t('bookings.past', 'Past')}
          </button>
        ))}
      </div>
      <LoadState state={state} onRetry={retry} />
      {state.kind === 'ready' &&
        (state.data.bookings.length === 0 ? (
          <Outcome
            icon="appointment"
            tone="muted"
            title={when === 'upcoming' ? t('bookings.noneUpcoming', 'No bookings coming up') : t('bookings.nonePast', 'No past bookings')}
          />
        ) : (
          <Card>
            <CardContent className="divide-y divide-border p-0">
              {state.data.bookings.map((b) => (
                <BookingLine key={b.id} handle={handle} booking={b} timeZone={state.data.time_zone} />
              ))}
            </CardContent>
          </Card>
        ))}
    </div>
  );
}
