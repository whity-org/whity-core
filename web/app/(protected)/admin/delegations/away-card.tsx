'use client';

/**
 * "I'm away" (OR.1, `TplAway`): the delegation everyone needs.
 *
 * A person sets it for themselves (`PUT /api/me/away`; the server takes the
 * person from the session, never from the form): the day it starts, the day
 * they're back, who decides for them, and what goes to that person. Anything
 * above what the delegate may approve waits for them. Coming back early ends
 * it at once (`DELETE /api/me/away`).
 */

import { useEffect, useMemo, useState } from 'react';
import { Card } from '@whity/ui/card';
import { Button } from '@whity/ui/button';
import { Badge } from '@whity/ui/badge';
import { Avatar } from '@whity/ui/avatar';
import { Icon } from '@whity/ui/icon';
import { Switch } from '@whity/ui/switch';
import { Input } from '@whity/ui/input';
import { DatePicker } from '@/components/ui/date-picker';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import type { components } from '@/lib/api/schema';

export type AwayPeriod = components['schemas']['AwayPeriod'];
type Cover = AwayPeriod['covers'][number];

export interface AwayPerson {
  id: number;
  name: string;
}

function isoDay(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

export function AwayCard({ people, onChanged }: { people: AwayPerson[]; onChanged?: () => void }) {
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const { apiClient, user } = useAuth();
  const { addToast } = useToast();

  const [current, setCurrent] = useState<AwayPeriod | null>(null);
  const [loaded, setLoaded] = useState(false);
  const [startsOn, setStartsOn] = useState<string>(() => isoDay(new Date()));
  const [backOn, setBackOn] = useState<string>(() => isoDay(new Date(Date.now() + 5 * 86_400_000)));
  const [delegateId, setDelegateId] = useState<string>('');
  const [covers, setCovers] = useState<Record<Cover, boolean>>({ flows: true, proposals: true, documents: false });
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const response = await apiClient('/api/v1/me/away');
      const body = (await response.json().catch(() => null)) as { data?: AwayPeriod | null } | null;
      if (!cancelled) {
        setCurrent(response.ok ? (body?.data ?? null) : null);
        setLoaded(true);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [apiClient]);

  const others = useMemo(() => people.filter((p) => p.id !== user?.id), [people, user?.id]);
  const delegate = others.find((p) => String(p.id) === delegateId) ?? null;
  const firstName = delegate?.name.split(' ')[0] ?? '';
  const day = (iso: string) => dates.date(iso) ?? '';

  const handOver = async () => {
    setBusy(true);
    try {
      const response = await apiClient('/api/v1/me/away', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          delegateProfileId: Number(delegateId),
          startsOn,
          backOn,
          covers: (Object.keys(covers) as Cover[]).filter((key) => covers[key]),
          message: message.trim() === '' ? null : message.trim(),
        }),
      });
      const body = (await response.json().catch(() => null)) as { data?: AwayPeriod; error?: string } | null;
      if (!response.ok || !body?.data) {
        addToast(body?.error ?? t('away.error', 'That could not be saved. Try again.'), 'error', 6000);
        return;
      }
      setCurrent(body.data);
      addToast(t('away.saved', '{name} decides for you from {date}.', { name: body.data.delegate.name, date: day(body.data.startsOn) }), 'success');
      onChanged?.();
    } finally {
      setBusy(false);
    }
  };

  const comeBack = async () => {
    setBusy(true);
    try {
      const response = await apiClient('/api/v1/me/away', { method: 'DELETE' });
      if (!response.ok) {
        addToast(t('away.error', 'That could not be saved. Try again.'), 'error', 6000);
        return;
      }
      setCurrent(null);
      addToast(t('away.ended', "Welcome back. Your decisions come to you again."), 'success');
      onChanged?.();
    } finally {
      setBusy(false);
    }
  };

  const coverRows: { key: Cover; title: string; hint: string }[] = [
    { key: 'flows', title: t('away.cover.flows', 'Approvals in flows'), hint: t('away.cover.flowsHint', 'Purchases, leave, expense claims') },
    { key: 'proposals', title: t('away.cover.proposals', "Your agents' proposals"), hint: t('away.cover.proposalsHint', 'What your agents ask you to approve') },
    { key: 'documents', title: t('away.cover.documents', 'Documents to sign'), hint: t('away.cover.documentsHint', 'Purchase orders, letters') },
  ];

  return (
    <Card className="h-fit gap-4 p-5" data-testid="away-card">
      <div>
        <h2 className="text-lg font-semibold">{t('away.title', "I'm away")}</h2>
        <p className="text-muted-foreground">
          {t('away.description', 'While you’re away, your decisions go to someone you trust. You can come back early.')}
        </p>
      </div>

      {!loaded ? null : current !== null ? (
        <div className="grid gap-3" data-testid="away-current">
          <div className="flex items-center gap-3">
            <Avatar name={current.delegate.name} size={32} />
            <div className="min-w-0 flex-1">
              <div>{current.delegate.name}</div>
              <div className="text-sm text-muted-foreground">
                {t('away.current.when', '{from} until you’re back on {back}', { from: day(current.startsOn), back: day(current.backOn) })}
              </div>
            </div>
            <Badge variant={current.status === 'in_force' ? 'success' : 'outline'}>
              {current.status === 'in_force' ? t('away.status.inForce', 'In force') : t('away.status.starting', 'Starting')}
            </Badge>
          </div>
          {current.message && <p className="text-sm">{current.message}</p>}
          <div className="flex justify-end">
            <Button variant="outline" disabled={busy} onClick={() => void comeBack()}>
              {t('away.comeBack', 'I’m back')}
            </Button>
          </div>
        </div>
      ) : (
        <form
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault();
            void handOver();
          }}
        >
          <div className="grid gap-3 sm:grid-cols-2">
            <DatePicker label={t('away.from', 'From')} value={startsOn} onChange={(d) => d && setStartsOn(isoDay(d))} required />
            <DatePicker label={t('away.backOn', 'Back on')} value={backOn} onChange={(d) => d && setBackOn(isoDay(d))} required />
          </div>

          <div className="grid gap-1.5">
            <label className="text-sm font-medium" htmlFor="away-delegate">
              {t('away.who', 'Who decides for you')}
            </label>
            <Select value={delegateId} onValueChange={setDelegateId}>
              <SelectTrigger id="away-delegate" className="w-full">
                <SelectValue placeholder={t('away.who.placeholder', 'Choose someone')} />
              </SelectTrigger>
              <SelectContent>
                {others.map((person) => (
                  <SelectItem key={person.id} value={String(person.id)}>
                    <span className="flex items-center gap-2">
                      <Avatar name={person.name} size={20} />
                      {person.name}
                    </span>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <fieldset className="grid gap-0">
            <legend className="mb-1 text-sm font-semibold">
              {delegate !== null ? t('away.goesTo', 'What goes to {name}', { name: firstName }) : t('away.goesToSomeone', 'What goes to them')}
            </legend>
            {coverRows.map((row) => (
              <div key={row.key} className="flex items-center gap-3 border-b border-border py-2.5 last:border-b-0">
                <div className="min-w-0 flex-1">
                  <div>{row.title}</div>
                  <div className="text-sm text-muted-foreground">{row.hint}</div>
                </div>
                <Switch
                  checked={covers[row.key]}
                  onCheckedChange={(checked) => setCovers((prev) => ({ ...prev, [row.key]: checked }))}
                  aria-label={row.title}
                />
              </div>
            ))}
            <div className="flex items-center gap-3 py-2.5">
              <div className="min-w-0 flex-1">
                <div>{t('away.above', 'Anything they can’t approve')}</div>
                <div className="text-sm text-muted-foreground">{t('away.aboveHint', 'More than their own permissions allow: waits for you')}</div>
              </div>
              <Badge variant="outline">{t('away.waits', 'Waits')}</Badge>
            </div>
          </fieldset>

          <div className="grid gap-1.5">
            <label className="text-sm font-medium" htmlFor="away-message">
              {t('away.message', 'Message when people write to you')}
            </label>
            <Input
              id="away-message"
              value={message}
              maxLength={500}
              onChange={(e) => setMessage(e.target.value)}
              placeholder={
                delegate !== null
                  ? t('away.message.placeholder', 'I’m away until {date}. {name} is deciding for me.', { date: day(backOn), name: delegate.name })
                  : ''
              }
            />
          </div>

          <div className="flex justify-end">
            <Button type="submit" disabled={busy || delegate === null || !Object.values(covers).some(Boolean)}>
              <Icon name="delegation" size={16} aria-hidden />
              {t('away.handOver', 'Hand over from {date}', { date: day(startsOn) })}
            </Button>
          </div>
        </form>
      )}
    </Card>
  );
}
