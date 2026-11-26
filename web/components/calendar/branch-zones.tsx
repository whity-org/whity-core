'use client';

/**
 * Each branch's own time zone (WHIT-1440, design section 39 §5.3, AP.16), on
 * Calendar › Settings. A branch without one uses the workspace's. Only the
 * branches that staff or rooms are in are listed; a change saves at once and
 * says so, since it touches no other field on the page.
 */

import { useState } from 'react';
import { FieldSelect } from '@/components/field-select';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Skeleton } from '@whity/ui/skeleton';
import { useTranslation } from '@whity/features/i18n';
import { calendarApi } from '@/lib/calendar/api';
import { useCalendarWords } from './use-calendar-words';

/** The workspace's own zone, as the select's value. */
const WORKSPACE = 'workspace';

function zoneNames(): string[] {
  const supported = (Intl as unknown as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf;
  if (typeof supported === 'function') {
    try {
      return supported('timeZone');
    } catch {
      // The short list below.
    }
  }
  return ['UTC', 'Asia/Amman', 'Europe/London', 'Europe/Berlin', 'America/New_York', 'Australia/Sydney'];
}

export function BranchZonesCard({ canChange }: { canChange: boolean }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const { addToast } = useToast();
  const { data, refetch } = useFetch(() => calendarApi.branches(), []);
  const [saving, setSaving] = useState<number | null>(null);

  const branches = (data?.data ?? []).filter((branch) => branch.in_use);
  const workspace = data?.workspace_time_zone ?? '';
  const options = [
    { value: WORKSPACE, label: t('branchZones.workspace', 'The workspace’s ({zone})', { zone: workspace }) },
    ...zoneNames().map((zone) => ({ value: zone, label: zone })),
  ];

  const change = async (id: number, name: string, value: string) => {
    setSaving(id);
    try {
      await calendarApi.setBranchZone(id, value === WORKSPACE ? null : value);
      addToast(t('branchZones.saved', 'Time zone saved for {branch}', { branch: name }), 'success');
      refetch();
    } catch (error) {
      addToast(words.errorText(error, t('branchZones.error', 'The time zone could not be saved. Try again.')), 'error');
    } finally {
      setSaving(null);
    }
  };

  return (
    <Card data-testid="settings-branch-zones">
      <CardHeader>
        <CardTitle>{t('branchZones.title', 'Branch time zones')}</CardTitle>
        <CardDescription>
          {t(
            'branchZones.hint',
            'A branch in another time zone can have its own. The hours and bookings of the people who work there use it, and the booking page writes the zone out. Bookings already made keep their time.'
          )}
        </CardDescription>
      </CardHeader>
      <CardContent>
        {data === null || data === undefined ? (
          <Skeleton className="h-12 w-full" />
        ) : branches.length === 0 ? (
          <p className="text-sm text-muted-foreground" data-testid="branch-zones-none">
            {t('branchZones.none', 'No staff or rooms are in a branch yet, so every time uses the workspace’s time zone.')}
          </p>
        ) : (
          <ul className="divide-y divide-divider">
            {branches.map((branch) => (
              <li key={branch.id} className="flex min-h-12 flex-wrap items-center gap-3 py-2">
                <label htmlFor={`branch-zone-${branch.id}`} className="min-w-40 flex-1 text-sm font-medium text-foreground">
                  {branch.name}
                </label>
                <div className="w-full sm:w-72" dir="ltr">
                  <FieldSelect
                    id={`branch-zone-${branch.id}`}
                    value={branch.time_zone ?? WORKSPACE}
                    options={options}
                    disabled={!canChange || saving === branch.id}
                    onChange={(value) => void change(branch.id, branch.name, value)}
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
