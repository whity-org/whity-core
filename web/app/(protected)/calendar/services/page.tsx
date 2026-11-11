'use client';

/**
 * /calendar/services (WHIT-944): what can be booked, how long it takes, what
 * it costs, and whether customers can book it online. A list page (section
 * 21): search, the six states, and one main button, New service, which asks
 * for a name and a length and opens the new record.
 *
 * Anyone who can read the calendar sees the list; adding and changing services
 * needs `calendar:manage`.
 */

import { useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { CalendarNav } from '@/components/calendar/calendar-nav';
import { useCalendarAccess } from '@/components/calendar/use-calendar-basics';
import { useCalendarWords } from '@/components/calendar/use-calendar-words';
import { bookingMoney } from '@/components/calendar/booking-sheet';
import { useFetch } from '@/hooks/useFetch';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Badge } from '@whity/ui/badge';
import { BilingualInput, type BilingualValue } from '@whity/ui/bilingual-input';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { EmptyFiltered, ReadOnlyNotice } from '@whity/ui/page-states';
import { SearchField } from '@whity/ui/search-field';
import { useTranslation } from '@whity/features/i18n';
import { calendarApi, type CalendarService } from '@/lib/calendar/api';
import { cleanNames } from '@/lib/localized-name';

export default function CalendarServicesPage() {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const access = useCalendarAccess();
  const router = useRouter();
  const [search, setSearch] = useState('');
  const [adding, setAdding] = useState(false);

  const { data, loading, error, forbidden, refetch } = useFetch(async () => {
    const [services, staff] = await Promise.all([calendarApi.services(), calendarApi.staff()]);
    return { services, staff };
  }, []);

  const rows = useMemo(() => {
    const list = data?.services ?? [];
    const term = search.trim().toLowerCase();
    if (term === '') return list;
    return list.filter((s) => Object.values(s.name).some((n) => typeof n === 'string' && n.toLowerCase().includes(term)));
  }, [data, search]);

  if (!access.loading && !access.canRead) {
    return <NoAccess area={t('services.area', 'services')} permission="calendar:read" />;
  }

  const columns: DataTableColumn<CalendarService>[] = [
    {
      id: 'name',
      header: t('services.column.name', 'Service'),
      cell: (row) => <span className="font-medium">{words.serviceName(row)}</span>,
    },
    {
      id: 'duration',
      header: t('services.column.duration', 'Length'),
      cell: (row) => <span className="tabular-nums">{words.minutes(row.duration_minutes)}</span>,
    },
    {
      id: 'price',
      header: t('services.column.price', 'Price'),
      cell: (row) =>
        row.price_minor === null ? (
          <span className="text-muted-foreground">{t('services.noPrice', 'Not shown')}</span>
        ) : (
          <span dir="ltr" className="tabular-nums">
            {bookingMoney(row.price_minor, row.currency)}
          </span>
        ),
    },
    {
      id: 'staff',
      header: t('services.column.staff', 'Who provides it'),
      cell: (row) =>
        row.staff_profile_ids.length === 0
          ? t('services.staff.none', 'Nobody yet')
          : (data?.staff ?? [])
              .filter((s) => row.staff_profile_ids.includes(s.profile_id))
              .map((s) => s.display_name)
              .join(', '),
    },
    {
      id: 'state',
      header: t('services.column.state', 'Bookable'),
      cell: (row) =>
        !row.active ? (
          <Badge variant="outline">{t('services.state.inactive', 'Not offered')}</Badge>
        ) : row.bookable_online ? (
          <Badge variant="success">{t('services.state.online', 'Online and at the desk')}</Badge>
        ) : (
          <Badge variant="info">{t('services.state.desk', 'At the desk only')}</Badge>
        ),
    },
  ];

  return (
    <div className="space-y-4">
      <AdminHeader
        title={t('services.title', 'Services')}
        description={t('services.description', 'What customers can book, how long it takes, and who provides it.')}
        action={
          access.canManage ? (
            <Button onClick={() => setAdding(true)} data-testid="services-new">
              <Icon name="plus" />
              {t('services.new', 'New service')}
            </Button>
          ) : undefined
        }
      />
      <CalendarNav active="services" />

      {!access.loading && !access.canManage && !error && (
        <ReadOnlyNotice
          title={t('services.readOnly.title', 'You can see services but not change them')}
          description={t('services.readOnly.description', 'Someone who manages the calendar adds and changes services.')}
        />
      )}

      {error ? (
        <LoadProblem
          forbidden={forbidden}
          message={t('services.error.load', 'The services could not be loaded.')}
          onRetry={refetch}
          area={t('services.area', 'services')}
        />
      ) : (
        <>
          {(data?.services.length ?? 0) > 0 && (
            <div className="max-w-sm">
              <SearchField
                value={search}
                onValueChange={setSearch}
                placeholder={t('services.search', 'Search services')}
                label={t('services.search', 'Search services')}
                clearLabel={t('services.clear', 'Clear search')}
              />
            </div>
          )}
          {search.trim() !== '' && rows.length === 0 && (data?.services.length ?? 0) > 0 ? (
            <EmptyFiltered
              title={t('services.filtered', 'No service matches “{term}”', { term: search.trim() })}
              clearLabel={t('services.clear', 'Clear search')}
              onClear={() => setSearch('')}
            />
          ) : (
            <DataTable
              columns={columns}
              data={rows}
              isLoading={loading && data === null}
              getRowId={(row) => String(row.id)}
              onRowClick={(row) => router.push(`/calendar/services/${row.id}`)}
              emptyState={{
                title: t('services.empty.title', 'No services yet'),
                description: access.canManage
                  ? t('services.empty.manage', 'Add what customers can book, such as a cake tasting or a consultation.')
                  : t('services.empty.ask', 'Someone who manages the calendar adds the services.'),
              }}
            />
          )}
        </>
      )}

      <NewServiceDialog
        open={adding}
        onOpenChange={setAdding}
        onCreated={(service) => router.push(`/calendar/services/${service.id}`)}
      />
    </div>
  );
}

function NewServiceDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated: (service: CalendarService) => void;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const [name, setName] = useState<BilingualValue>({});
  const [duration, setDuration] = useState('30');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const save = async () => {
    const names = cleanNames(name);
    const minutes = Number(duration);
    if (Object.keys(names).length === 0) {
      setProblem(t('services.error.name', 'Give the service a name.'));
      return;
    }
    if (!Number.isInteger(minutes) || minutes < 5 || minutes > 1440) {
      setProblem(t('services.error.duration', 'The length must be a whole number of minutes, from 5 to 1440.'));
      return;
    }
    setSaving(true);
    setProblem(null);
    try {
      onCreated(await calendarApi.createService({ name: names, duration_minutes: minutes }));
    } catch (error) {
      setProblem(words.errorText(error, t('services.error.create', 'The service could not be added. Try again.')));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent data-testid="services-new-dialog">
        <DialogHeader>
          <DialogTitle>{t('services.new', 'New service')}</DialogTitle>
          <DialogDescription>
            {t('services.new.description', 'Name it and say how long it takes. You can set the price and who provides it next.')}
          </DialogDescription>
        </DialogHeader>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            void save();
          }}
        >
          <BilingualInput
            id="service-new-name"
            label={t('services.field.name', 'Name')}
            value={name}
            onChange={setName}
            arLabel={t('language.arabic', 'Arabic')}
            enLabel={t('language.english', 'English')}
          />
          <Input
            id="service-new-duration"
            type="number"
            min={5}
            max={1440}
            label={t('services.field.duration', 'Length in minutes')}
            value={duration}
            onChange={(e) => setDuration(e.target.value)}
          />
          {problem && (
            <Alert variant="destructive">
              <AlertDescription>{problem}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
              {t('action.cancel', 'Cancel')}
            </Button>
            <Button type="submit" loading={saving}>
              {t('services.new.submit', 'Add service')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
