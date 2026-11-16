'use client';

/**
 * What a customer did here (Contacts as the one customer record): their
 * bookings, chats, forms and requests to join, newest first. A section the
 * reader may not see isn't sent by the server, so it isn't shown.
 */

import Link from 'next/link';
import { Icon } from '@whity/ui/icon';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';

type Title = string | Record<string, string>;

interface ActivityItem {
  id: number;
  title: Title;
  status: string;
  at: string;
}

type Section = 'bookings' | 'chats' | 'forms' | 'join_requests';

type Activity = Partial<Record<Section, ActivityItem[]>>;

const ORDER: Section[] = ['bookings', 'chats', 'forms', 'join_requests'];

function hrefFor(section: Section, item: ActivityItem): string | null {
  switch (section) {
    case 'chats':
      return `/chat?c=${item.id}&k=customer`;
    case 'forms':
      return `/admin/routes/form_submission/${item.id}`;
    case 'join_requests':
      return '/admin/settings/customer-signup';
    default:
      return null;
  }
}

export function CustomerActivity({ contactId }: { contactId: number }) {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const { dateTime } = useDateDisplay();
  const { data, loading } = useFetch<Activity>(async () => {
    const { data: body, response } = await api.GET('/api/v1/contacts/{id}/activity', { params: { path: { id: contactId } } });
    if (!response.ok) return {};
    return ((body as unknown as { data?: Activity }).data ?? {}) as Activity;
  }, [contactId]);

  const heading: Record<Section, string> = {
    bookings: t('contacts.activity.bookings', 'Bookings'),
    chats: t('contacts.activity.chats', 'Chats'),
    forms: t('contacts.activity.forms', 'Forms they sent'),
    join_requests: t('contacts.activity.joinRequests', 'Requests to join'),
  };
  const titleOf = (title: Title): string =>
    typeof title === 'string' ? title : (title[language] ?? title.en ?? title.ar ?? Object.values(title)[0] ?? '');

  if (loading) {
    return <div className="h-24 animate-pulse rounded-lg bg-muted" aria-busy="true" />;
  }
  const sections = ORDER.filter((s) => data?.[s] !== undefined);
  if (sections.length === 0) {
    return null;
  }

  return (
    <section className="space-y-4 rounded-lg border border-border p-5" data-testid="customer-activity">
      <h2 className="flex items-center gap-2 text-sm font-semibold text-muted-foreground">
        <Icon name="activity" size={16} />
        {t('contacts.activity.title', 'What they did here')}
      </h2>
      {sections.map((section) => {
        const items = data?.[section] ?? [];
        return (
          <div key={section} className="space-y-1">
            <h3 className="text-sm font-medium">{heading[section]}</h3>
            {items.length === 0 ? (
              <p className="text-sm text-muted-foreground">{t('contacts.activity.none', 'None yet.')}</p>
            ) : (
              <ul className="divide-y divide-border">
                {items.map((item) => {
                  const href = hrefFor(section, item);
                  const label = titleOf(item.title) || '–';
                  return (
                    <li key={item.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                      {href !== null ? (
                        <Link href={href} className="text-primary hover:underline">
                          {label}
                        </Link>
                      ) : (
                        <span>{label}</span>
                      )}
                      <span className="text-muted-foreground" dir="ltr">
                        {item.at !== '' ? dateTime(item.at) ?? '' : ''}
                      </span>
                    </li>
                  );
                })}
              </ul>
            )}
          </div>
        );
      })}
    </section>
  );
}
