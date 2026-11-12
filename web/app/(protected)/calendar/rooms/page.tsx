'use client';

/**
 * /calendar/rooms (design 3.3, `TplAppointments`): the rooms a service can
 * need, and which branch each person and room belongs to.
 *
 * A room is booked with the person: a service that needs it holds it, so two
 * people can't use one room at once. A branch is an organisational unit; the
 * calendar offers a branch filter as soon as someone or some room is in one.
 *
 * Anyone who can read the calendar sees this page; changes need
 * `calendar:manage`. A room is never deleted (bookings keep its name): it is
 * turned off. Each change saves on its own and can be changed back, so
 * nothing asks first (section 21).
 */

import { useState } from 'react';
import { AdminHeader } from '@/components/admin/admin-header';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { FieldSelect } from '@/components/field-select';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { CalendarNav } from '@/components/calendar/calendar-nav';
import { useCalendarAccess, useCalendarBasics, type CalendarUnit } from '@/components/calendar/use-calendar-basics';
import { useCalendarWords } from '@/components/calendar/use-calendar-words';
import { useLocalizedUnits } from '@/hooks/useLocalizedUnits';
import { useToast } from '@/lib/toast-context';
import { cleanNames } from '@/lib/localized-name';
import { calendarApi, type CalendarRoom, type CalendarStaff } from '@/lib/calendar/api';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Avatar } from '@whity/ui/avatar';
import { BilingualInput, type BilingualValue } from '@whity/ui/bilingual-input';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Icon } from '@whity/ui/icon';
import { EmptyFirstTime, ReadOnlyNotice } from '@whity/ui/page-states';
import { Skeleton } from '@whity/ui/skeleton';
import { Switch } from '@whity/ui/switch';
import { useTranslation } from '@whity/features/i18n';

const NO_BRANCH = 'none';

export default function CalendarRoomsPage() {
  const t = useTranslation('calendar');
  const access = useCalendarAccess();
  const basics = useCalendarBasics();
  const [editing, setEditing] = useState<CalendarRoom | 'new' | null>(null);

  if (!access.loading && !access.canRead) {
    return <NoAccess area={t('rooms.area', 'rooms')} permission="calendar:read" />;
  }

  return (
    <div className="space-y-4">
      <AdminHeader
        title={t('rooms.title', 'Rooms and branches')}
        description={t('rooms.description', 'Rooms a service needs are booked with the person. Branches filter the calendar.')}
        action={
          access.canManage ? (
            <Button onClick={() => setEditing('new')} data-testid="rooms-new">
              <Icon name="plus" />
              {t('rooms.new', 'New room')}
            </Button>
          ) : undefined
        }
      />
      <CalendarNav active="rooms" />
      {!access.loading && !access.canManage && (
        <ReadOnlyNotice
          title={t('rooms.readOnly.title', 'You can see the rooms but not change them')}
          description={t('rooms.readOnly.description', 'Ask someone who manages the calendar.')}
        />
      )}
      {basics.error ? (
        <LoadProblem
          forbidden={basics.forbidden}
          message={t('rooms.error.load', 'The rooms could not be loaded.')}
          onRetry={basics.refetch}
          area={t('rooms.area', 'rooms')}
        />
      ) : basics.data === null ? (
        <Skeleton className="h-64 w-full rounded-card" role="status" aria-busy="true" aria-label={t('rooms.loading', 'Loading the rooms')} />
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          <RoomsCard
            rooms={basics.data.rooms}
            units={basics.data.units}
            canManage={access.canManage}
            onEdit={setEditing}
            onChanged={basics.refetch}
          />
          <StaffBranchesCard staff={basics.data.staff} units={basics.data.units} canManage={access.canManage} onChanged={basics.refetch} />
        </div>
      )}
      {basics.data !== null && (
        <RoomDialog
          room={editing}
          units={basics.data.units}
          onOpenChange={(open) => (open ? undefined : setEditing(null))}
          onSaved={() => {
            setEditing(null);
            basics.refetch();
          }}
        />
      )}
    </div>
  );
}

