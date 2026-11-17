'use client';

/**
 * Settings › Chat (design 3.5, UC.4, UC.12, WHIT-1097; card `TplChatParts`,
 * "Bots, set by the workspace" and "Blocking"): the workspace's bots, and the
 * numbers and addresses it blocked.
 *
 * Bots follow rules set here and never decide anything; agents are set up
 * under AI. Only people who manage customer messages see these cards.
 */

import { useEffect, useMemo, useState } from 'react';
import { Avatar } from '@whity/ui/avatar';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Icon } from '@whity/ui/icon';
import { Input } from '@whity/ui/input';
import { Switch } from '@whity/ui/switch';
import { Textarea } from '@whity/ui/textarea';
import { useTranslation } from '@whity/features/i18n';
import { deviceTimeZone, useDateDisplay } from '@whity/features/datetime';
import { apiFetch } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { cn } from '@/lib/utils';
import type { ChatBlockedSender, ChatBot, ChatDirectory } from '@/lib/chat-wire';
import { chatApi, chatErrorText } from './chat-api';

const DAYS = [1, 2, 3, 4, 5, 6, 7];

export function ChatBotsCard() {
  const t = useTranslation('chat');
  const { addToast } = useToast();
  const api = useMemo(() => chatApi(apiFetch), []);
  const [bots, setBots] = useState<ChatBot[] | null>(null);
  const [open, setOpen] = useState<string | null>(null);
  const [people, setPeople] = useState<ChatDirectory['people']>([]);

  useEffect(() => {
    let live = true;
    api.bots().then((b) => live && setBots(b), () => live && setBots([]));
    api.directory('').then((d) => live && setPeople(d.people), () => undefined);
    return () => {
      live = false;
    };
  }, [api]);

  const save = async (bot: ChatBot) => {
    try {
      const saved = await api.saveBot(bot.kind, bot.enabled, bot.settings);
      setBots((prev) => prev?.map((b) => (b.kind === saved.kind ? saved : b)) ?? prev);
      addToast(t('bots.saved', 'Saved'), 'success');
    } catch (e) {
      addToast(chatErrorText(e, t('bots.error', 'That change was not saved.')), 'error');
    }
  };

  const title: Record<ChatBot['kind'], string> = {
    away: t('bots.away', 'Away reply'),
    welcome: t('bots.welcome', 'Welcome'),
    handover: t('bots.handover', 'Hand-over'),
  };
  const hint: Record<ChatBot['kind'], string> = {
    away: t('bots.awayHint', 'Answers customers outside opening hours, once a day per person'),
    welcome: t('bots.welcomeHint', 'Answers a customer’s first message'),
    handover: t('bots.handoverHint', 'Gives each new conversation to the next person on the list'),
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('bots.title', 'Bots')}</CardTitle>
        <CardDescription>{t('bots.description', 'Bots follow rules you set here; they never decide anything. Agents are under AI.')}</CardDescription>
      </CardHeader>
      <CardContent>
        {bots === null ? (
          <div aria-busy="true" className="h-24 animate-pulse rounded-card bg-muted/40" />
        ) : (
          <ul className="divide-y divide-border rounded-card border border-border">
            {bots.map((bot) => (
              <li key={bot.kind} className="grid gap-3 p-3">
                <div className="flex items-center gap-3">
                  <span className="grid size-9 place-items-center rounded-control bg-muted text-muted-foreground" aria-hidden="true">
                    <Icon name="trigger" size={16} />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block font-medium">{title[bot.kind]}</span>
                    <span className="block text-sm text-muted-foreground">{hint[bot.kind]}</span>
                  </span>
                  <Button type="button" variant="ghost" size="sm" onClick={() => setOpen(open === bot.kind ? null : bot.kind)} aria-expanded={open === bot.kind}>
                    {t('bots.edit', 'Change')}
                  </Button>
                  <Switch
                    checked={bot.enabled}
                    aria-label={t('bots.turnOn', 'Turn {name} on', { name: title[bot.kind] })}
                    onCheckedChange={(on) => void save({ ...bot, enabled: on })}
                  />
                </div>
                {open === bot.kind && <BotRule bot={bot} people={people} onSave={(b) => void save(b)} />}
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

function BotRule({ bot, people, onSave }: { bot: ChatBot; people: ChatDirectory['people']; onSave: (bot: ChatBot) => void }) {
  const t = useTranslation('chat');
  const [settings, setSettings] = useState<Record<string, unknown>>(bot.settings);
  const set = (key: string, value: unknown) => setSettings((s) => ({ ...s, [key]: value }));
  const days = (settings.days as number[] | undefined) ?? [];
  const chosen = (settings.people as number[] | undefined) ?? [];
  const dayName = (d: number) =>
    [
      t('bots.day1', 'Mon'),
      t('bots.day2', 'Tue'),
      t('bots.day3', 'Wed'),
      t('bots.day4', 'Thu'),
      t('bots.day5', 'Fri'),
      t('bots.day6', 'Sat'),
      t('bots.day7', 'Sun'),
    ][d - 1];

  return (
    <div className="grid gap-3 ps-12">
      {(bot.kind === 'away' || bot.kind === 'welcome') && (
        <label className="grid gap-1.5 text-sm">
          {t('bots.message', 'What it says')}
          <Textarea rows={2} maxLength={1000} value={String(settings.text ?? '')} onChange={(e) => set('text', e.target.value)} />
        </label>
      )}
      {bot.kind === 'away' && (
        <>
          <div role="group" aria-label={t('bots.openDays', 'Open on')} className="flex flex-wrap gap-1.5">
            {DAYS.map((d) => (
              <button
                key={d}
                type="button"
                aria-pressed={days.includes(d)}
                onClick={() => set('days', days.includes(d) ? days.filter((x) => x !== d) : [...days, d].sort())}
                className={cn('h-8 rounded-full border px-3 text-sm', days.includes(d) ? 'border-primary-tint bg-primary-tint text-primary-tint-foreground' : 'border-border hover:bg-muted')}
              >
                {dayName(d)}
              </button>
            ))}
          </div>
          <div className="flex flex-wrap items-end gap-3">
            <Input label={t('bots.from', 'Opens')} type="time" value={String(settings.from ?? '09:00')} onChange={(e) => set('from', e.target.value)} className="w-32" />
            <Input label={t('bots.to', 'Closes')} type="time" value={String(settings.to ?? '18:00')} onChange={(e) => set('to', e.target.value)} className="w-32" />
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={() => set('timezone', deviceTimeZone() ?? 'UTC')}
            >
              {t('bots.zone', 'Time zone: {zone}', { zone: String(settings.timezone ?? 'UTC') })}
            </Button>
          </div>
        </>
      )}
      {bot.kind === 'handover' && (
        <div role="group" aria-label={t('bots.people', 'People who take new conversations')} className="flex flex-wrap gap-1.5">
          {people.map((p) => (
            <button
              key={p.id}
              type="button"
              aria-pressed={chosen.includes(p.id)}
              onClick={() => set('people', chosen.includes(p.id) ? chosen.filter((x) => x !== p.id) : [...chosen, p.id])}
              className={cn('inline-flex h-8 items-center gap-1.5 rounded-full border px-2.5 text-sm', chosen.includes(p.id) ? 'border-primary-tint bg-primary-tint text-primary-tint-foreground' : 'border-border hover:bg-muted')}
            >
              <Avatar name={p.name} size={20} />
              {p.name}
            </button>
          ))}
        </div>
      )}
      <div>
        <Button type="button" variant="outline" size="sm" onClick={() => onSave({ ...bot, settings })}>
          {t('bots.save', 'Save rule')}
        </Button>
      </div>
    </div>
  );
}

export function BlockedSendersCard() {
  const t = useTranslation('chat');
  const { date } = useDateDisplay();
  const { addToast } = useToast();
  const api = useMemo(() => chatApi(apiFetch), []);
  const [rows, setRows] = useState<ChatBlockedSender[] | null>(null);

  useEffect(() => {
    let live = true;
    api.blocked().then((r) => live && setRows(r), () => live && setRows([]));
    return () => {
      live = false;
    };
  }, [api]);

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('blocked.title', 'Blocked')}</CardTitle>
        <CardDescription>
          {t('blocked.description', 'Nothing these numbers and addresses send reaches the team. Past messages stay. People in the team can’t be blocked, only muted.')}
        </CardDescription>
      </CardHeader>
      <CardContent>
        {rows === null ? (
          <div aria-busy="true" className="h-16 animate-pulse rounded-card bg-muted/40" />
        ) : rows.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('blocked.none', 'Nobody is blocked. Block someone from their chat’s details.')}</p>
        ) : (
          <ul className="divide-y divide-border rounded-card border border-border">
            {rows.map((r) => (
              <li key={r.id} className="flex items-center gap-3 p-3">
                <Icon name="ban" size={16} />
                <span className="min-w-0 flex-1">
                  <span dir="auto" className="block truncate text-start font-medium">
                    {r.name ?? r.address}
                  </span>
                  <span className="block text-sm text-muted-foreground">
                    {[r.name ? r.address : null, r.blocked_by ? t('blocked.by', 'by {name}, {date}', { name: r.blocked_by, date: date(r.created_at) ?? '' }) : date(r.created_at)]
                      .filter(Boolean)
                      .join(' · ')}
                  </span>
                </span>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={async () => {
                    try {
                      await api.unblock(r.id);
                      setRows((prev) => prev?.filter((x) => x.id !== r.id) ?? prev);
                      addToast(t('blocked.unblocked', 'Unblocked. Their next message reaches the team.'), 'success');
                    } catch (e) {
                      addToast(chatErrorText(e, ''), 'error');
                    }
                  }}
                >
                  {t('blocked.unblock', 'Unblock')}
                </Button>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
