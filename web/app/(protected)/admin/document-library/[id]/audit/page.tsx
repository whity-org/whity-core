'use client';

/**
 * The audit page of a document — `/admin/document-library/[id]/audit`
 * (WHIT-922, #1726).
 *
 * Every act on the document's routes and every signature, whether each
 * signature's seal checks against the instance's published key, and whether
 * the trail's hash chain is intact. Electronic signatures with an audit trail;
 * the page never calls them anything more. A company stamp (#1729) is shown as
 * what it is: its image, applied by a named holder, not a certified seal.
 *
 * Anyone who may see the document may read its audit; the server answers a
 * document they may not see as missing.
 */

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Badge } from '@whity/ui/badge';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { api } from '@/lib/api/client';
import {
  SignatureDrawing,
  type SignatureAppearance,
} from '@/components/documents/signature-drawing';

interface AuditEvent {
  id: number;
  route_id: number;
  actor_profile_id: number | null;
  action: string;
  note: string | null;
  verdict: string | null;
  withdrawal: boolean;
  subject_version: number | null;
  content_hash: string | null;
  occurred_at: string;
  prev_hash?: string | null;
  entry_hash?: string | null;
}

interface AuditSignature {
  id: number;
  event_id: number;
  subject_version: number;
  content_hash: string;
  by: { profile_id: number; name: string; email: string | null };
  capacity: string | null;
  on_behalf_of: string | null;
  confirmed_by: 'totp' | 'passkey' | 'email_code' | 'fresh_sign_in';
  at: string;
  ip_address: string | null;
  user_agent: string | null;
  consent_version: string;
  consent_wording: string | null;
  appearance: SignatureAppearance | null;
  /** #1729: a company stamp applied by a holder, or a signature. Absent on an older server: a signature. */
  kind?: 'signature' | 'stamp';
  stamp?: { id: number; name: string; legal_name: string; image: string } | null;
  record_hash: string;
  seal_key_id: string;
  seal_valid: boolean;
  details_withheld: boolean;
}

interface AuditData {
  document_id: number;
  chain: {
    intact: boolean;
    entries: number;
    linked: number;
    earlier: number;
    detached: number;
    broken_at_event_id: number | null;
    signed_without_record: number[];
  };
  events: AuditEvent[];
  signatures: AuditSignature[];
  keys: { key_id: string; algorithm: string; public_key: string; retired_at: string | null }[];
}

type LoadState = { status: 'loading' } | { status: 'missing' } | { status: 'error' } | { status: 'ready'; data: AuditData };

