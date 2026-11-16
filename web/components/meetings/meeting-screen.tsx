'use client';

/**
 * /calendar/meetings/{id} (design 3.5, `TplMeeting`): one formal meeting.
 * Its facts (when, where, the body, who is invited) sit in the header; the
 * tabs hold the agenda, attendance and decisions. The time and place are the
 * meeting's calendar event (ADR 0018): scheduling here moves it on the
 * calendar and tells the guests.
 *
 * Reading needs `convening:read`; scheduling, inviting, the agenda and
 * attendance need `convening:manage`; a decision needs `convening:decide`.
 * Holding and cancelling a meeting cannot be undone, so each asks first.
 */

import Link from 'next/link';
import { useState } from 'react';
import { AdminHeader } from '@/components/admin/admin-header';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
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
import {
  meetingErrorText,
  meetingsApi,
  type MeetingAgendaItem,
  type MeetingDecision,
  type MeetingDetail,
  type MeetingVerdict,
} from '@/lib/meetings/api';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Checkbox } from '@whity/ui/checkbox';
import { Icon } from '@whity/ui/icon';
import { EmptyFirstTime } from '@whity/ui/page-states';
import { Skeleton } from '@whity/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { MeetingStatusBadge, MeetingsNav, useMeetingAccess, usePeopleNames, wallClock } from './meetings-shared';

type Confirming = 'hold' | 'cancel' | null;

