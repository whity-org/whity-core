'use client';

/**
 * Blocks inside chat messages (design 3.5, UC.5, WHIT-1096; card
 * `TplChatParts`, "Blocks inside messages"): a poll with live counts, and a
 * Whity record (a booking or a document) with its current status and Open.
 *
 * A block shows only the actions its reader may take: a poll offers voting
 * until it closes; a record offers Open, and its own page decides the rest.
 */

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { Button } from '@whity/ui/button';
import { Badge } from '@whity/ui/badge';
import { Checkbox } from '@whity/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { Icon } from '@whity/ui/icon';
import { Input } from '@whity/ui/input';
import { SearchField } from '@whity/ui/search-field';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { cn } from '@/lib/utils';
import type { ChatBlock, ChatPollBlock, ChatRecordBlock } from '@/lib/chat-wire';
import type { ChatApi } from './chat-api';

/** The words here are Chat's: the `t` passed in is `useTranslation('chat')`, as this hook gives. */
export function useChatBlocksText(): TranslateFn {
  const t = useTranslation('chat');
  return t;
}

function PollBlock({ t, poll, onVote }: { t: TranslateFn; poll: ChatPollBlock; onVote?: (choices: number[]) => void }) {
  const { dateTime } = useDateDisplay();
  const total = poll.options.reduce((n, o) => n + o.votes, 0);
  const mine = poll.options.map((o, i) => (o.mine ? i : -1)).filter((i) => i >= 0);
  const canVote = !poll.closed && onVote !== undefined;

  const choose = (i: number) => {
    if (!onVote) return;
    if (poll.multiple) onVote(mine.includes(i) ? mine.filter((x) => x !== i) : [...mine, i]);
    else onVote(mine.includes(i) ? [] : [i]);
  };

  return (
    <div className="grid min-w-64 gap-2 rounded-control border border-border bg-card p-3">
      <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <Icon name="list-bullet" size={14} />
        {t('poll.label', 'Poll')}
      </p>
      <p dir="auto" className="text-start text-base font-semibold">
        {poll.question}
      </p>
      <ul className="grid gap-1.5" aria-label={poll.question}>
        {poll.options.map((o, i) => {
          const share = total > 0 ? Math.round((o.votes / total) * 100) : 0;
          return (
            <li key={i}>
              <button
                type="button"
                disabled={!canVote}
                aria-pressed={o.mine}
                title={o.people.join(', ')}
                onClick={() => choose(i)}
                className={cn(
                  'relative flex h-10 w-full items-center gap-2 overflow-hidden rounded-control border px-3 text-start text-sm',
                  o.mine ? 'border-primary' : 'border-border',
                  canVote && 'hover:bg-muted'
                )}
              >
                <span aria-hidden="true" className="absolute inset-y-0 start-0 bg-primary-tint" style={{ width: `${share}%` }} />
                <span className="relative flex flex-1 items-center gap-2">
                  {o.mine && <Icon name="check" size={14} />}
                  <span dir="auto" className="text-start">
                    {o.text}
                  </span>
                </span>
                <span className="relative tabular-nums">{o.votes}</span>
              </button>
            </li>
          );
        })}
      </ul>
      <p className="text-xs text-muted-foreground">
        {[
          poll.voters === 1 ? t('poll.oneVoter', '1 person voted') : t('poll.voters', '{count} people voted', { count: String(poll.voters) }),
          poll.closed
            ? t('poll.closed', 'Closed')
            : poll.closes_at
              ? t('poll.closes', 'closes {time}', { time: dateTime(poll.closes_at) ?? '' })
              : poll.multiple
                ? t('poll.several', 'pick one or more')
                : null,
        ]
          .filter(Boolean)
          .join(' · ')}
      </p>
    </div>
  );
}

