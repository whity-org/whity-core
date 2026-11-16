'use client';

/**
 * /calendar/meetings (design 3.5, `TplMeetings`): the formal meetings of the
 * workspace's bodies, Upcoming and Past, grouped by day, with the bodies
 * beside the list. A meeting's time and place live on its calendar event
 * (ADR 0018), so every meeting here is also on the calendar; everyday
 * meetings are only there (Also show > Meetings).
 *
 * Needs `convening:read`; creating a meeting needs `convening:manage`.
 */

import Link from 'next/link';
import { useMemo, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { AdminHeader } from '@/components/admin/admin-header';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { FieldSelect } from '@/components/field-select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { meetingErrorText, meetingsApi, type ConveningBody, type Meeting } from '@/lib/meetings/api';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Icon } from '@whity/ui/icon';
import { EmptyFirstTime } from '@whity/ui/page-states';
import { Skeleton } from '@whity/ui/skeleton';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { cn } from '@/lib/utils';
import { MeetingStatusBadge, MeetingsNav, useMeetingAccess } from './meetings-shared';

type Tab = 'upcoming' | 'past';
const ALL = 'all';

/** The date part of `2026-11-04 10:00:00` (or ISO), or null when there is no time yet. */
function dayOf(meeting: Meeting): string | null {
  return meeting.scheduled_at === null ? null : meeting.scheduled_at.slice(0, 10);
}

function isPast(meeting: Meeting): boolean {
  return meeting.status === 'held' || meeting.status === 'cancelled';
}

export function MeetingsScreen() {
  const t = useTranslation('meetings');
  const access = useMeetingAccess();
  const [tab, setTab] = useState<Tab>('upcoming');
  const [bodyId, setBodyId] = useState<string>(useSearchParams().get('body') ?? ALL);
  const [creating, setCreating] = useState(false);

  const meetings = useFetch(() => meetingsApi.list(), []);
  const bodies = useFetch(() => meetingsApi.bodies(), []);

  if (!access.loading && !access.canRead) {
    return <NoAccess area={t('area', 'meetings')} permission="convening:read" />;
  }

  const bodyList = bodies.data ?? [];
  const rows = (meetings.data ?? []).filter(
    (m) => (bodyId === ALL || m.body_id === Number(bodyId)) && isPast(m) === (tab === 'past')
  );

  return (
    <div className="space-y-4">
      <AdminHeader
        title={t('title', 'Meetings')}
        description={t(
          'description',
          'The formal meetings of your committees and boards. Each one is on the calendar too.'
        )}
        action={
          access.canManage ? (
            <Button onClick={() => setCreating(true)} data-testid="meetings-new" disabled={bodyList.length === 0}>
              <Icon name="plus" />
              {t('new', 'New meeting')}
            </Button>
          ) : undefined
        }
      />
      <MeetingsNav active="meetings" />

      {meetings.error ? (
        <LoadProblem
          forbidden={meetings.forbidden}
          message={t('error.load', 'The meetings could not be loaded.')}
          onRetry={meetings.refetch}
          area={t('area', 'meetings')}
        />
      ) : (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
          <section aria-label={t('list.label', 'Meetings')} className="min-w-0 space-y-4">
            <div className="flex flex-wrap items-center gap-2">
              <div role="group" aria-label={t('filter.when', 'Show')} className="flex gap-2">
                {(['upcoming', 'past'] as const).map((id) => (
                  <Button
                    key={id}
                    type="button"
                    variant={tab === id ? 'default' : 'outline'}
                    size="sm"
                    aria-pressed={tab === id}
                    onClick={() => setTab(id)}
                    data-testid={`meetings-tab-${id}`}
                  >
                    {id === 'upcoming' ? t('tab.upcoming', 'Upcoming') : t('tab.past', 'Past')}
                  </Button>
                ))}
              </div>
              {bodyList.length > 1 && (
                <div className="min-w-48">
                  <FieldSelect
                    id="meetings-body-filter"
                    value={bodyId}
                    onChange={setBodyId}
                    options={[
                      { value: ALL, label: t('filter.allBodies', 'All bodies') },
                      ...bodyList.map((b) => ({ value: String(b.id), label: b.display_name })),
                    ]}
                  />
                </div>
              )}
            </div>

            {meetings.data === null ? (
              <Skeleton
                className="h-48 w-full rounded-card"
                role="status"
                aria-busy="true"
                aria-label={t('loading', 'Loading the meetings')}
              />
            ) : rows.length === 0 ? (
              <EmptyFirstTime
                icon="calendar"
                title={
                  tab === 'upcoming'
                    ? t('empty.upcoming.title', 'No meetings coming up')
                    : t('empty.past.title', 'No past meetings')
                }
                description={
                  bodyList.length === 0
                    ? t('empty.noBodies', 'Add a meeting body first, such as a board or a committee.')
                    : t('empty.description', 'A formal meeting belongs to a body and has an agenda, attendance and decisions.')
                }
              />
            ) : (
              <MeetingDays rows={rows} bodies={bodyList} descending={tab === 'past'} />
            )}
          </section>

          <aside className="space-y-4">
            <BodiesCard bodies={bodyList} loading={bodies.data === null && !bodies.error} />
          </aside>
        </div>
      )}

      <NewMeetingDialog open={creating} bodies={bodyList} onOpenChange={setCreating} />
    </div>
  );
}

function MeetingDays({ rows, bodies, descending }: { rows: Meeting[]; bodies: ConveningBody[]; descending: boolean }) {
  const t = useTranslation('meetings');
  const dates = useDateDisplay();
  const days = useMemo(() => {
    const groups = new Map<string, Meeting[]>();
    for (const meeting of rows) {
      const key = dayOf(meeting) ?? '';
      groups.set(key, [...(groups.get(key) ?? []), meeting]);
    }
    return [...groups.entries()].sort(([a], [b]) => {
      if (a === '' || b === '') return a === '' ? 1 : -1;
      return descending ? b.localeCompare(a) : a.localeCompare(b);
    });
  }, [rows, descending]);
  const bodyName = (id: number) => bodies.find((b) => b.id === id)?.display_name ?? '';

  return (
    <div className="space-y-5">
      {days.map(([day, meetings]) => (
        <div key={day || 'undated'} className="space-y-2">
          <h2 className="text-sm font-semibold text-muted-foreground">
            {day === '' ? t('day.unscheduled', 'Not scheduled yet') : (dates.calendarLabel(day, 'fullDate') ?? day)}
          </h2>
          <ul className="divide-y divide-divider overflow-hidden rounded-card border border-border bg-card">
            {meetings.map((meeting) => (
              <li key={meeting.id}>
                <Link
                  href={`/calendar/meetings/${meeting.id}`}
                  data-testid={`meeting-${meeting.id}`}
                  className={cn(
                    'flex min-h-12 flex-wrap items-center gap-x-3 gap-y-1 border-s-4 border-s-info px-4 py-3 outline-none hover:bg-muted focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring',
                    meeting.status === 'cancelled' && 'opacity-70'
                  )}
                >
                  <span className="w-14 shrink-0 text-sm tabular-nums text-muted-foreground" dir="ltr">
                    {meeting.scheduled_at ? (dates.time(meeting.scheduled_at) ?? '') : '—'}
                  </span>
                  <span className="min-w-0 flex-1 basis-40">
                    <span className="block truncate text-sm font-medium text-foreground">{meeting.display_title}</span>
                    <span className="block truncate text-sm text-muted-foreground">
                      {[bodyName(meeting.body_id), meeting.location].filter(Boolean).join(' · ')}
                    </span>
                  </span>
                  <Badge variant="secondary">{t('formal', 'Formal')}</Badge>
                  <MeetingStatusBadge status={meeting.status} />
                </Link>
              </li>
            ))}
          </ul>
        </div>
      ))}
    </div>
  );
}

function BodiesCard({ bodies, loading }: { bodies: ConveningBody[]; loading: boolean }) {
  const t = useTranslation('meetings');
  return (
    <Card data-testid="meetings-bodies-card">
      <CardHeader>
        <CardTitle>{t('bodies.title', 'Meeting bodies')}</CardTitle>
        <CardDescription>{t('bodies.description', 'Committees and boards that hold formal meetings.')}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {loading ? (
          <Skeleton className="h-20 w-full rounded-control" />
        ) : bodies.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('bodies.none', 'No bodies yet.')}</p>
        ) : (
          <ul className="divide-y divide-divider">
            {bodies.map((body) => (
              <li key={body.id} className="flex min-h-12 items-center gap-2 py-2">
                <span className="min-w-0 flex-1 truncate text-sm font-medium text-foreground">{body.display_name}</span>
                {!body.is_active && <Badge variant="outline">{t('bodies.off', 'Off')}</Badge>}
              </li>
            ))}
          </ul>
        )}
        <Button asChild variant="outline" size="sm">
          <Link href="/calendar/meetings/bodies">{t('bodies.manage', 'See all bodies')}</Link>
        </Button>
      </CardContent>
    </Card>
  );
}