export function MeetingScreen({ id }: { id: number }) {
  const t = useTranslation('meetings');
  const access = useMeetingAccess();
  const { addToast } = useToast();
  const dates = useDateDisplay();
  const { nameOf } = usePeopleNames();
  const meeting = useFetch(() => meetingsApi.get(id), [id]);
  const [scheduling, setScheduling] = useState(false);
  const [confirming, setConfirming] = useState<Confirming>(null);
  const [busy, setBusy] = useState(false);

  if (!access.loading && !access.canRead) {
    return <NoAccess area={t('area', 'meetings')} permission="convening:read" />;
  }

  const data = meeting.data;
  const when = wallClock(data?.scheduled_at);
  const open = data !== null && (data.status === 'draft' || data.status === 'scheduled');

  const run = async (action: () => Promise<unknown>, fallback: string) => {
    setBusy(true);
    try {
      await action();
      meeting.refetch();
    } catch (error) {
      addToast(meetingErrorText(error, fallback), 'error');
    } finally {
      setBusy(false);
      setConfirming(null);
    }
  };

  return (
    <div className="space-y-4">
      <AdminHeader
        title={
          data
            ? `${data.body?.display_name ?? data.display_title} · ${t('record.number', 'Meeting {n}', { n: data.meeting_number })}`
            : t('record.title', 'Meeting')
        }
        description={data?.display_title}
        action={
          data !== null && access.canManage && open ? (
            <>
              <Button variant="outline" onClick={() => setScheduling(true)} data-testid="meeting-schedule">
                <Icon name="calendar" />
                {data.scheduled_at ? t('action.reschedule', 'Change time') : t('action.schedule', 'Set the time')}
              </Button>
              {data.status === 'scheduled' && (
                <Button
                  variant="outline"
                  disabled={busy}
                  onClick={() => void run(() => meetingsApi.invite(id), t('error.invite', 'The invitations could not be sent.'))}
                  data-testid="meeting-invite"
                >
                  <Icon name="mail" />
                  {t('action.invite', 'Invite the body')}
                </Button>
              )}
              {data.status === 'scheduled' && (
                <Button onClick={() => setConfirming('hold')} data-testid="meeting-hold">
                  {t('action.hold', 'Mark as held')}
                </Button>
              )}
              <Button variant="outline" onClick={() => setConfirming('cancel')} data-testid="meeting-cancel">
                {t('action.cancelMeeting', 'Cancel meeting')}
              </Button>
            </>
          ) : undefined
        }
      />
      <MeetingsNav active="meetings" />

      {meeting.error ? (
        <LoadProblem
          forbidden={meeting.forbidden}
          message={t('record.error.load', 'The meeting could not be loaded.')}
          onRetry={meeting.refetch}
          area={t('area', 'meetings')}
        />
      ) : data === null ? (
        <Skeleton
          className="h-64 w-full rounded-card"
          role="status"
          aria-busy="true"
          aria-label={t('record.loading', 'Loading the meeting')}
        />
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-muted-foreground" data-testid="meeting-facts">
            <MeetingStatusBadge status={data.status} />
            <span className="inline-flex items-center gap-1.5">
              <Icon name="calendar" size={16} />
              {when === null ? (
                t('fact.notSet', 'Not set yet')
              ) : (
                <>
                  {dates.calendarLabel(when.date, 'fullDate') ?? when.date}
                  {' · '}
                  <span className="font-mono tabular-nums" dir="ltr">
                    {when.time}
                  </span>
                </>
              )}
            </span>
            {data.location && (
              <span className="inline-flex items-center gap-1.5">
                <Icon name="tenant" size={16} />
                {data.location}
              </span>
            )}
            {when !== null && (
              <Link href="/calendar" className="underline underline-offset-2">
                {t('fact.onCalendar', 'See it on the calendar')}
              </Link>
            )}
          </div>

          <Tabs defaultValue="agenda">
            <TabsList>
              <TabsTrigger value="agenda">
                {t('tab.agenda', 'Agenda')} <Count n={data.agenda.length} />
              </TabsTrigger>
              <TabsTrigger value="attendance">{t('tab.attendance', 'Attendance')}</TabsTrigger>
              <TabsTrigger value="decisions">
                {t('tab.decisions', 'Decisions')} <Count n={data.decisions.length} />
              </TabsTrigger>
            </TabsList>
            <TabsContent value="agenda" className="pt-4">
              <AgendaTab meeting={data} canManage={access.canManage} canDecide={access.canDecide} onChanged={meeting.refetch} />
            </TabsContent>
            <TabsContent value="attendance" className="pt-4">
              <AttendanceTab meeting={data} canManage={access.canManage} nameOf={nameOf} onChanged={meeting.refetch} />
            </TabsContent>
            <TabsContent value="decisions" className="pt-4">
              <DecisionsTab meeting={data} />
            </TabsContent>
          </Tabs>

          <ScheduleDialog
            open={scheduling}
            current={data.scheduled_at}
            location={data.location}
            onOpenChange={setScheduling}
            onSave={(scheduledAt, location) =>
              run(
                () => meetingsApi.schedule(id, { scheduled_at: scheduledAt, ...(location ? { location } : {}) }),
                t('error.schedule', 'The time could not be saved. Check the date and try again.')
              ).then(() => setScheduling(false))
            }
          />
          <Dialog open={confirming !== null} onOpenChange={(next) => (next ? undefined : setConfirming(null))}>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>
                  {confirming === 'hold' ? t('confirm.hold.title', 'Mark this meeting as held?') : t('confirm.cancel.title', 'Cancel this meeting?')}
                </DialogTitle>
                <DialogDescription>
                  {confirming === 'hold'
                    ? t('confirm.hold.description', 'This cannot be undone. Decisions can then be recorded and attendance taken.')
                    : t('confirm.cancel.description', 'The guests are told and it leaves the calendar. This cannot be undone.')}
                </DialogDescription>
              </DialogHeader>
              <DialogFooter>
                <Button variant="outline" onClick={() => setConfirming(null)}>
                  {t('action.keep', 'Not now')}
                </Button>
                <Button
                  disabled={busy}
                  onClick={() =>
                    void run(
                      () => (confirming === 'hold' ? meetingsApi.hold(id) : meetingsApi.cancel(id)),
                      t('error.change', 'The meeting could not be changed. Try again.')
                    )
                  }
                  data-testid="meeting-confirm"
                >
                  {confirming === 'hold' ? t('action.hold', 'Mark as held') : t('action.cancelMeeting', 'Cancel meeting')}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </>
      )}
    </div>
  );
}

function Count({ n }: { n: number }) {
  return n === 0 ? null : (
    <span className="ms-1 rounded-full bg-muted px-1.5 text-xs tabular-nums" dir="ltr">
      {n}
    </span>
  );
}

