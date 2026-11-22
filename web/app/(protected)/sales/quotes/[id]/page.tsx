'use client';

/**
 * /sales/quotes/[id] (WHIT-1341, design section 34 §3.2-§3.8): one quote,
 * on section 21's record page until the brand team's card arrives.
 *
 * - Quote: its lines, totals in its own currency (CU.1-CU.4), terms, the
 *   team's notes, and the customer's answer once there is one.
 * - History: drafted, sent for approval, approved, sent, answered (online or
 *   recorded by someone), turned into, withdrawn; and its other revisions.
 *
 * The one main button follows the state: Send while a draft or ready, Record
 * the answer once sent, Turn into once accepted, New revision once declined or
 * expired. A draft is changed in place. Withdrawing can't be undone, so it
 * asks first. Print saves the PDF in English or Arabic: the copy it went out
 * as, with the QR to accept online, or a working copy while it is a draft.
 */

import { use, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { AdminHeader } from '@/components/admin/admin-header';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { UserText } from '@/components/ui/user-text';
import { PrintMenu } from '@/components/inventory/print-menu';
import { QuoteForm } from '@/components/sales/quote-form';
import { QuoteAnswerDialog, QuoteLinkDialog, SendQuoteDialog } from '@/components/sales/quote-dialogs';
import { QuoteStatus, useQuoteStatusLabel } from '@/components/sales/quote-status';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { quantityText } from '@/lib/inventory/api';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@whity/ui/alert-dialog';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Money } from '@whity/ui/money/money';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { useDateDisplay } from '@whity/features/datetime';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { QUOTES_READ, QUOTES_WRITE, percentText, salesApi, salesErrorText, type SalesQuote } from '@/lib/sales/api';

type Tab = 'quote' | 'history';

interface HistoryEntry {
  at: string;
  text: string;
  icon: 'edit' | 'send' | 'check' | 'close' | 'link' | 'clock';
}

export default function QuotePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const quoteId = Number(id);
  const t = useTranslation('admin');
  const router = useRouter();
  const language = useLanguageCode();
  const dates = useDateDisplay();
  const toast = useToast();
  const statusLabel = useQuoteStatusLabel();
  const { has, loading: accessLoading } = useCapabilities();
  const canWrite = has(QUOTES_WRITE);
  const [tab, setTab] = useState<Tab>('quote');
  const [editing, setEditing] = useState(false);
  const [sending, setSending] = useState(false);
  const [answering, setAnswering] = useState(false);
  const [shownLink, setShownLink] = useState<{ link: string; emailed: boolean | null } | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);

  const { data, error, forbidden, refetch } = useFetch(async () => {
    const quote = await salesApi.quote(quoteId);
    const targets = quote.status === 'accepted' || quote.status === 'converted' ? await salesApi.targets(quoteId).catch(() => []) : [];
    return { quote, targets };
  }, [quoteId]);

  if (!accessLoading && !has(QUOTES_READ)) {
    return <NoAccess area={t('sales.area', 'sales')} permission={QUOTES_READ} />;
  }
  if (error) {
    return <LoadProblem forbidden={forbidden} message={t('sales.error.quote', 'The quote could not be loaded.')} onRetry={refetch} area={t('sales.area', 'sales')} />;
  }
  if (data === null) {
    return <div className="h-48 animate-pulse rounded-lg bg-muted" aria-busy="true" />;
  }

  const { quote, targets } = data;
  const lines = quote.lines ?? [];
  const number = quote.number ?? t('sales.draftQuote', 'Draft quote');
  const fileName = quote.number ? `${quote.number}${quote.revision > 1 ? `-r${quote.revision}` : ''}` : `quote-${quote.id}`;

  const act = async (what: string, work: () => Promise<unknown>, done: string) => {
    setBusy(what);
    setProblem(null);
    try {
      await work();
      toast.addToast(done, 'success');
      refetch();
    } catch (e) {
      setProblem(salesErrorText(e));
    } finally {
      setBusy(null);
    }
  };

  const history: HistoryEntry[] = [
    {
      at: quote.created_at,
      text: quote.created_by_name ? t('sales.history.created', 'Drafted by {name}', { name: quote.created_by_name }) : t('sales.history.createdNoName', 'Drafted'),
      icon: 'edit' as const,
    },
    ...(quote.submitted_at ? [{ at: quote.submitted_at, text: t('sales.history.submitted', 'Sent for approval'), icon: 'send' as const }] : []),
    ...(quote.approved_at
      ? [
          {
            at: quote.approved_at,
            text: quote.approved_by_name ? t('sales.history.approved', 'Approved by {name}', { name: quote.approved_by_name }) : t('sales.history.approvedNoName', 'Approved'),
            icon: 'check' as const,
          },
        ]
      : []),
    ...(quote.sent_at ? [{ at: quote.sent_at, text: t('sales.history.sent', 'Sent to {customer}', { customer: quote.contact_name }), icon: 'send' as const }] : []),
    ...(quote.decided_at
      ? [
          {
            at: quote.decided_at,
            text:
              quote.status === 'declined'
                ? quote.decided_online
                  ? t('sales.history.declinedOnline', 'Declined online')
                  : t('sales.history.declined', 'Declined, recorded by {name}', { name: quote.decided_by_profile_name ?? '' })
                : quote.decided_online
                  ? t('sales.history.acceptedOnline', 'Accepted online by {name}', { name: quote.decided_by_name ?? '' })
                  : t('sales.history.accepted', 'Accepted by {name}, recorded by {by}', { name: quote.decided_by_name ?? '', by: quote.decided_by_profile_name ?? '' }),
            icon: quote.status === 'declined' ? ('close' as const) : ('check' as const),
          },
        ]
      : []),
    ...(quote.converted_at ? [{ at: quote.converted_at, text: t('sales.history.converted', 'Turned into {what}', { what: (quote.conversions ?? []).map((c) => c.label ?? c.target).join(', ') }), icon: 'check' as const }] : []),
    ...(quote.cancelled_at ? [{ at: quote.cancelled_at, text: t('sales.history.cancelled', 'Withdrawn'), icon: 'close' as const }] : []),
  ].sort((a, b) => a.at.localeCompare(b.at));

  const withdraw = (
    <AlertDialog>
      <AlertDialogTrigger asChild>
        <Button variant="outline" disabled={busy !== null} data-testid="quote-withdraw">
          {t('sales.withdraw', 'Withdraw')}
        </Button>
      </AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{t('sales.withdraw.title', 'Withdraw this quote?')}</AlertDialogTitle>
          <AlertDialogDescription>
            {quote.status === 'sent'
              ? t('sales.withdraw.sentHint', 'Its link stops working and {customer} can no longer accept it. This can’t be undone; you can write a new quote.', {
                  customer: quote.contact_name,
                })
              : t('sales.withdraw.draftHint', 'It stays in the list as withdrawn. This can’t be undone.')}
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>{t('action.cancel', 'Cancel')}</AlertDialogCancel>
          <AlertDialogAction onClick={() => void act('cancel', () => salesApi.cancel(quote.id), t('sales.withdrawn', 'Quote withdrawn.'))}>
            {t('sales.withdraw', 'Withdraw')}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );

  const revise = (main: boolean) => (
    <Button
      variant={main ? 'default' : 'outline'}
      loading={busy === 'revise'}
      disabled={busy !== null}
      data-testid="quote-revise"
      onClick={() => {
        setBusy('revise');
        setProblem(null);
        salesApi
          .revise(quote.id)
          .then((next) => router.push(`/sales/quotes/${next.id}`))
          .catch((e: unknown) => setProblem(salesErrorText(e)))
          .finally(() => setBusy(null));
      }}
    >
      <Icon name="edit" />
      {t('sales.revise', 'New revision')}
    </Button>
  );

  let actions: React.ReactNode = <QuoteStatus status={quote.status} />;
  if (canWrite && !editing) {
    if (quote.status === 'draft' || quote.status === 'ready') {
      actions = (
        <>
          {quote.status === 'draft' && (
            <Button variant="outline" onClick={() => setEditing(true)} data-testid="quote-edit">
              <Icon name="edit" />
              {t('sales.edit', 'Change')}
            </Button>
          )}
          {withdraw}
          <Button onClick={() => setSending(true)} disabled={busy !== null} data-testid="quote-send">
            <Icon name="send" />
            {t('sales.send', 'Send')}
          </Button>
        </>
      );
    } else if (quote.status === 'sent') {
      actions = (
        <>
          <Button
            variant="outline"
            loading={busy === 'link'}
            disabled={busy !== null}
            data-testid="quote-new-link"
            onClick={() => {
              setBusy('link');
              setProblem(null);
              salesApi
                .newLink(quote.id)
                .then((link) => setShownLink({ link, emailed: null }))
                .catch((e: unknown) => setProblem(salesErrorText(e)))
                .finally(() => setBusy(null));
            }}
          >
            <Icon name="link" />
            {t('sales.newLink', 'New link')}
          </Button>
          {revise(false)}
          {withdraw}
          <Button onClick={() => setAnswering(true)} disabled={busy !== null} data-testid="quote-answer">
            {t('sales.answer', 'Record the answer')}
          </Button>
        </>
      );
    } else if (quote.status === 'declined' || quote.status === 'expired') {
      actions = (
        <>
          <QuoteStatus status={quote.status} />
          {revise(true)}
        </>
      );
    }
  }

  return (
    <div className="space-y-6">
      <AdminHeader
        breadcrumb={<Breadcrumb linkComponent={Link} items={[{ label: t('sales.title', 'Sales'), href: '/sales' }, { label: number }]} />}
        title={quote.contact_name}
        description={[
          quote.number
            ? t('sales.heading', 'Quote {number}, revision {revision}', { number: quote.number, revision: quote.revision })
            : t('sales.headingDraft', 'Draft quote, not sent yet'),
          t('sales.validUntil', 'valid until {date}', { date: dates.date(quote.valid_until) ?? '' }),
          statusLabel(quote.status),
        ].join(' · ')}
        action={
          <div className="flex flex-wrap items-center gap-2">
            {quote.status !== 'cancelled' && !editing && <PrintMenu print={(lang) => salesApi.print(quote.id, lang)} fileName={fileName} testId="quote-print" />}
            {actions}
          </div>
        }
      />

      {quote.status === 'waiting_approval' && (
        <Alert>
          <AlertDescription>{t('sales.waitingNotice', 'Waiting for approval on its route. It can be sent once approved.')}</AlertDescription>
        </Alert>
      )}
      {quote.status === 'replaced' && (
        <Alert>
          <AlertDescription>{t('sales.replacedNotice', 'A newer revision of this quote has gone out; its link no longer opens this one.')}</AlertDescription>
        </Alert>
      )}
      {problem && (
        <Alert variant="destructive">
          <AlertDescription>{problem}</AlertDescription>
        </Alert>
      )}

      {editing ? (
        <QuoteForm
          quote={quote}
          currency={quote.currency}
          saveLabel={t('sales.form.save', 'Save changes')}
          onCancel={() => setEditing(false)}
          onSave={async (body) => {
            await salesApi.update(quote.id, body);
            setEditing(false);
            toast.addToast(t('sales.quote.saved', 'Draft saved.'), 'success');
            refetch();
          }}
        />
      ) : (
        <>
          {(quote.status === 'accepted' || quote.status === 'converted') && (
            <section className="space-y-3 rounded-lg border border-border p-5" data-testid="quote-turn-into">
              <h2 className="font-semibold">{t('sales.turnInto', 'Turn it into')}</h2>
              {targets.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  {t('sales.turnInto.none', 'Nothing yet. When sales orders or invoices are on here, an accepted quote turns into one in a click.')}
                </p>
              ) : (
                <ul className="flex flex-wrap gap-2">
                  {targets.map((target) => (
                    <li key={target.key}>
                      {target.done ? (
                        <span className="inline-flex min-h-10 items-center gap-2 rounded-md border border-border px-3 text-sm">
                          <Icon name="check" size={16} />
                          {target.done.label ?? (language === 'ar' ? target.label.ar : target.label.en)}
                        </span>
                      ) : (
                        <Button
                          variant="outline"
                          disabled={!target.possible || busy !== null || !canWrite}
                          title={target.possible ? undefined : target.reason}
                          loading={busy === `convert-${target.key}`}
                          onClick={() => void act(`convert-${target.key}`, () => salesApi.convert(quote.id, target.key), t('sales.converted', 'Done.'))}
                        >
                          {language === 'ar' ? target.label.ar : target.label.en}
                        </Button>
                      )}
                      {!target.possible && !target.done && target.reason && <p className="mt-1 text-sm text-muted-foreground">{target.reason}</p>}
                    </li>
                  ))}
                </ul>
              )}
            </section>
          )}

          <Tabs value={tab} onValueChange={(v) => setTab(v as Tab)}>
            <TabsList>
              <TabsTrigger value="quote">{t('sales.tab.quote', 'Quote')}</TabsTrigger>
              <TabsTrigger value="history">{t('sales.tab.history', 'History')}</TabsTrigger>
            </TabsList>
          </Tabs>

          {tab === 'quote' ? (
            <QuoteBody quote={quote} lines={lines} />
          ) : (
            <div className="space-y-4">
              <ol className="divide-y divide-border rounded-lg border border-border" data-testid="quote-history">
                {history.map((entry, i) => (
                  <li key={`${entry.at}-${i}`} className="flex items-center gap-3 px-4 py-3 text-sm">
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                      <Icon name={entry.icon} size={16} />
                    </span>
                    <span className="min-w-0 flex-1">
                      <UserText>{entry.text}</UserText>
                    </span>
                    <span className="text-sm text-muted-foreground">{dates.dateTime(entry.at) ?? ''}</span>
                  </li>
                ))}
              </ol>
              {(quote.revisions ?? []).length > 1 && (
                <section className="rounded-lg border border-border p-4">
                  <h2 className="mb-2 font-semibold">{t('sales.revisions', 'Revisions')}</h2>
                  <ul className="space-y-1 text-sm">
                    {(quote.revisions ?? []).map((r) => (
                      <li key={r.id}>
                        {r.id === quote.id ? (
                          <span className="font-semibold">{t('sales.revisionThis', 'Revision {n} (this one)', { n: r.revision })}</span>
                        ) : (
                          <Link href={`/sales/quotes/${r.id}`} className="text-primary hover:underline">
                            {t('sales.revisionN', 'Revision {n}', { n: r.revision })}
                          </Link>
                        )}
                        <span className="ms-2 text-muted-foreground">{statusLabel(r.status as SalesQuote['status'])}</span>
                      </li>
                    ))}
                  </ul>
                </section>
              )}
            </div>
          )}
        </>
      )}

      {sending && (
        <SendQuoteDialog
          quote={quote}
          onOpenChange={setSending}
          onNeedsApproval={() => {
            setSending(false);
            void act('submit', () => salesApi.submit(quote.id), t('sales.submitted', 'Sent for approval. It goes out once approved.'));
          }}
          onSend={async (email) => {
            const sent = await salesApi.send(quote.id, email);
            setSending(false);
            setShownLink({ link: sent.link, emailed: email ? sent.emailed : null });
            if (email && !sent.emailed) {
              toast.addToast(t('sales.notEmailed', 'Sent, but the email didn’t go. Copy the link and send it yourself.'), 'warning');
            }
            refetch();
          }}
        />
      )}
      {answering && (
        <QuoteAnswerDialog
          quote={quote}
          onOpenChange={setAnswering}
          onAnswer={async (answer) => {
            await salesApi.decide(quote.id, answer);
            setAnswering(false);
            toast.addToast(answer.accepted ? t('sales.acceptedToast', 'Recorded as accepted.') : t('sales.declinedToast', 'Recorded as declined.'), 'success');
            refetch();
          }}
        />
      )}
      {shownLink && <QuoteLinkDialog link={shownLink.link} emailed={shownLink.emailed} onOpenChange={(open) => !open && setShownLink(null)} />}
    </div>
  );
}

