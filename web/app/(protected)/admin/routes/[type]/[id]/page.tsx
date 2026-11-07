'use client';

/**
 * Reviewing a subject that travels on a route without a document (WHIT-920).
 *
 * A form submission no longer raises a document when it is sent: its route
 * travels on the submission, and the finalise step makes the document later. So
 * the person a route reached opens it here rather than in the document library:
 * the answers, what changed since the version they last acted on, the trail,
 * and their own item, which they answer with the same panel a document route
 * uses — plus sending it back to the person who sent it, for changes.
 *
 * Everything comes from `GET /api/v1/routes?subject_type=…&subject_id=…`, which
 * 404s anybody the route never reached.
 */

import { use, useCallback, useEffect, useMemo, useState } from 'react';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { PageHeader } from '@whity/ui/page-header';
import { AreaError } from '@whity/ui/page-states';
import { RadioGroup, RadioGroupItem } from '@whity/ui/radio-group';
import { Textarea } from '@whity/ui/textarea';
import { RecordTimeline, RecordTimelineItem } from '@whity/features/record';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { useAuth } from '@/lib/auth-context';
import { useDirection } from '@/lib/direction-context';
import { useToast } from '@/lib/toast-context';
import { RouteActPanel } from '@/components/documents/route-act-panel';
import type { DocumentRoute, RouteRecipient } from '@/components/documents/routing-wire';

type Localized = Record<string, string> | string | null | undefined;

interface SubjectField {
  field_key: string;
  field_type: string;
  label: Localized;
  options?: Array<{ value: string; label: Localized }>;
}

interface TrailEntry {
  id: number;
  action: string;
  actor_profile_id: number | null;
  note: string | null;
  verdict: 'approved' | 'rejected' | null;
  to_author: boolean;
  occurred_at: string;
}

interface SubjectRoute extends DocumentRoute {
  trail: TrailEntry[];
  recipients: RouteRecipient[];
  changes: {
    seen_version: number | null;
    current_version: number | null;
    changes: Array<{ key: string; before: unknown; after: unknown }>;
  };
}

interface SubjectView {
  subject: {
    type: string;
    id: number;
    title: string | null;
    document_id: number | null;
    version: number | null;
    can_send_back: boolean;
    form?: {
      fields: SubjectField[];
      files: Array<{ id: number; field_key: string; filename: string | null }>;
      data: Record<string, unknown> | [];
    } | null;
  };
  routes: SubjectRoute[];
  people: Record<string, string>;
}

function text(value: Localized, preferArabic: boolean): string {
  if (value === null || value === undefined) return '';
  if (typeof value === 'string') return value;
  return (preferArabic ? value.ar || value.en : value.en || value.ar) ?? Object.values(value)[0] ?? '';
}

function answerText(field: SubjectField | undefined, value: unknown, preferArabic: boolean, yes: string, no: string): string {
  if (value === null || value === undefined || value === '') return '';
  if (typeof value === 'boolean') return value ? yes : no;
  const option = (raw: unknown) => {
    const match = field?.options?.find((o) => o.value === String(raw));
    return match ? text(match.label, preferArabic) || String(raw) : String(raw);
  };
  if (Array.isArray(value)) return value.map((item) => (typeof item === 'object' && item !== null ? '' : option(item))).filter(Boolean).join('; ');
  if (typeof value === 'object') return '';
  return option(value);
}