function AgendaTab({
  meeting,
  canManage,
  canDecide,
  onChanged,
}: {
  meeting: MeetingDetail;
  canManage: boolean;
  canDecide: boolean;
  onChanged: () => void;
}) {
  const t = useTranslation('meetings');
  const { addToast } = useToast();
  const [title, setTitle] = useState('');
  const [deciding, setDeciding] = useState<MeetingAgendaItem | null>(null);
  const editable = meeting.status === 'draft' || meeting.status === 'scheduled';
  const decisionFor = (item: MeetingAgendaItem) => meeting.decisions.find((d) => d.agenda_item_id === item.id);

  const add = async () => {
    try {
      await meetingsApi.addAgendaItem(meeting.id, { title: title.trim() });
      setTitle('');
      onChanged();
    } catch (error) {
      addToast(meetingErrorText(error, t('agenda.error.add', 'The item could not be added.')), 'error');
    }
  };
  const remove = async (item: MeetingAgendaItem) => {
    try {
      await meetingsApi.removeAgendaItem(meeting.id, item.id);
      onChanged();
    } catch (error) {
      addToast(meetingErrorText(error, t('agenda.error.remove', 'The item could not be removed.')), 'error');
    }
  };

  return (
    <div className="space-y-4">
      {meeting.agenda.length === 0 ? (
        <EmptyFirstTime
          icon="list-numbered"
          title={t('agenda.empty.title', 'The agenda is empty')}
          description={t('agenda.empty.description', 'Add the items the body will discuss, in order.')}
        />
      ) : (
        <ol className="divide-y divide-divider overflow-hidden rounded-card border border-border bg-card">
          {meeting.agenda.map((item, index) => {
            const decision = decisionFor(item);
            return (
              <li key={item.id} className="flex min-h-12 flex-wrap items-center gap-3 px-4 py-3" data-testid={`agenda-${item.id}`}>
                <span className="w-6 text-sm tabular-nums text-muted-foreground" dir="ltr">
                  {index + 1}
                </span>
                <span className="min-w-0 flex-1 basis-40">
                  <span className="block text-sm font-medium text-foreground">{item.display_title}</span>
                  {item.notes && <span className="block text-sm text-muted-foreground">{item.notes}</span>}
                </span>
                {decision ? (
                  <VerdictBadge decision={decision} />
                ) : meeting.status === 'held' && canDecide ? (
                  <Button variant="outline" size="sm" onClick={() => setDeciding(item)} data-testid={`agenda-decide-${item.id}`}>
                    {t('agenda.decide', 'Record decision')}
                  </Button>
                ) : null}
                {canManage && editable && (
                  <Button variant="outline" size="sm" onClick={() => void remove(item)} data-testid={`agenda-remove-${item.id}`}>
                    {t('agenda.remove', 'Remove')}
                  </Button>
                )}
              </li>
            );
          })}
        </ol>
      )}

      {canManage && editable && (
        <form
          className="flex flex-wrap items-end gap-2"
          onSubmit={(event) => {
            event.preventDefault();
            if (title.trim() !== '') void add();
          }}
        >
          <div className="min-w-56 flex-1">
            <label htmlFor="agenda-title" className="mb-1 block text-sm font-medium text-foreground">
              {t('agenda.new', 'New agenda item')}
            </label>
            <Input id="agenda-title" value={title} onChange={(event) => setTitle(event.target.value)} />
          </div>
          <Button type="submit" variant="outline" disabled={title.trim() === ''} data-testid="agenda-add">
            <Icon name="plus" />
            {t('agenda.add', 'Add')}
          </Button>
        </form>
      )}

      <DecideDialog
        meetingId={meeting.id}
        item={deciding}
        onOpenChange={(next) => (next ? undefined : setDeciding(null))}
        onDone={() => {
          setDeciding(null);
          onChanged();
        }}
      />
    </div>
  );
}

function useVerdictWords() {
  const t = useTranslation('meetings');
  return (verdict: MeetingVerdict) =>
    ({
      approved: t('verdict.approved', 'Approved'),
      rejected: t('verdict.rejected', 'Rejected'),
      deferred: t('verdict.deferred', 'Deferred'),
    })[verdict];
}

function VerdictBadge({ decision }: { decision: MeetingDecision }) {
  const words = useVerdictWords();
  const variant = decision.verdict === 'approved' ? 'success' : decision.verdict === 'rejected' ? 'destructive' : 'warning';
  return <Badge variant={variant}>{words(decision.verdict)}</Badge>;
}