function RoomsCard({
  rooms,
  units,
  canManage,
  onEdit,
  onChanged,
}: {
  rooms: CalendarRoom[];
  units: CalendarUnit[];
  canManage: boolean;
  onEdit: (room: CalendarRoom) => void;
  onChanged: () => void;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const { addToast } = useToast();
  const named = useLocalizedUnits(units);
  const unitName = (id: number | null) => (id === null ? null : named.find((u) => u.id === id)?.name ?? null);

  const toggle = async (room: CalendarRoom, active: boolean) => {
    try {
      await calendarApi.updateRoom(room.id, { active });
      onChanged();
    } catch (error) {
      addToast(words.errorText(error, t('rooms.error.save', 'The room could not be saved. Try again.')), 'error');
    }
  };

  return (
    <Card data-testid="rooms-card">
      <CardHeader>
        <CardTitle>{t('rooms.card.title', 'Rooms')}</CardTitle>
        <CardDescription>{t('rooms.card.description', 'Choose the room a service needs on the service itself.')}</CardDescription>
      </CardHeader>
      <CardContent>
        {rooms.length === 0 ? (
          <EmptyFirstTime
            icon="tenant"
            title={t('rooms.empty.title', 'No rooms yet')}
            description={t('rooms.empty.description', 'Add a room when a service needs one, such as a tasting room.')}
          />
        ) : (
          <ul className="divide-y divide-divider">
            {rooms.map((room) => (
              <li key={room.id} className="flex min-h-12 items-center gap-3 py-2" data-testid={`room-${room.id}`}>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium text-foreground">{words.label(room.name)}</p>
                  <p className="text-sm text-muted-foreground">{unitName(room.unit_id) ?? t('rooms.noBranch', 'No branch')}</p>
                </div>
                {canManage && (
                  <>
                    <Switch
                      checked={room.active}
                      onCheckedChange={(active) => void toggle(room, active)}
                      aria-label={t('rooms.activeLabel', 'Bookable: {name}', { name: words.label(room.name) })}
                    />
                    <Button variant="outline" size="sm" onClick={() => onEdit(room)} data-testid={`room-edit-${room.id}`}>
                      {t('rooms.edit', 'Edit')}
                    </Button>
                  </>
                )}
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

function StaffBranchesCard({
  staff,
  units,
  canManage,
  onChanged,
}: {
  staff: CalendarStaff[];
  units: CalendarUnit[];
  canManage: boolean;
  onChanged: () => void;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const { addToast } = useToast();
  const named = useLocalizedUnits(units);

  const save = async (person: CalendarStaff, value: string) => {
    try {
      await calendarApi.saveStaff(person.profile_id, { unit_id: value === NO_BRANCH ? null : Number(value) });
      addToast(t('rooms.staffSaved', 'Saved'), 'success');
      onChanged();
    } catch (error) {
      addToast(words.errorText(error, t('rooms.error.staff', 'That person could not be updated. Try again.')), 'error');
    }
  };

  return (
    <Card data-testid="staff-branches-card">
      <CardHeader>
        <CardTitle>{t('rooms.staff.title', 'Who works where')}</CardTitle>
        <CardDescription>{t('rooms.staff.description', 'The branch each person works in. Branches are your organisational units.')}</CardDescription>
      </CardHeader>
      <CardContent>
        {units.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('rooms.staff.noUnits', 'There are no organisational units to use as branches, or you can’t see them.')}</p>
        ) : staff.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('services.staff.noTeam', 'Nobody has a calendar yet.')}</p>
        ) : (
          <ul className="divide-y divide-divider">
            {staff.map((person) => (
              <li key={person.profile_id} className="flex min-h-12 flex-wrap items-center gap-3 py-2">
                <Avatar name={person.display_name} size={28} />
                <span className="min-w-0 flex-1 truncate text-sm font-medium text-foreground">{person.display_name}</span>
                <div className="w-full sm:w-56">
                  <label htmlFor={`staff-branch-${person.profile_id}`} className="sr-only">
                    {t('rooms.staff.branchOf', 'Branch for {name}', { name: person.display_name })}
                  </label>
                  <FieldSelect
                    id={`staff-branch-${person.profile_id}`}
                    value={person.unit_id === null || person.unit_id === undefined ? NO_BRANCH : String(person.unit_id)}
                    onChange={(value) => void save(person, value)}
                    disabled={!canManage}
                    options={[
                      { value: NO_BRANCH, label: t('rooms.noBranch', 'No branch') },
                      ...named.map((u) => ({ value: String(u.id), label: u.name })),
                    ]}
                  />
                </div>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

function RoomDialog({
  room,
  units,
  onOpenChange,
  onSaved,
}: {
  room: CalendarRoom | 'new' | null;
  units: CalendarUnit[];
  onOpenChange: (open: boolean) => void;
  onSaved: () => void;
}) {
  const open = room !== null;
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent data-testid="room-dialog">
        {open && <RoomForm key={room === 'new' ? 'new' : room.id} room={room === 'new' ? null : room} units={units} onCancel={() => onOpenChange(false)} onSaved={onSaved} />}
      </DialogContent>
    </Dialog>
  );
}

function RoomForm({
  room,
  units,
  onCancel,
  onSaved,
}: {
  room: CalendarRoom | null;
  units: CalendarUnit[];
  onCancel: () => void;
  onSaved: () => void;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const { addToast } = useToast();
  const named = useLocalizedUnits(units);
  const [name, setName] = useState<BilingualValue>(room ? { ...room.name } : {});
  const [unit, setUnit] = useState<string>(room?.unit_id ? String(room.unit_id) : NO_BRANCH);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const save = async () => {
    const names = cleanNames(name);
    if (Object.keys(names).length === 0) {
      setProblem(t('rooms.error.name', 'Give the room a name.'));
      return;
    }
    setSaving(true);
    setProblem(null);
    const body = { name: names, unit_id: unit === NO_BRANCH ? null : Number(unit) };
    try {
      if (room === null) await calendarApi.createRoom(body);
      else await calendarApi.updateRoom(room.id, body);
      addToast(room === null ? t('rooms.added', 'Room added') : t('rooms.saved', 'Room saved'), 'success');
      onSaved();
    } catch (error) {
      setProblem(words.errorText(error, t('rooms.error.save', 'The room could not be saved. Try again.')));
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <DialogHeader>
        <DialogTitle>{room === null ? t('rooms.new', 'New room') : t('rooms.editTitle', 'Edit room')}</DialogTitle>
        <DialogDescription>{t('rooms.dialog.description', 'Its name in English and Arabic, and the branch it is in.')}</DialogDescription>
      </DialogHeader>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          void save();
        }}
      >
        <BilingualInput
          id="room-name"
          label={t('rooms.field.name', 'Name')}
          value={name}
          onChange={setName}
          arLabel={t('language.arabic', 'Arabic')}
          enLabel={t('language.english', 'English')}
        />
        {named.length > 0 && (
          <div className="space-y-1">
            <label htmlFor="room-branch" className="text-sm font-medium text-foreground">
              {t('rooms.field.branch', 'Branch')}
            </label>
            <FieldSelect
              id="room-branch"
              value={unit}
              onChange={setUnit}
              options={[
                { value: NO_BRANCH, label: t('rooms.noBranch', 'No branch') },
                ...named.map((u) => ({ value: String(u.id), label: u.name })),
              ]}
            />
          </div>
        )}
        {problem && (
          <Alert variant="destructive">
            <AlertDescription>{problem}</AlertDescription>
          </Alert>
        )}
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onCancel} disabled={saving}>
            {t('action.cancel', 'Cancel')}
          </Button>
          <Button type="submit" loading={saving} data-testid="room-save">
            {room === null ? t('rooms.add', 'Add room') : t('action.save', 'Save changes')}
          </Button>
        </DialogFooter>
      </form>
    </>
  );
}
