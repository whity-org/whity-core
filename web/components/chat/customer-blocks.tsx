'use client';

/**
 * Blocks in customer chats (design 3.5, UC.5, UC.10, WHIT-1106; card
 * `TplChatParts`, "+ attaches" and "Blocks inside messages"): booking times,
 * a form to fill and a payment link. The customer receives words with the
 * link under them; the team sees the block.
 *
 * Payment links come from Whity Pay and appear only when it is installed and
 * set up for the workspace (owner, 16 Nov 2026).
 */

import { useEffect, useMemo, useState } from 'react';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { Icon } from '@whity/ui/icon';
import { Input } from '@whity/ui/input';
import { Textarea } from '@whity/ui/textarea';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { apiFetch } from '@/lib/api/client';
import { cn } from '@/lib/utils';
import type { CustomerBlock, CustomerBlockOptions } from '@/lib/chat-wire';
import { chatApi, chatErrorText } from './chat-api';

/** The words here are Chat's: the `t` passed in is `useTranslation('chat')`, as this hook gives. */
export function useCustomerBlocksText(): TranslateFn {
  const t = useTranslation('chat');
  return t;
}

/** What the + can offer in this customer chat, and whether Whity Pay is there. */
export function useCustomerBlockOptions(conversationId: number, enabled: boolean): (CustomerBlockOptions & { payments: boolean }) | null {
  const api = useMemo(() => chatApi(apiFetch), []);
  const [options, setOptions] = useState<(CustomerBlockOptions & { payments: boolean }) | null>(null);
  useEffect(() => {
    if (!enabled) return;
    let live = true;
    Promise.all([api.customerBlockOptions(conversationId).catch(() => null), api.paymentsAvailable()]).then(([o, payments]) => {
      if (live) setOptions({ booking_page: o?.booking_page ?? false, services: o?.services ?? [], forms: o?.forms ?? [], payments });
    });
    return () => {
      live = false;
    };
  }, [api, conversationId, enabled]);
  return options;
}

/** The block the team sees on a message it sent a customer. */
export function CustomerBlockCard({ t, block }: { t: TranslateFn; block: CustomerBlock }) {
  const { dateTime } = useDateDisplay();
  const head = (icon: 'calendar' | 'form' | 'card', label: string) => (
    <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
      <Icon name={icon} size={14} />
      {label}
    </p>
  );
  return (
    <div className="mt-1 grid min-w-56 gap-2 rounded-control border border-border bg-card p-3 text-start">
      {block.type === 'booking_times' && (
        <>
          {head('calendar', t('cblock.timesLabel', 'Pick a time · sent to the customer'))}
          <p className="font-semibold">{t('cblock.service', '{name} · {minutes} minutes', { name: block.service.name, minutes: String(block.service.minutes) })}</p>
          <div className="flex flex-wrap gap-1.5">
            {block.times.map((time) => (
              <span key={time} className="rounded-full border border-primary px-2.5 py-1 text-sm text-primary">
                {dateTime(time)}
              </span>
            ))}
          </div>
        </>
      )}
      {block.type === 'form' && (
        <>
          {head('form', t('cblock.formLabel', 'Form · asked for'))}
          <p className="font-semibold">{block.form.title}</p>
        </>
      )}
      {block.type === 'payment' && (
        <>
          {head('card', t('cblock.payLabel', 'Payment link'))}
          <p className="font-semibold">{block.description}</p>
          <p className="flex items-center gap-2">
            <span dir="ltr" className="font-mono">
              {block.currency} {block.amount}
            </span>
            <Badge variant="warning">{t('cblock.sent', 'Sent')}</Badge>
          </p>
        </>
      )}
      <div className="flex flex-wrap gap-2">
        <Button asChild variant="outline" size="sm">
          <a href={block.url} target="_blank" rel="noreferrer">
            {t('cblock.open', 'Open what they see')}
          </a>
        </Button>
        <Button type="button" variant="ghost" size="sm" icon="copy" onClick={() => void navigator.clipboard?.writeText(block.url).catch(() => undefined)}>
          {t('cblock.copy', 'Copy link')}
        </Button>
      </div>
    </div>
  );
}

type Kind = 'booking_times' | 'form' | 'payment';