function DecideDialog({
  meetingId,
  item,
  onOpenChange,
  onDone,
}: {
  meetingId: number;
  item: MeetingAgendaItem | null;
  onOpenChange: (open: boolean) => void;
  onDone: () => void;
}) {
  const t = useTranslation('meetings');
  const words = useVerdictWords();
  const { addToast } = useToast();
  const [verdict, setVerdict] = useState<MeetingVerdict>('approved');
  const [rationale, setRationale] = useState('');
  const [busy, setBusy] = useState(false);

  const save = async () => {
    if (item === null) return;
    setBusy(true);
    try {
      await meetingsApi.decide(meetingId, item.id, { verdict, ...(rationale.trim() ? { rationale: rationale.trim() } : {}) });
      setRationale('');
      onDone();
    } catch (error) {
      addToast(meetingErrorText(error, t('decision.error', 'The decision could not be recorded.')), 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={item !== null} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('decision.title', 'Record a decision')}</DialogTitle>
          <DialogDescription>{item?.display_title}</DialogDescription>
        </DialogHeader>
        <div className="space-y-3">
          <div role="group" aria-label={t('decision.verdict', 'Decision')} className="flex flex-wrap gap-2">
            {(['approved', 'rejected', 'deferred'] as const).map((v) => (
              <Button
                key={v}
                type="button"
                size="sm"
                variant={verdict === v ? 'default' : 'outline'}
                aria-pressed={verdict === v}
                onClick={() => setVerdict(v)}
              >
                {words(v)}
              </Button>
            ))}
          </div>
          <div>
            <label htmlFor="decision-why" className="mb-1 block text-sm font-medium text-foreground">
              {t('decision.why', 'Reason (optional)')}
            </label>
            <Input id="decision-why" value={rationale} onChange={(event) => setRationale(event.target.value)} />
          </div>
          <Alert>
            <AlertDescription>
              {t('decision.note', 'A decision gets the next number and can approve or reject the document the item is about. It is final.')}
            </AlertDescription>
          </Alert>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('action.cancel', 'Cancel')}
          </Button>
          <Button disabled={busy} onClick={() => void save()} data-testid="decision-save">
            {t('decision.save', 'Record decision')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function AttendanceTab({
  meeting,
  canManage,
  nameOf,
  onChanged,
}: {
  meeting: MeetingDetail;
  canManage: boolean;
  nameOf: (id: number | null | undefined) => string;
  onChanged: () => void;
}) {
  const t = useTranslation('meetings');
  const { addToast } = useToast();
  const held = meeting.status === 'held';
  const [present, setPresent] = useState<Set<number>>(
    () => new Set(meeting.attendance.filter((a) => a.profile_id !== null).map((a) => a.profile_id as number))
  );
  const [busy, setBusy] = useState(false);
  const answerWords: Record<string, string> = {
    invited: t('answer.invited', 'No answer yet'),
    accepted: t('answer.accepted', 'Coming'),
    declined: t('answer.declined', 'Not coming'),
    tentative: t('answer.tentative', 'Maybe'),
  };

  const save = async () => {
    setBusy(true);
    try {
      const guests = meeting.attendance.filter((a) => a.profile_id === null);
      await meetingsApi.recordAttendance(meeting.id, [
        ...[...present].map((profile_id) => ({ profile_id, capacity: 'member' as const })),
        ...guests.map((g) => ({ attendee_name: g.attendee_name, capacity: g.capacity })),
      ]);
      onChanged();
    } catch (error) {
      addToast(meetingErrorText(error, t('attendance.error', 'The attendance could not be saved.')), 'error');
    } finally {
      setBusy(false);
    }
  };

  if (meeting.invitations.length === 0 && meeting.attendance.length === 0) {
    return (
      <EmptyFirstTime
        icon="users"
        title={t('attendance.empty.title', 'Nobody invited yet')}
        description={t('attendance.empty.description', 'Set the time, then invite the body. Attendance is taken after the meeting.')}
      />
    );
  }

  return (
    <div className="space-y-4">
      <ul className="divide-y divide-divider overflow-hidden rounded-card border border-border bg-card">
        {meeting.invitations.map((invitation) => {
          const name = nameOf(invitation.profile_id);
          return (
            <li key={invitation.id} className="flex min-h-12 items-center gap-3 px-4 py-2">
              <Avatar name={name} size={24} />
              <span className="min-w-0 flex-1 truncate text-sm font-medium text-foreground">{name}</span>
              <span className="text-sm text-muted-foreground">{answerWords[invitation.status]}</span>
              {held && canManage && (
                <label className="flex items-center gap-2 text-sm text-foreground">
                  <Checkbox
                    checked={present.has(invitation.profile_id)}
                    onCheckedChange={(on) =>
                      setPresent((prev) => {
                        const next = new Set(prev);
                        if (on === true) next.add(invitation.profile_id);
                        else next.delete(invitation.profile_id);
                        return next;
                      })
                    }
                  />
                  {t('attendance.present', 'Present')}
                </label>
              )}
            </li>
          );
        })}
        {meeting.attendance
          .filter((a) => a.profile_id === null)
          .map((guest) => (
            <li key={guest.id} className="flex min-h-12 items-center gap-3 px-4 py-2">
              <Avatar name={guest.attendee_name ?? ''} size={24} />
              <span className="min-w-0 flex-1 truncate text-sm font-medium text-foreground">{guest.attendee_name}</span>
              <Badge variant="outline">{t('attendance.guest', 'Guest')}</Badge>
            </li>
          ))}
      </ul>
      {held && canManage && (
        <Button onClick={() => void save()} disabled={busy} data-testid="attendance-save">
          {t('attendance.save', 'Save attendance')}
        </Button>
      )}
      {!held && (
        <p className="text-sm text-muted-foreground">
          {t('attendance.later', 'Who was there is recorded after the meeting is held.')}
        </p>
      )}
    </div>
  );
}

function DecisionsTab({ meeting }: { meeting: MeetingDetail }) {
  const t = useTranslation('meetings');
  const dates = useDateDisplay();
  if (meeting.decisions.length === 0) {
    return (
      <EmptyFirstTime
        icon="check"
        title={t('decisions.empty.title', 'No decisions yet')}
        description={t('decisions.empty.description', 'Decisions are recorded against agenda items once the meeting is held.')}
      />
    );
  }
  const itemTitle = (decision: MeetingDecision) =>
    meeting.agenda.find((a) => a.id === decision.agenda_item_id)?.display_title ?? '';
  return (
    <ul className="divide-y divide-divider overflow-hidden rounded-card border border-border bg-card">
      {meeting.decisions.map((decision) => (
        <li key={decision.id} className="flex min-h-12 flex-wrap items-center gap-3 px-4 py-3" data-testid={`decision-${decision.id}`}>
          <span className="w-24 shrink-0 font-mono text-sm tabular-nums text-foreground" dir="ltr">
            {decision.decision_number}
          </span>
          <span className="min-w-0 flex-1 basis-40">
            <span className="block text-sm font-medium text-foreground">{itemTitle(decision)}</span>
            {decision.rationale && <span className="block text-sm text-muted-foreground">{decision.rationale}</span>}
          </span>
          <span className="text-sm text-muted-foreground">{dates.date(decision.decided_at) ?? ''}</span>
          <VerdictBadge decision={decision} />
        </li>
      ))}
    </ul>
  );
}

function ScheduleDialog({
  open,
  current,
  location,
  onOpenChange,
  onSave,
}: {
  open: boolean;
  current: string | null;
  location: string | null;
  onOpenChange: (open: boolean) => void;
  onSave: (scheduledAt: string, location: string) => Promise<unknown>;
}) {
  const t = useTranslation('meetings');
  const [when, setWhen] = useState(current ? current.slice(0, 16).replace(' ', 'T') : '');
  const [where, setWhere] = useState(location ?? '');
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('schedule.title', 'When and where')}</DialogTitle>
          <DialogDescription>
            {t('schedule.description', 'It goes on the calendar and the body is told. Changing it later tells them again.')}
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-3">
          <div>
            <label htmlFor="meeting-when" className="mb-1 block text-sm font-medium text-foreground">
              {t('schedule.when', 'Date and time')}
            </label>
            <Input id="meeting-when" type="datetime-local" dir="ltr" value={when} onChange={(event) => setWhen(event.target.value)} />
          </div>
          <div>
            <label htmlFor="meeting-where" className="mb-1 block text-sm font-medium text-foreground">
              {t('schedule.where', 'Where')}
            </label>
            <Input id="meeting-where" value={where} onChange={(event) => setWhere(event.target.value)} />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('action.cancel', 'Cancel')}
          </Button>
          <Button disabled={when === ''} onClick={() => void onSave(when.replace('T', ' '), where.trim())} data-testid="schedule-save">
            {t('schedule.save', 'Save')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
