'use client';

/**
 * Home (WHIT-1416, design section 41 §8.1, CA.14): what needs the customer
 * first (a quote to answer, an invoice to pay), then the next booking and the
 * latest message, then quick actions. An empty Home says so and offers Book
 * or Write to us, whichever the workspace has on.
 */

import Link from 'next/link';
import { api } from '@/lib/api/client';
import { areaHref, useCustomerArea } from '@/lib/customer-area';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@whity/ui/card';
import { useCurrentLanguage, useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { UserText } from '@/components/ui/user-text';
import type { components } from '@whity/api-client';
import { LoadState, Outcome, PageTitle, useAreaLoad } from '@/components/customer-area/area-parts';
import { BillLine, BookingLine } from '@/components/customer-area/area-rows';

type Home = components['schemas']['CustomerAreaHome'];

export default function CustomerHome() {
  const t = useTranslation('customer');
  const dates = useDateDisplay();
  const { handle, phase } = useCustomerArea();
  const { currentLanguage } = useCurrentLanguage();
  const [state, retry] = useAreaLoad<Home>(() => api.GET('/api/v1/customer-area/{handle}/home', { params: { path: { handle } } }), [handle]);
  const area = phase.kind === 'ready' ? phase.area : null;
  const arabic = String(currentLanguage ?? '').startsWith('ar');
  const welcome = area ? (arabic ? area.welcome.ar || area.welcome.en : area.welcome.en || area.welcome.ar) : '';
  const name = area?.me?.name ?? '';

  return (
    <div className="space-y-6">
      <PageTitle>{name ? t('home.hello', 'Hello, {name}', { name }) : t('home.title', 'Home')}</PageTitle>
      {welcome && <UserText className="block whitespace-pre-line text-muted-foreground">{welcome}</UserText>}
      <LoadState state={state} onRetry={retry} />
      {state.kind === 'ready' && (
        <>
          {state.data.waiting.length > 0 && (
            <Card data-testid="customer-home-waiting">
              <CardHeader>
                <CardTitle>{t('home.waiting', 'Waiting for you')}</CardTitle>
              </CardHeader>
              <CardContent className="divide-y divide-border p-0">
                {state.data.waiting.map((bill) => (
                  <BillLine key={`${bill.kind}-${bill.id}`} handle={handle} bill={bill} />
                ))}
              </CardContent>
            </Card>
          )}
          {state.data.next_booking && (
            <Card data-testid="customer-home-booking">
              <CardHeader>
                <CardTitle>{t('home.nextBooking', 'Your next booking')}</CardTitle>
              </CardHeader>
              <CardContent className="p-0">
                <BookingLine handle={handle} booking={state.data.next_booking} timeZone={state.data.time_zone} />
              </CardContent>
            </Card>
          )}
          {state.data.latest_message && (
            <Card data-testid="customer-home-message">
              <CardHeader>
                <CardTitle>{t('home.latestMessage', 'Latest message')}</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                {state.data.latest_message.message && (
                  <p className="text-sm text-muted-foreground">{dates.dateTime(state.data.latest_message.message.created_at)}</p>
                )}
                <UserText className="line-clamp-3 block">{state.data.latest_message.message?.body ?? ''}</UserText>
                <Button asChild variant="outline">
                  <Link href={areaHref(handle, `messages/${state.data.latest_message.id}`)}>{t('home.reply', 'Reply')}</Link>
                </Button>
              </CardContent>
            </Card>
          )}
          {state.data.waiting.length === 0 && !state.data.next_booking && !state.data.latest_message && (
            <Outcome
              icon="success"
              tone="ok"
              title={t('home.emptyTitle', 'Nothing waiting for you')}
              body={t('home.emptyBody', 'Your bookings, bills and messages with {business} will show here.', { business: area?.workspace.name ?? '' })}
            />
          )}
          {area && (area.booking || area.chat) && (
            <div className="flex flex-col gap-2 sm:flex-row">
              {area.booking && (
                <Button asChild size="lg">
                  <Link href={`/book/${handle}`}>{t('home.book', 'Book')}</Link>
                </Button>
              )}
              {area.chat && (
                <Button asChild size="lg" variant="outline">
                  <Link href={areaHref(handle, 'messages')}>{t('home.write', 'Write to us')}</Link>
                </Button>
              )}
            </div>
          )}
        </>
      )}
    </div>
  );
}