function QuoteBody({ quote, lines }: { quote: SalesQuote; lines: NonNullable<SalesQuote['lines']> }) {
  const t = useTranslation('admin');
  return (
    <section className="overflow-x-auto rounded-lg border border-border" data-testid="quote-lines">
      <table className="w-full text-sm">
        <thead className="bg-muted/50 text-sm text-muted-foreground">
          <tr>
            <th className="px-4 py-3 text-start font-medium">{t('sales.column.description', 'Description')}</th>
            <th className="px-4 py-3 text-end font-medium">{t('sales.column.quantity', 'Quantity')}</th>
            <th className="px-4 py-3 text-end font-medium">{t('sales.column.price', 'Price')}</th>
            <th className="px-4 py-3 text-end font-medium">{t('sales.column.discount', 'Discount')}</th>
            <th className="px-4 py-3 text-end font-medium">{t('sales.column.tax', 'Tax')}</th>
            <th className="px-4 py-3 text-end font-medium">{t('sales.column.amount', 'Amount')}</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {lines.map((line) => (
            <tr key={line.id} className="h-12">
              <td className="px-4 py-2">
                <UserText className="block">{line.description}</UserText>
                {line.sku && (
                  <bdi dir="ltr" className="font-mono text-sm text-muted-foreground">
                    {line.sku}
                  </bdi>
                )}
              </td>
              <td className="whitespace-nowrap px-4 py-2 text-end">
                <bdi dir="ltr" className="font-mono tabular-nums">
                  {quantityText(line.quantity)} {line.unit && <span className="text-sm text-muted-foreground">{line.unit}</span>}
                </bdi>
              </td>
              <td className="whitespace-nowrap px-4 py-2 text-end">
                <Money minor={line.price_minor} currency={quote.currency} />
              </td>
              <td className="whitespace-nowrap px-4 py-2 text-end font-mono tabular-nums" dir="ltr">
                {line.discount_bp ? `${percentText(line.discount_bp)}%` : '–'}
              </td>
              <td className="whitespace-nowrap px-4 py-2 text-end font-mono tabular-nums" dir="ltr">
                {line.tax_bp ? `${percentText(line.tax_bp)}%` : '–'}
              </td>
              <td className="whitespace-nowrap px-4 py-2 text-end">
                <Money minor={line.line_total_minor} currency={quote.currency} />
              </td>
            </tr>
          ))}
        </tbody>
        <tfoot className="border-t border-border text-sm">
          <tr>
            <td className="px-4 py-2 text-muted-foreground" colSpan={5}>
              {quote.prices_include_tax ? t('sales.subtotalIncl', 'Subtotal (prices include tax)') : t('sales.subtotal', 'Subtotal')}
            </td>
            <td className="px-4 py-2 text-end">
              <Money minor={quote.subtotal_minor} currency={quote.currency} />
            </td>
          </tr>
          <tr>
            <td className="px-4 py-2 text-muted-foreground" colSpan={5}>
              {t('sales.tax', 'Sales tax')}
            </td>
            <td className="px-4 py-2 text-end">
              <Money minor={quote.tax_minor} currency={quote.currency} />
            </td>
          </tr>
          <tr className="font-semibold">
            <td className="px-4 py-3" colSpan={5}>
              {t('sales.total', 'Total')}
            </td>
            <td className="px-4 py-3 text-end">
              <Money minor={quote.total_minor} currency={quote.currency} />
            </td>
          </tr>
        </tfoot>
      </table>
      {(quote.terms !== '' || quote.notes !== '' || quote.decline_reason !== '') && (
        <dl className="divide-y divide-border border-t border-border text-sm">
          {quote.terms !== '' && (
            <div className="grid gap-1 px-4 py-3 sm:grid-cols-[12rem_1fr]">
              <dt className="text-muted-foreground">{t('sales.form.terms', 'Terms')}</dt>
              <dd>
                <UserText>{quote.terms}</UserText>
              </dd>
            </div>
          )}
          {quote.notes !== '' && (
            <div className="grid gap-1 px-4 py-3 sm:grid-cols-[12rem_1fr]">
              <dt className="text-muted-foreground">{t('sales.form.notes', 'Notes for the team')}</dt>
              <dd>
                <UserText>{quote.notes}</UserText>
              </dd>
            </div>
          )}
          {quote.decline_reason !== '' && (
            <div className="grid gap-1 px-4 py-3 sm:grid-cols-[12rem_1fr]">
              <dt className="text-muted-foreground">{t('sales.declineReason', 'Why they declined')}</dt>
              <dd>
                <UserText>{quote.decline_reason}</UserText>
              </dd>
            </div>
          )}
        </dl>
      )}
    </section>
  );
}