function NewMeetingDialog({
  open,
  bodies,
  onOpenChange,
}: {
  open: boolean;
  bodies: ConveningBody[];
  onOpenChange: (open: boolean) => void;
}) {
  const t = useTranslation('meetings');
  const router = useRouter();
  const { addToast } = useToast();
  const [body, setBody] = useState('');
  const [title, setTitle] = useState('');
  const [busy, setBusy] = useState(false);
  const active = bodies.filter((b) => b.is_active);
  const chosen = body !== '' ? body : active.length === 1 ? String(active[0].id) : '';

  const save = async () => {
    setBusy(true);
    try {
      const meeting = await meetingsApi.create({ body_id: Number(chosen), title: title.trim() });
      onOpenChange(false);
      setTitle('');
      router.push(`/calendar/meetings/${meeting.id}`);
    } catch (error) {
      addToast(meetingErrorText(error, t('error.create', 'The meeting could not be created. Try again.')), 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('create.title', 'New meeting')}</DialogTitle>
          <DialogDescription>
            {t('create.description', 'It starts as a draft. Set the time and place on the next page, then invite the body.')}
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-3">
          <div>
            <label htmlFor="meeting-body" className="mb-1 block text-sm font-medium text-foreground">
              {t('create.body', 'Meeting body')}
            </label>
            <FieldSelect
              id="meeting-body"
              value={chosen}
              onChange={setBody}
              placeholder={t('create.bodyPlaceholder', 'Which body is meeting')}
              options={active.map((b) => ({ value: String(b.id), label: b.display_name }))}
            />
          </div>
          <div>
            <label htmlFor="meeting-title" className="mb-1 block text-sm font-medium text-foreground">
              {t('create.name', 'Title')}
            </label>
            <Input id="meeting-title" value={title} onChange={(event) => setTitle(event.target.value)} />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('action.cancel', 'Cancel')}
          </Button>
          <Button
            onClick={() => void save()}
            disabled={busy || chosen === '' || title.trim() === ''}
            data-testid="meeting-create"
          >
            {t('create.save', 'Create meeting')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