export default function Page() {
  const params = useParams<{ id: string | string[] }>();
  const rawId = Array.isArray(params.id) ? params.id[0] : params.id;
  const documentId = Number(rawId);
  const t = useTranslation('documents');
  const { hidden, dateTime } = useDateDisplay();
  const [state, setState] = useState<LoadState>({ status: 'loading' });
  const [people, setPeople] = useState<Record<string, string>>({});

  const load = useCallback(async () => {
    if (!Number.isInteger(documentId) || documentId <= 0) {
      setState({ status: 'missing' });
      return;
    }
    try {
      const { data, response } = await api.GET('/api/v1/documents/{id}/audit', {
        params: { path: { id: documentId } },
      });
      if (response.status === 404) {
        setState({ status: 'missing' });
        return;
      }
      if (!response.ok) {
        setState({ status: 'error' });
        return;
      }
      const body = data as unknown as { data: AuditData };
      setState({ status: 'ready', data: body.data });
      const { data: namesBody, response: names } = await api.GET('/api/v1/documents/{id}/people', {
        params: { path: { id: documentId } },
      });
      if (names.ok) {
        const named = namesBody as unknown as { data: Record<string, string> };
        setPeople(named.data ?? {});
      }
    } catch {
      setState({ status: 'error' });
    }
  }, [documentId]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- loads once per document
    void load();
  }, [load]);

  const actionLabel = (action: string, withdrawal: boolean): string => {
    if (withdrawal) return t('audit.action.withdrawal', 'Withdrew the document');
    switch (action) {
      case 'issued':
        return t('record.trail.action.issued', 'Issued');
      case 'forwarded':
        return t('record.trail.action.forwarded', 'Forwarded');
      case 'acknowledged':
        return t('record.trail.action.acknowledged', 'Acknowledged');
      case 'returned':
        return t('record.trail.action.returned', 'Returned');
      case 'noted':
        return t('record.trail.action.noted', 'Note added');
      case 'sent_back':
        return t('record.trail.action.sentBack', 'Sent back to the author for changes');
      case 'resubmitted':
        return t('record.trail.action.resubmitted', 'Resubmitted by the author');
      case 'signed':
        return t('record.trail.action.signed', 'Signed electronically');
      case 'declined':
        return t('record.trail.action.declined', 'Declined to sign');
      case 'stamped':
        return t('record.trail.action.stamped', 'Stamped');
      default:
        return action;
    }
  };

  const confirmedLabel = (method: AuditSignature['confirmed_by']): string => {
    switch (method) {
      case 'totp':
        return t('audit.confirmed.totp', 'A code from an authenticator app');
      case 'email_code':
        return t('audit.confirmed.emailCode', 'A one-time code sent by email');
      case 'fresh_sign_in':
        return t('audit.confirmed.freshSignIn', 'Signing in again through their sign-in provider');
      default:
        return t('audit.confirmed.passkey', 'A passkey');
    }
  };

  const who = (profileId: number | null): string =>
    profileId === null
      ? t('record.person.gone', 'An account that no longer exists')
      : people[String(profileId)] ?? t('audit.person.unknown', 'Someone no longer in this workspace');

  const back = (
    <Link
      href={`/admin/document-library/${documentId}`}
      className="text-sm text-primary underline-offset-4 hover:underline"
    >
      {t('audit.back', 'Back to the document')}
    </Link>
  );

  if (state.status === 'loading') {
    return <p className="text-sm text-muted-foreground">{t('audit.loading', 'Reading the audit…')}</p>;
  }
  if (state.status === 'missing') {
    return (
      <div className="space-y-3">
        {back}
        <p className="text-sm text-muted-foreground" data-testid="audit-missing">
          {t('audit.missing', 'This document could not be found, or you cannot see it.')}
        </p>
      </div>
    );
  }
  if (state.status === 'error') {
    return (
      <div className="space-y-3">
        {back}
        <Alert variant="destructive">
          <AlertDescription>{t('audit.error', 'The audit could not be loaded. Try again.')}</AlertDescription>
        </Alert>
      </div>
    );
  }

  const { data } = state;
  const short = (hash: string | null | undefined): string => (hash ? hash.slice(0, 12) : '—');

  return (
    <div className="space-y-6" data-testid="document-audit">
      <div className="space-y-1">
        {back}
        <h1 className="text-xl font-semibold text-foreground">{t('audit.title', 'Audit trail and signatures')}</h1>
        <p className="text-sm text-muted-foreground">
          {t(
            'audit.intro',
            'Every act on this document and every electronic signature, with the checks that show nothing was changed afterwards.'
          )}
        </p>
        <p className="text-sm text-muted-foreground">
          {t(
            'audit.chain.scope',
            'Each entry is linked to the one before it, so the check shows any change to the entries the links cover.'
          )}
        </p>
      </div>

      <Alert variant={data.chain.intact ? 'success' : 'destructive'} data-testid="audit-chain" data-intact={data.chain.intact ? 'true' : 'false'}>
        <AlertDescription>
          {data.chain.intact
            ? t('audit.chain.intact', 'The trail is intact: each of its {linked} linked entries matches the one before it.', {
                linked: data.chain.linked,
              })
            : t('audit.chain.broken', 'The trail was changed after it was written, at entry {id}.', {
                id: data.chain.broken_at_event_id ?? '—',
              })}
          {data.chain.earlier > 0 &&
            ' ' +
              t('audit.chain.earlier', '{count} earlier entries were written before entries were linked; the first linked entry covers them.', {
                count: data.chain.earlier,
              })}
          {data.chain.signed_without_record.length > 0 &&
            ' ' +
              t('audit.chain.missingRecord', '{count} signing entries have no signature record.', {
                count: data.chain.signed_without_record.length,
              })}
          {data.chain.detached > 0 &&
            ' ' +
              t('audit.chain.detached', 'Some people, units or steps named in it were removed later. That is not a change to the trail.')}
        </AlertDescription>
      </Alert>

      <section className="space-y-3">
        <h2 className="text-base font-semibold text-foreground">
          {t('audit.signatures.headingWithStamps', 'Signatures and stamps')}
        </h2>
        {data.signatures.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('audit.signatures.none', 'Nobody has signed this document.')}</p>
        ) : (
          <ul className="space-y-3">
            {data.signatures.map((s) => {
              const isStamp = s.kind === 'stamp';
              const stamp = isStamp ? (s.stamp ?? null) : null;
              return (
              <li
                key={s.id}
                className="space-y-3 rounded-md border border-border p-4"
                data-testid="audit-signature"
                data-kind={isStamp ? 'stamp' : 'signature'}
              >
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-sm font-semibold text-foreground">
                    {isStamp
                      ? t('audit.stamp.appliedBy', 'Applied by {name}', { name: s.by.name })
                      : s.by.name}
                  </span>
                  <Badge variant={s.seal_valid ? 'success' : 'destructive'} data-seal={s.seal_valid ? 'valid' : 'invalid'}>
                    {s.seal_valid
                      ? t('audit.seal.valid', 'Seal checks')
                      : t('audit.seal.invalid', 'Seal does not check')}
                  </Badge>
                </div>
                {isStamp && (
                  <div className="flex flex-wrap items-center gap-4" data-testid="audit-stamp">
                    {stamp !== null && (
                      // A data URL the server stored with the record; next/image adds nothing here.
                      // eslint-disable-next-line @next/next/no-img-element
                      <img
                        src={stamp.image}
                        alt={t('stamping.imageAlt', 'The image of the stamp {name}', { name: stamp.name })}
                        className="size-24 shrink-0 rounded-md border border-border object-contain p-1"
                      />
                    )}
                    <div className="min-w-0 space-y-1 text-sm">
                      <p className="font-medium text-foreground">
                        {stamp !== null ? stamp.name : t('audit.stamp.unknown', 'A company stamp')}
                      </p>
                      {stamp !== null && (
                        <p className="text-muted-foreground">
                          {t('stamping.legalName', 'Stands for {legalName}', { legalName: stamp.legal_name })}
                        </p>
                      )}
                      <p className="text-xs text-muted-foreground">
                        {t(
                          'audit.stamp.note',
                          'An authorised person applied the image of this stamp. It is not a certified electronic seal.'
                        )}
                      </p>
                    </div>
                  </div>
                )}
                {!isStamp && s.appearance !== null && (
                  <SignatureDrawing
                    appearance={s.appearance}
                    label={t('audit.drawing', 'Drawn signature of {name}', { name: s.by.name })}
                    className="rounded-md border border-border p-2"
                  />
                )}
                <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                  {!isStamp && (
                    <>
                      <div>
                        <dt className="text-xs text-muted-foreground">{t('signing.capacity', 'Signing as')}</dt>
                        <dd>{s.capacity ?? t('signing.capacity.self', 'Yourself')}</dd>
                      </div>
                      <div>
                        <dt className="text-xs text-muted-foreground">{t('signing.onBehalfOf', 'On behalf of')}</dt>
                        <dd>{s.on_behalf_of ?? t('signing.onBehalfOf.self', 'Yourself')}</dd>
                      </div>
                    </>
                  )}
                  {!hidden && (
                    <div>
                      <dt className="text-xs text-muted-foreground">
                        {isStamp ? t('audit.stampedAt', 'Applied at') : t('audit.signedAt', 'Signed at')}
                      </dt>
                      <dd dir="ltr">{dateTime(s.at) ?? '—'}</dd>
                    </div>
                  )}
                  <div>
                    <dt className="text-xs text-muted-foreground">{t('audit.confirmedBy', 'Confirmed with')}</dt>
                    <dd>{confirmedLabel(s.confirmed_by)}</dd>
                  </div>
                  <div>
                    <dt className="text-xs text-muted-foreground">
                      {t('audit.version', 'Version {version}, content fingerprint', { version: s.subject_version })}
                    </dt>
                    <dd className="break-all font-mono text-xs" dir="ltr">{s.content_hash}</dd>
                  </div>
                  <div>
                    <dt className="text-xs text-muted-foreground">{t('audit.address', 'Address and browser')}</dt>
                    {s.details_withheld ? (
                      <dd className="text-xs text-muted-foreground">
                        {t('audit.address.withheld', 'Shown to people who can read the audit log.')}
                      </dd>
                    ) : (
                      <dd className="break-all text-xs" dir="ltr">
                        {s.ip_address ?? '—'} · {s.user_agent ?? '—'}
                      </dd>
                    )}
                  </div>
                  <div className="sm:col-span-2">
                    <dt className="text-xs text-muted-foreground">
                      {t('audit.consent', 'Consent wording, version {version}', { version: s.consent_version })}
                    </dt>
                    <dd>
                      {s.consent_version === '2026-11-1'
                        ? t(
                            'signing.consent.v20261101',
                            'I have read this document and I sign it electronically, in the capacity shown. I agree that my electronic signature, with this audit trail, records that I signed this version.'
                          )
                        : s.consent_version === '2026-11-stamp-1'
                          ? t(
                              'stamping.consent.v2026stamp1',
                              'I hold this company stamp and I am authorised to apply it to this document. I agree that this audit trail records that I applied it to this version. This is an authorised person applying the image of the stamp, not a certified electronic seal.'
                            )
                          : (s.consent_wording ?? '')}
                    </dd>
                  </div>
                  <div className="sm:col-span-2">
                    <dt className="text-xs text-muted-foreground">{t('audit.record', 'Record fingerprint and key')}</dt>
                    <dd className="break-all font-mono text-xs" dir="ltr">
                      {s.record_hash} · {s.seal_key_id}
                    </dd>
                  </div>
                </dl>
              </li>
              );
            })}
          </ul>
        )}
      </section>

      <section className="space-y-3">
        <h2 className="text-base font-semibold text-foreground">{t('audit.events.heading', 'Every act')}</h2>
        {data.events.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('audit.events.none', 'Nothing has happened on this document’s routes yet.')}</p>
        ) : (
          <ol className="divide-y divide-border rounded-md border border-border">
            {data.events.map((e) => (
              <li
                key={e.id}
                className={`flex flex-wrap items-baseline gap-x-4 gap-y-1 p-3 text-sm ${
                  data.chain.broken_at_event_id === e.id ? 'bg-error-tint' : ''
                }`}
                data-testid="audit-event"
              >
                <span className="font-medium text-foreground">{actionLabel(e.action, e.withdrawal)}</span>
                <span className="text-muted-foreground">{who(e.actor_profile_id)}</span>
                {!hidden && <span className="text-muted-foreground" dir="ltr">{dateTime(e.occurred_at) ?? '—'}</span>}
                {e.subject_version !== null && (
                  <span className="text-muted-foreground">
                    {t('audit.event.version', 'Version {version}', { version: e.subject_version })}
                  </span>
                )}
                <span className="font-mono text-xs text-muted-foreground" dir="ltr" title={e.entry_hash ?? undefined}>
                  {e.entry_hash ? short(e.entry_hash) : t('audit.event.unlinked', 'Before linking')}
                </span>
                {e.note !== null && <p className="w-full whitespace-pre-wrap text-foreground">{e.note}</p>}
              </li>
            ))}
          </ol>
        )}
      </section>

      <section className="space-y-2">
        <h2 className="text-base font-semibold text-foreground">{t('audit.keys.heading', 'Published keys')}</h2>
        <p className="text-sm text-muted-foreground">
          {t('audit.keys.intro', 'Each signature record is sealed with one of this site’s keys. Anyone can check a seal with the public key.')}
        </p>
        <ul className="space-y-1">
          {data.keys.map((k) => (
            <li key={k.key_id} className="break-all font-mono text-xs text-foreground" dir="ltr">
              {k.key_id} · {k.algorithm} · {k.public_key}
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}
