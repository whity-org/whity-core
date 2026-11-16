'use client';

/**
 * /calendar/meetings/bodies (design 3.5, `TplMeetings`, "meeting bodies"):
 * the committees and boards that hold formal meetings, with the chair and
 * secretary of each. A body's key is quoted in every decision number and
 * cannot be changed once made, so the dialog says so.
 *
 * Needs `convening:read`; adding a body needs `convening:manage`.
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
import { meetingErrorText, meetingsApi, type ConveningBody, type ConveningBodyMember } from '@/lib/meetings/api';
import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Icon } from '@whity/ui/icon';
import { EmptyFirstTime } from '@whity/ui/page-states';
import { Skeleton } from '@whity/ui/skeleton';
import { useTranslation } from '@whity/features/i18n';
import { MeetingsNav, useMeetingAccess, usePeopleNames } from './meetings-shared';

type BodyWithMembers = ConveningBody & { members: ConveningBodyMember[] };

export function BodiesScreen() {
  const t = useTranslation('meetings');
  const access = useMeetingAccess();
  const [creating, setCreating] = useState(false);
  const { nameOf } = usePeopleNames();

  const bodies = useFetch<BodyWithMembers[]>(async () => {
    const list = await meetingsApi.bodies();
    return Promise.all(list.map((body) => meetingsApi.body(body.id)));
  }, []);

  if (!access.loading && !access.canRead) {
    return <NoAccess area={t('bodies.area', 'meeting bodies')} permission="convening:read" />;
  }

  const roleOf = (body: BodyWithMembers, role: ConveningBodyMember['member_role']) =>
    body.members.filter((m) => m.left_at === null && m.member_role === role).map((m) => nameOf(m.profile_id));

  return (
    <div className="space-y-4">
      <AdminHeader
        title={t('bodies.page.title', 'Meeting bodies')}
        description={t(
          'bodies.page.description',
          'A body is a committee or board with a chair, a secretary and members. Its meetings are numbered.'
        )}
        action={
          access.canManage ? (
            <Button onClick={() => setCreating(true)} data-testid="bodies-new">
              <Icon name="plus" />
              {t('bodies.new', 'New body')}
            </Button>
          ) : undefined
        }
      />
      <MeetingsNav active="bodies" />

      {bodies.error ? (
        <LoadProblem
          forbidden={bodies.forbidden}
          message={t('bodies.error.load', 'The meeting bodies could not be loaded.')}
          onRetry={bodies.refetch}
          area={t('bodies.area', 'meeting bodies')}
        />
      ) : bodies.data === null ? (
        <Skeleton
          className="h-48 w-full rounded-card"
          role="status"
          aria-busy="true"
          aria-label={t('bodies.loading', 'Loading the meeting bodies')}
        />
      ) : bodies.data.length === 0 ? (
        <EmptyFirstTime
          icon="users"
          title={t('bodies.empty.title', 'No meeting bodies yet')}
          description={t('bodies.empty.description', 'Add a board or a committee to hold formal meetings.')}
        />
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {bodies.data.map((body) => {
            const active = body.members.filter((m) => m.left_at === null);
            const chair = roleOf(body, 'chair');
            const secretary = roleOf(body, 'secretary');
            return (
              <Card key={body.id} data-testid={`body-${body.id}`}>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <span className="min-w-0 flex-1 truncate">{body.display_name}</span>
                    {!body.is_active && <Badge variant="outline">{t('bodies.off', 'Off')}</Badge>}
                  </CardTitle>
                  {body.description && <CardDescription>{body.description}</CardDescription>}
                </CardHeader>
                <CardContent className="space-y-3">
                  <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <dt className="text-muted-foreground">{t('bodies.chair', 'Chair')}</dt>
                    <dd className="flex items-center gap-2 text-foreground">
                      {chair.length === 0 ? '—' : chair.map((n) => <PersonChip key={n} name={n} />)}
                    </dd>
                    <dt className="text-muted-foreground">{t('bodies.secretary', 'Secretary')}</dt>
                    <dd className="flex items-center gap-2 text-foreground">
                      {secretary.length === 0 ? '—' : secretary.map((n) => <PersonChip key={n} name={n} />)}
                    </dd>
                    <dt className="text-muted-foreground">{t('bodies.members', 'Members')}</dt>
                    <dd className="text-foreground tabular-nums">{active.length}</dd>
                  </dl>
                  <Button asChild variant="outline" size="sm">
                    <Link href={`/calendar/meetings?body=${body.id}`}>{t('bodies.meetings', 'See its meetings')}</Link>
                  </Button>
                </CardContent>
              </Card>
            );
          })}
        </div>
      )}

      <NewBodyDialog open={creating} onOpenChange={setCreating} onSaved={bodies.refetch} />
    </div>
  );
}

function PersonChip({ name }: { name: string }) {
  return (
    <span className="inline-flex min-w-0 items-center gap-2">
      <Avatar name={name} size={24} />
      <span className="truncate">{name}</span>
    </span>
  );
}

function NewBodyDialog({
  open,
  onOpenChange,
  onSaved,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSaved: () => void;
}) {
  const t = useTranslation('meetings');
  const { addToast } = useToast();
  const [name, setName] = useState('');
  const [key, setKey] = useState('');
  const [description, setDescription] = useState('');
  const [busy, setBusy] = useState(false);
  const keyOk = /^[a-z0-9_-]+$/.test(key);

  const save = async () => {
    setBusy(true);
    try {
      await meetingsApi.createBody({
        body_key: key,
        name: name.trim(),
        ...(description.trim() !== '' ? { description: description.trim() } : {}),
      });
      setName('');
      setKey('');
      setDescription('');
      onOpenChange(false);
      onSaved();
    } catch (error) {
      addToast(meetingErrorText(error, t('bodies.error.create', 'The body could not be created. Try again.')), 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('bodies.create.title', 'New meeting body')}</DialogTitle>
          <DialogDescription>
            {t('bodies.create.description', 'The short key is written into every decision number and cannot be changed later.')}
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-3">
          <div>
            <label htmlFor="body-name" className="mb-1 block text-sm font-medium text-foreground">
              {t('bodies.create.name', 'Name')}
            </label>
            <Input id="body-name" value={name} onChange={(event) => setName(event.target.value)} />
          </div>
          <div>
            <label htmlFor="body-key" className="mb-1 block text-sm font-medium text-foreground">
              {t('bodies.create.key', 'Short key')}
            </label>
            <Input
              id="body-key"
              dir="ltr"
              value={key}
              onChange={(event) => setKey(event.target.value.toLowerCase())}
              placeholder="board"
              aria-invalid={key !== '' && !keyOk}
            />
            <p className="mt-1 text-sm text-muted-foreground">
              {t('bodies.create.keyHint', 'Lower-case letters, digits, - and _.')}
            </p>
          </div>
          <div>
            <label htmlFor="body-description" className="mb-1 block text-sm font-medium text-foreground">
              {t('bodies.create.purpose', 'What it is for')}
            </label>
            <Input id="body-description" value={description} onChange={(event) => setDescription(event.target.value)} />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('action.cancel', 'Cancel')}
          </Button>
          <Button onClick={() => void save()} disabled={busy || name.trim() === '' || !keyOk} data-testid="body-create">
            {t('bodies.create.save', 'Create body')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