function RecordBlock({ t, record }: { t: TranslateFn; record: ChatRecordBlock }) {
  const { dateTime, date } = useDateDisplay();
  if (record.missing) {
    return (
      <div className="rounded-control border border-dashed border-border bg-card p-3 text-sm text-muted-foreground">
        {t('record.missing', 'This record is no longer here.')}
      </div>
    );
  }
  const booking = record.record === 'booking';
  const tone = record.status === 'cancelled' || record.status === 'withdrawn' ? 'error' : record.status === 'confirmed' || record.status === 'issued' ? 'success' : 'info';
  const status: Record<string, string> = {
    confirmed: t('record.confirmed', 'Confirmed'),
    cancelled: t('record.cancelled', 'Cancelled'),
    pending: t('record.pending', 'Waiting for an answer'),
    held: t('record.held', 'Held'),
    issued: t('record.issued', 'Issued'),
    withdrawn: t('record.withdrawn', 'Withdrawn'),
  };
  return (
    <div className="grid min-w-64 gap-2 rounded-control border border-border bg-card p-3">
      <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <Icon name={booking ? 'calendar' : 'document'} size={14} />
        {booking ? t('record.booking', 'Booking') : record.kind || t('record.document', 'Document')}
      </p>
      <p dir="auto" className="text-start text-base font-semibold">
        {record.number ? `${record.number} · ${record.title}` : record.title}
      </p>
      <dl className="grid grid-cols-[5.5rem_minmax(0,1fr)] gap-x-3 gap-y-1 text-sm">
        {booking && record.starts_at && (
          <>
            <dt className="text-muted-foreground">{t('record.when', 'When')}</dt>
            <dd>{dateTime(record.starts_at)}</dd>
          </>
        )}
        {booking && record.with && (
          <>
            <dt className="text-muted-foreground">{t('record.with', 'With')}</dt>
            <dd>{record.with}</dd>
          </>
        )}
        {!booking && record.created_at && (
          <>
            <dt className="text-muted-foreground">{t('record.date', 'Date')}</dt>
            <dd>{date(record.created_at)}</dd>
          </>
        )}
        <dt className="text-muted-foreground">{t('record.status', 'Status')}</dt>
        <dd>
          <Badge variant={tone === 'error' ? 'destructive' : tone === 'success' ? 'success' : 'info'}>{status[record.status] ?? record.status}</Badge>
        </dd>
      </dl>
      <div>
        <Button asChild variant="outline" size="sm">
          <Link href={record.href}>{booking ? t('record.openBooking', 'Open booking') : t('record.openDocument', 'Open document')}</Link>
        </Button>
      </div>
    </div>
  );
}

export function MessageBlock({ t, block, onVote }: { t: TranslateFn; block: ChatBlock; onVote?: (choices: number[]) => void }) {
  return block.type === 'poll' ? <PollBlock t={t} poll={block} onVote={onVote} /> : <RecordBlock t={t} record={block} />;
}