export default function RouteSubjectPage({ params }: { params: Promise<{ type: string; id: string }> }) {
  const { type, id } = use(params);
  const t = useTranslation('documents');
  const { apiClient, user } = useAuth();
  const { dir } = useDirection();
  const preferArabic = dir === 'rtl';
  const { dateTime } = useDateDisplay();
  const viewerProfileId = user?.id ?? null;

  const [view, setView] = useState<SubjectView | null>(null);
  const [failed, setFailed] = useState<'missing' | 'error' | null>(null);
  const [version, setVersion] = useState(0);

  const load = useCallback(async () => {
    setFailed(null);
    try {
      const response = await apiClient(
        `/api/v1/routes?subject_type=${encodeURIComponent(type)}&subject_id=${encodeURIComponent(id)}`,
        { headers: { Accept: 'application/json' } }
      );
      if (response.status === 404) {
        setFailed('missing');
        return;
      }
      const body = (await response.json().catch(() => null)) as { data?: SubjectView } | null;
      if (!response.ok || !body?.data) {
        setFailed('error');
        return;
      }
      setView(body.data);
    } catch {
      setFailed('error');
    }
  }, [apiClient, id, type]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the fetch on mount is the effect
    void load();
  }, [load, version]);

  const nameOf = useCallback(
    (profileId: number | null) =>
      profileId === null
        ? t('record.person.gone', 'An account that no longer exists')
        : view?.people[String(profileId)] || t('record.person.former', 'A former member'),
    [t, view]
  );

  if (failed === 'missing') {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{t('subject.missing.title', 'This could not be found')}</CardTitle>
          <CardDescription>{t('subject.missing.body', 'It does not exist, or it was never sent to you.')}</CardDescription>
        </CardHeader>
      </Card>
    );
  }
  if (failed === 'error') {
    return <AreaError message={t('subject.error', 'This could not be loaded.')} retryLabel={t('subject.retry', 'Try again')} onRetry={() => void load()} />;
  }
  if (view === null) {
    return <p className="text-sm text-muted-foreground">{t('subject.loading', 'Loading…')}</p>;
  }

  const form = view.subject.form ?? null;
  const answers = form === null || Array.isArray(form.data) ? {} : form.data;
  const fieldByKey = new Map((form?.fields ?? []).map((field) => [field.field_key, field]));
  const labelOf = (key: string) => text(fieldByKey.get(key)?.label, preferArabic) || key;

  return (
    <div className="space-y-6">
      <PageHeader
        title={view.subject.title ?? t('subject.untitled', 'Request')}
        description={
          view.subject.type === 'form_submission'
            ? t('subject.formSubmission', 'A form somebody sent. It has no document yet: one is made when its route is finished.')
            : undefined
        }
      />

      {form !== null && (
        <Card>
          <CardHeader>
            <CardTitle>{t('subject.answers', 'Answers')}</CardTitle>
            {view.subject.version !== null && (
              <CardDescription>{t('subject.version', 'Version {number}', { number: view.subject.version })}</CardDescription>
            )}
          </CardHeader>
          <CardContent>
            <dl className="space-y-4">
              {form.fields.map((field) => {
                const files = form.files.filter((file) => file.field_key === field.field_key);
                const routeId = view.routes[0]?.id;
                return (
                  <div key={field.field_key} className="space-y-0.5">
                    <dt className="text-sm font-medium">{labelOf(field.field_key)}</dt>
                    <dd className="text-sm text-muted-foreground">
                      {field.field_type === 'file' && files.length > 0 && routeId !== undefined ? (
                        <span className="flex flex-col items-start gap-2 pt-1">
                          {files.map((file) => (
                            <Button key={file.id} asChild variant="outline" className="h-auto min-h-10 max-w-full whitespace-normal break-all text-start">
                              <a href={`/api/v1/routes/${routeId}/files/${file.id}`} download>
                                {t('subject.download', 'Download {name}', { name: file.filename ?? t('subject.attachedFile', 'attached file') })}
                              </a>
                            </Button>
                          ))}
                        </span>
                      ) : (
                        answerText(field, answers[field.field_key], preferArabic, t('subject.yes', 'Yes'), t('subject.no', 'No')) ||
                        t('subject.noAnswer', 'No answer')
                      )}
                    </dd>
                  </div>
                );
              })}
            </dl>
          </CardContent>
        </Card>
      )}

      {view.routes.map((route) => (
        <Card key={route.id}>
          <CardHeader>
            <CardTitle>{route.title}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-6">
            {route.changes.changes.length > 0 && (
              <section className="space-y-2 rounded-control border border-border p-3" aria-label={t('subject.changes', 'What changed since you last saw it')}>
                <h3 className="text-sm font-medium">
                  {t('subject.changes.title', 'Changed since version {seen}', { seen: route.changes.seen_version ?? 0 })}
                </h3>
                <ul className="space-y-1 text-sm">
                  {route.changes.changes.map((change) => {
                    const field = fieldByKey.get(change.key);
                    const yes = t('subject.yes', 'Yes');
                    const no = t('subject.no', 'No');
                    return (
                      <li key={change.key}>
                        <span className="font-medium">{labelOf(change.key)}</span>
                        {': '}
                        <span className="text-muted-foreground line-through">
                          {answerText(field, change.before, preferArabic, yes, no) || t('subject.noAnswer', 'No answer')}
                        </span>
                        {' → '}
                        <span>{answerText(field, change.after, preferArabic, yes, no) || t('subject.noAnswer', 'No answer')}</span>
                      </li>
                    );
                  })}
                </ul>
              </section>
            )}

            <RouteActPanel
              documentId={route.document_id ?? 0}
              route={route}
              recipients={route.recipients}
              viewerProfileId={viewerProfileId}
              actionsUrl={`/api/v1/routes/${route.id}/actions`}
              onActed={() => setVersion((current) => current + 1)}
            />

            <SendBack
              route={route}
              viewerProfileId={viewerProfileId}
              canSendBack={view.subject.can_send_back}
              onSent={() => setVersion((current) => current + 1)}
            />

            <section className="space-y-2">
              <h3 className="text-sm font-medium">{t('subject.trail', 'What happened')}</h3>
              <RecordTimeline>
                {route.trail.map((entry) => (
                  <RecordTimelineItem
                    key={entry.id}
                    title={
                      <span className="font-sans">
                        {trailWord(t, entry)}
                        {entry.verdict !== null && (
                          <Badge variant={entry.verdict === 'approved' ? 'success' : 'secondary'} className="ms-2">
                            {entry.verdict === 'approved' ? t('subject.approved', 'Approved') : t('subject.rejected', 'Not approved')}
                          </Badge>
                        )}
                      </span>
                    }
                    meta={`${nameOf(entry.actor_profile_id)} · ${dateTime(entry.occurred_at) ?? ''}`}
                    detail={entry.note ?? undefined}
                  />
                ))}
              </RecordTimeline>
            </section>
          </CardContent>
        </Card>
      ))}
    </div>
  );
}