/** One dialog for the three: what to send, with the person's own words above the link. */
export function CustomerBlockDialog({
  t,
  kind,
  conversationId,
  customerName,
  options,
  currency,
  onClose,
  onSent,
}: {
  t: TranslateFn;
  kind: Kind;
  conversationId: number;
  customerName: string;
  options: CustomerBlockOptions;
  currency: string;
  onClose: () => void;
  onSent: () => void;
}) {
  const api = useMemo(() => chatApi(apiFetch), []);
  const [words, setWords] = useState('');
  const [serviceId, setServiceId] = useState<number>(options.services[0]?.id ?? 0);
  const [times, setTimes] = useState<string[]>(['']);
  const [formId, setFormId] = useState<number>(options.forms[0]?.id ?? 0);
  const [description, setDescription] = useState('');
  const [amount, setAmount] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const title = kind === 'booking_times' ? t('cblock.timesTitle', 'Send booking times') : kind === 'form' ? t('cblock.formTitle', 'Ask for a form') : t('cblock.payTitle', 'Send a payment link');
  const ready =
    kind === 'booking_times'
      ? serviceId > 0 && times.some((x) => x !== '')
      : kind === 'form'
        ? formId > 0
        : description.trim() !== '' && /^\d+(\.\d{1,3})?$/.test(amount.trim());

  const send = async () => {
    setBusy(true);
    setError(null);
    try {
      if (kind === 'payment') {
        const link = await api.createPayment(amount.trim(), currency, description.trim());
        await api.sendCustomerBlock(conversationId, { type: 'payment', text: words, url: link.url, reference: link.reference, amount: amount.trim(), currency, description: description.trim() });
      } else if (kind === 'booking_times') {
        await api.sendCustomerBlock(conversationId, {
          type: 'booking_times',
          text: words,
          service_id: serviceId,
          times: times.filter((x) => x !== '').map((x) => new Date(x).toISOString()),
        });
      } else {
        await api.sendCustomerBlock(conversationId, { type: 'form', text: words, form_id: formId });
      }
      onSent();
    } catch (e) {
      setError(chatErrorText(e, t('cblock.error', 'It was not sent. Please try again.')));
    } finally {
      setBusy(false);
    }
  };

  const select = 'h-10 w-full rounded-control border border-input bg-card px-3 text-sm';

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="sm:max-w-[30rem]" closeLabel={t('action.close', 'Close')}>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{t('cblock.hint', '{name} gets your words with the link under them.', { name: customerName })}</DialogDescription>
        </DialogHeader>
        {kind === 'booking_times' && (
          <div className="grid gap-3">
            <label className="grid gap-1.5 text-sm">
              {t('cblock.serviceLabel', 'Service')}
              <select className={select} value={serviceId} onChange={(e) => setServiceId(Number(e.target.value))}>
                {options.services.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </select>
            </label>
            <div className="grid gap-2">
              <span className="text-sm">{t('cblock.times', 'Times to suggest')}</span>
              {times.map((value, i) => (
                <Input
                  key={i}
                  type="datetime-local"
                  aria-label={t('cblock.timeN', 'Time {n}', { n: String(i + 1) })}
                  value={value}
                  onChange={(e) => setTimes((prev) => prev.map((x, j) => (j === i ? e.target.value : x)))}
                />
              ))}
              {times.length < 6 && (
                <Button type="button" variant="ghost" size="sm" icon="plus" className="justify-self-start" onClick={() => setTimes((prev) => [...prev, ''])}>
                  {t('cblock.addTime', 'Add a time')}
                </Button>
              )}
            </div>
          </div>
        )}
        {kind === 'form' && (
          <label className="grid gap-1.5 text-sm">
            {t('cblock.formLabelPick', 'Form')}
            <select className={select} value={formId} onChange={(e) => setFormId(Number(e.target.value))}>
              {options.forms.map((f) => (
                <option key={f.id} value={f.id}>
                  {f.title}
                </option>
              ))}
            </select>
          </label>
        )}
        {kind === 'payment' && (
          <div className="grid gap-3">
            <Input label={t('cblock.payFor', 'What it is for')} maxLength={200} value={description} onChange={(e) => setDescription(e.target.value)} />
            <Input label={t('cblock.amount', 'Amount ({currency})', { currency })} inputMode="decimal" dir="ltr" value={amount} onChange={(e) => setAmount(e.target.value)} />
          </div>
        )}
        <Textarea
          aria-label={t('cblock.words', 'Your words')}
          placeholder={t('cblock.wordsHint', 'A line for {name} (optional)', { name: customerName })}
          rows={2}
          maxLength={1000}
          value={words}
          onChange={(e) => setWords(e.target.value)}
        />
        {error !== null && (
          <p role="alert" className={cn('text-sm text-error-text')}>
            {error}
          </p>
        )}
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            {t('action.cancel', 'Cancel')}
          </Button>
          <Button type="button" icon="send" disabled={!ready} loading={busy} onClick={() => void send()}>
            {t('cblock.send', 'Send')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