export function PollDialog({ t, open, onOpenChange, onSend }: { t: TranslateFn; open: boolean; onOpenChange: (open: boolean) => void; onSend: (block: { type: 'poll'; question: string; options: string[]; multiple: boolean }) => Promise<void> }) {
  const [question, setQuestion] = useState('');
  const [options, setOptions] = useState(['', '']);
  const [multiple, setMultiple] = useState(false);
  const [busy, setBusy] = useState(false);
  const filled = options.map((o) => o.trim()).filter(Boolean);
  const ready = question.trim() !== '' && new Set(filled).size >= 2;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[30rem]" closeLabel={t('action.close', 'Close')}>
        <DialogHeader>
          <DialogTitle>{t('poll.new', 'New poll')}</DialogTitle>
          <DialogDescription>{t('poll.newHint', 'Everyone in this chat can vote and see the counts.')}</DialogDescription>
        </DialogHeader>
        <label className="grid gap-1.5 text-sm">
          {t('poll.question', 'Question')}
          <Input value={question} maxLength={300} onChange={(e) => setQuestion(e.target.value)} />
        </label>
        <div className="grid gap-2">
          <span className="text-sm">{t('poll.answers', 'Answers')}</span>
          {options.map((o, i) => (
            <div key={i} className="flex items-center gap-2">
              <Input
                value={o}
                maxLength={120}
                aria-label={t('poll.answerN', 'Answer {n}', { n: String(i + 1) })}
                onChange={(e) => setOptions((prev) => prev.map((x, j) => (j === i ? e.target.value : x)))}
              />
              {options.length > 2 && (
                <Button type="button" variant="ghost" size="icon-sm" aria-label={t('poll.removeAnswer', 'Remove answer {n}', { n: String(i + 1) })} onClick={() => setOptions((prev) => prev.filter((_, j) => j !== i))}>
                  <Icon name="close" size={14} />
                </Button>
              )}
            </div>
          ))}
          {options.length < 10 && (
            <Button type="button" variant="ghost" size="sm" icon="plus" className="justify-self-start" onClick={() => setOptions((prev) => [...prev, ''])}>
              {t('poll.addAnswer', 'Add an answer')}
            </Button>
          )}
        </div>
        <label className="flex items-center gap-2 text-sm">
          <Checkbox checked={multiple} onCheckedChange={(v) => setMultiple(v === true)} />
          {t('poll.multiple', 'People can pick more than one')}
        </label>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {t('action.cancel', 'Cancel')}
          </Button>
          <Button
            type="button"
            disabled={!ready}
            loading={busy}
            onClick={async () => {
              setBusy(true);
              try {
                await onSend({ type: 'poll', question: question.trim(), options: filled, multiple });
                setQuestion('');
                setOptions(['', '']);
                setMultiple(false);
              } finally {
                setBusy(false);
              }
            }}
          >
            {t('poll.send', 'Send poll')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function RecordDialog({
  t,
  api,
  open,
  onOpenChange,
  onPick,
}: {
  t: TranslateFn;
  api: ChatApi;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onPick: (block: { type: 'record'; record: 'booking' | 'document'; id: number }) => Promise<void>;
}) {
  const { dateTime } = useDateDisplay();
  const [kind, setKind] = useState<'booking' | 'document'>('booking');
  const [query, setQuery] = useState('');
  const [found, setFound] = useState<ChatRecordBlock[] | null>(null);

  useEffect(() => {
    if (!open) return;
    let live = true;
    api.records(kind, query).then(
      (rows) => live && setFound(rows),
      () => live && setFound([])
    );
    return () => {
      live = false;
    };
  }, [api, kind, query, open]);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[32rem]" closeLabel={t('action.close', 'Close')}>
        <DialogHeader>
          <DialogTitle>{t('record.pick', 'Share a Whity record')}</DialogTitle>
          <DialogDescription>{t('record.pickHint', 'It shows its status as it changes. Opening it follows what each person may see.')}</DialogDescription>
        </DialogHeader>
        <div role="tablist" className="flex gap-1.5">
          {(['booking', 'document'] as const).map((k) => (
            <button
              key={k}
              type="button"
              role="tab"
              aria-selected={kind === k}
              onClick={() => setKind(k)}
              className={cn('h-8 rounded-full border px-3 text-sm', kind === k ? 'border-primary-tint bg-primary-tint text-primary-tint-foreground' : 'border-border hover:bg-muted')}
            >
              {k === 'booking' ? t('record.bookings', 'Bookings') : t('record.documents', 'Documents')}
            </button>
          ))}
        </div>
        <SearchField
          value={query}
          onValueChange={setQuery}
          onSearch={setQuery}
          label={t('record.search', 'Search')}
          clearLabel={t('list.clear', 'Clear search')}
          placeholder={kind === 'booking' ? t('record.searchBookings', 'Customer or service') : t('record.searchDocuments', 'Title or number')}
        />
        <ul className="grid max-h-72 gap-1 overflow-y-auto">
          {found === null ? null : found.length === 0 ? (
            <li className="px-2 py-3 text-sm text-muted-foreground">{t('record.none', 'Nothing matches.')}</li>
          ) : (
            found.map((r) => (
              <li key={`${r.record}${r.id}`}>
                <button
                  type="button"
                  onClick={() => void onPick({ type: 'record', record: r.record, id: r.id })}
                  className="grid w-full gap-0.5 rounded-control px-2 py-2 text-start text-sm hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring"
                >
                  <span dir="auto" className="truncate text-start font-medium">
                    {r.number ? `${r.number} · ${r.title}` : r.title}
                  </span>
                  <span className="text-xs text-muted-foreground">{r.record === 'booking' ? dateTime(r.starts_at ?? null) : r.kind}</span>
                </button>
              </li>
            ))
          )}
        </ul>
      </DialogContent>
    </Dialog>
  );
}