function trailWord(t: (key: string, fallback?: string) => string, entry: TrailEntry): string {
  switch (entry.action) {
    case 'issued':
      return t('subject.trail.issued', 'Sent');
    case 'forwarded':
      return t('record.trail.action.forwarded', 'Forwarded');
    case 'acknowledged':
      return t('record.trail.action.acknowledged', 'Acknowledged');
    case 'returned':
      return t('record.trail.action.returned', 'Returned');
    case 'noted':
      return entry.to_author ? t('subject.trail.notedToAuthor', 'Note for the sender') : t('record.trail.action.noted', 'Note added');
    case 'sent_back':
      return t('subject.trail.sentBack', 'Sent back for changes');
    case 'resubmitted':
      return t('subject.trail.resubmitted', 'Sent again with changes');
    default:
      return entry.action;
  }
}

/**
 * Send it back to the person who sent it, for changes (WHIT-919's act). Offered
 * to a person holding an open item on a stage that allows it, when the subject
 * has somebody to send it back to: an anonymous submission has nobody.
 */
function SendBack({
  route,
  viewerProfileId,
  canSendBack,
  onSent,
}: {
  route: SubjectRoute;
  viewerProfileId: number | null;
  canSendBack: boolean;
  onSent: () => void;
}) {
  const t = useTranslation('documents');
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [open, setOpen] = useState(false);
  const [note, setNote] = useState('');
  const [resume, setResume] = useState<'sender' | 'start'>('sender');
  const [busy, setBusy] = useState(false);
  const [refusal, setRefusal] = useState<string | null>(null);

  const myStep = useMemo(() => {
    if (viewerProfileId === null) return null;
    const item = route.recipients.find((r) => r.profile_id === viewerProfileId && r.open && (r as { role?: string }).role !== 'author');
    if (item === undefined) return null;
    return (route.steps.find((step) => step.id === item.step_id) as (typeof route.steps)[number] & { allow_send_back?: boolean }) ?? null;
  }, [route, viewerProfileId]);

  if (!canSendBack || myStep === null || myStep.allow_send_back === false) return null;

  async function send() {
    setBusy(true);
    setRefusal(null);
    try {
      const response = await apiClient(`/api/v1/routes/${route.id}/actions`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ action: 'sent_back', note: note.trim(), resume_from: resume }),
      });
      const body = (await response.json().catch(() => null)) as { error?: string } | null;
      if (!response.ok) {
        setRefusal(body?.error ?? t('subject.sendBack.failed', 'It could not be sent back. Try again.'));
        return;
      }
      addToast(t('subject.sendBack.done', 'Sent back for changes.'), 'success');
      setOpen(false);
      setNote('');
      onSent();
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <Button variant="outline" onClick={() => setOpen(true)}>
        {t('subject.sendBack', 'Send back for changes')}
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{t('subject.sendBack.title', 'Send back for changes')}</DialogTitle>
            <DialogDescription>
              {t('subject.sendBack.body', 'The person who sent it can change it and send it again. Everybody else holding it stops until then. Earlier approvals stay.')}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-1">
              <label htmlFor="send-back-note" className="text-sm font-medium">
                {t('subject.sendBack.note', 'What needs to change')}
              </label>
              <Textarea id="send-back-note" value={note} onChange={(event) => setNote(event.target.value)} rows={4} />
              <p className="text-xs text-muted-foreground">{t('subject.sendBack.noteHint', 'The person who sent it reads this.')}</p>
            </div>
            <fieldset className="space-y-2">
              <legend className="text-sm font-medium">{t('subject.sendBack.resume', 'When it comes back')}</legend>
              <RadioGroup value={resume} onValueChange={(value) => setResume(value === 'start' ? 'start' : 'sender')}>
                <label className="flex items-center gap-2 text-sm">
                  <RadioGroupItem value="sender" />
                  {t('subject.sendBack.resume.sender', 'Back to this stage')}
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <RadioGroupItem value="start" />
                  {t('subject.sendBack.resume.start', 'Start again from the first stage')}
                </label>
              </RadioGroup>
            </fieldset>
            {refusal !== null && <p className="text-sm text-destructive">{refusal}</p>}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              {t('subject.cancel', 'Cancel')}
            </Button>
            <Button onClick={() => void send()} disabled={busy || note.trim() === ''}>
              {t('subject.sendBack', 'Send back for changes')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
