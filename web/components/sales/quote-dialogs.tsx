'use client';

/**
 * The quote's dialogs (WHIT-1341, design section 34 §3.2-§3.3):
 *
 * - Send: email it to the customer, or only make the link to share yourself.
 * - The link: shown once, because only its hash is kept (QT.5); Copy, and a
 *   word that a new link stops the old one working.
 * - The customer's answer, given by phone or in person: accepted, with the
 *   name of who accepted, or declined, with a reason if they gave one.
 */

import { useState } from 'react';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Checkbox } from '@whity/ui/checkbox';
import { Icon } from '@whity/ui/icon';
import { Textarea } from '@whity/ui/textarea';
import { useTranslation } from '@whity/features/i18n';
import { salesErrorText, SalesRequestError, type SalesQuote } from '@/lib/sales/api';

export function SendQuoteDialog({
  quote,
  onOpenChange,
  onSend,
  onNeedsApproval,
}: {
  quote: SalesQuote;
  onOpenChange: (open: boolean) => void;
  onSend: (email: boolean) => Promise<void>;
  /** The workspace approves quotes before they go out: send it for approval instead. */
  onNeedsApproval: () => void;
}) {
  const t = useTranslation('admin');
  const hasEmail = (quote.contact_email ?? '') !== '';
  const [email, setEmail] = useState(hasEmail);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const send = async () => {
    setBusy(true);
    setProblem(null);
    try {
      await onSend(email);
    } catch (e) {
      if (e instanceof SalesRequestError && e.code === 'route_needed') {
        onNeedsApproval();
        return;
      }
      setProblem(salesErrorText(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent data-testid="quote-send-dialog">
        <DialogHeader>
          <DialogTitle>{t('sales.send.title', 'Send the quote')}</DialogTitle>
          <DialogDescription>
            {t('sales.send.hint', 'It takes its number and a link where {customer} can accept or decline it online. After this it changes only through a new revision.', {
              customer: quote.contact_name,
            })}
          </DialogDescription>
        </DialogHeader>
        <label className="flex items-start gap-2 text-sm">
          <Checkbox checked={email} disabled={!hasEmail} onCheckedChange={(v) => setEmail(v === true)} className="mt-0.5" data-testid="quote-send-email" />
          <span>
            {hasEmail
              ? t('sales.send.email', 'Email it to {email}', { email: quote.contact_email ?? '' })
              : t('sales.send.noEmail', 'The customer has no email address: copy the link and send it yourself.')}
          </span>
        </label>
        {problem && (
          <Alert variant="destructive">
            <AlertDescription>{problem}</AlertDescription>
          </Alert>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>
            {t('action.cancel', 'Cancel')}
          </Button>
          <Button onClick={() => void send()} loading={busy} data-testid="quote-send-confirm">
            <Icon name="send" />
            {email ? t('sales.send.sendEmail', 'Send') : t('sales.send.makeLink', 'Make the link')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function QuoteLinkDialog({ link, emailed, onOpenChange }: { link: string; emailed: boolean | null; onOpenChange: (open: boolean) => void }) {
  const t = useTranslation('admin');
  const [copied, setCopied] = useState(false);

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent data-testid="quote-link-dialog">
        <DialogHeader>
          <DialogTitle>
            {emailed === true ? t('sales.link.emailedTitle', 'Sent by email') : t('sales.link.title', 'The customer’s link')}
          </DialogTitle>
          <DialogDescription>
            {t('sales.link.hint', 'Copy it now: Whity keeps only a fingerprint of it, so it is shown only this once. Make a new link later and this one stops working.')}
          </DialogDescription>
        </DialogHeader>
        <div className="flex gap-2">
          <Input id="quote-link" dir="ltr" readOnly value={link} className="font-mono" aria-label={t('sales.link.label', 'Link to the quote')} onFocus={(e) => e.target.select()} />
          <Button
            variant="outline"
            data-testid="quote-link-copy"
            onClick={() => {
              void navigator.clipboard.writeText(link).then(() => setCopied(true));
            }}
          >
            <Icon name={copied ? 'check' : 'copy'} />
            {copied ? t('sales.link.copied', 'Copied') : t('sales.link.copy', 'Copy')}
          </Button>
        </div>
        <DialogFooter>
          <Button onClick={() => onOpenChange(false)}>{t('sales.link.done', 'Done')}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function QuoteAnswerDialog({
  quote,
  onOpenChange,
  onAnswer,
}: {
  quote: SalesQuote;
  onOpenChange: (open: boolean) => void;
  onAnswer: (answer: { accepted: boolean; name?: string; reason?: string }) => Promise<void>;
}) {
  const t = useTranslation('admin');
  const [accepted, setAccepted] = useState(true);
  const [name, setName] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const save = async () => {
    if (accepted && name.trim() === '') {
      setProblem(t('sales.answer.needName', 'Write the name of who accepted.'));
      return;
    }
    setBusy(true);
    setProblem(null);
    try {
      await onAnswer(accepted ? { accepted, name: name.trim() } : { accepted, reason: reason.trim() });
    } catch (e) {
      setProblem(salesErrorText(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent data-testid="quote-answer-dialog">
        <DialogHeader>
          <DialogTitle>{t('sales.answer.title', 'Record the customer’s answer')}</DialogTitle>
          <DialogDescription>
            {t('sales.answer.hint', 'For an answer {customer} gave by phone or in person. An answer given online is recorded on its own.', {
              customer: quote.contact_name,
            })}
          </DialogDescription>
        </DialogHeader>
        <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label={t('sales.answer.which', 'Their answer')}>
          {[true, false].map((yes) => (
            <button
              key={String(yes)}
              type="button"
              role="radio"
              aria-checked={accepted === yes}
              onClick={() => setAccepted(yes)}
              data-testid={yes ? 'quote-answer-accepted' : 'quote-answer-declined'}
              className={`min-h-11 rounded-md border px-3 text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring ${
                accepted === yes ? 'border-primary bg-primary/5' : 'border-border hover:bg-muted'
              }`}
            >
              {yes ? t('sales.answer.accepted', 'They accepted') : t('sales.answer.declined', 'They declined')}
            </button>
          ))}
        </div>
        {accepted ? (
          <Input id="quote-answer-name" dir="auto" label={t('sales.answer.name', 'Who accepted')} value={name} onChange={(e) => setName(e.target.value)} />
        ) : (
          <div className="space-y-1.5">
            <label htmlFor="quote-answer-reason" className="text-sm font-medium">
              {t('sales.answer.reason', 'Why, if they said')}
            </label>
            <Textarea id="quote-answer-reason" dir="auto" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
          </div>
        )}
        {problem && (
          <Alert variant="destructive">
            <AlertDescription>{problem}</AlertDescription>
          </Alert>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>
            {t('action.cancel', 'Cancel')}
          </Button>
          <Button onClick={() => void save()} loading={busy} data-testid="quote-answer-save">
            {t('sales.answer.save', 'Record the answer')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
