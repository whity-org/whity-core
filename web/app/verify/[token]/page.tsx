'use client';

/**
 * The PUBLIC document verification page (#1036) — what a QR code opens.
 *
 * Lives OUTSIDE the `(protected)` and `(editor)` route groups, so it renders
 * with no session, which is the point: the person reading it is a courier, a
 * ministry clerk or a citizen holding a printed decision, and the paper is the
 * whole of their relationship with this system. It must never depend on auth,
 * on a tenant, or on any app chrome that does.
 *
 * WHY THE URL IS THIS AND NOT THE API PATH. The QR payload is what a phone
 * camera opens in a browser, so it has to be a human page. `/api/v1/...` would
 * hand a courier raw JSON. The API path this page fetches is versioned, once,
 * here — the version-prefix trap that broke every document viewer for a release
 * (`content_url` emitted `/api/documents/...` while the router served
 * `/api/v1/documents/...`) is a trap for EMITTED paths, and this one is a
 * literal in a client rather than a path built server-side.
 *
 * ARABIC / RTL IS A HARD REQUIREMENT HERE, harder than anywhere else in the app:
 * this is the surface most likely to be read by somebody outside the
 * organisation, and for an Arabic-speaking institution that is most readers.
 * The page is DIRECTION-AGNOSTIC by construction — no physical margins, no
 * `text-left`, no `ml-`/`pr-` anywhere — so it mirrors entirely from `<html dir>`,
 * which `DirectionProvider` in the root layout sets from the chosen language.
 * The one exception is the reference code, which is pinned `dir="ltr"`: it is a
 * Latin-alphanumeric group that bidi reordering would otherwise scramble inside
 * an Arabic sentence, and a verification reference that reads differently from
 * the paper is worse than no reference.
 *
 * WHAT THIS PAGE CANNOT SHOW, because the endpoint cannot return it: the
 * document id, its title, its content, its recipients, or any person's or
 * unit's name. The "open the record" button appears only when a SIGNED-IN
 * caller asks a second, ordinary, RBAC-gated endpoint and it answers — so an
 * anonymous reader never learns the record exists beyond what is on this page,
 * and a signed-in reader without reach gets exactly the same page an anonymous
 * one does.
 */

import { use, useEffect, useId, useState } from 'react';
import Link from 'next/link';
import { Button } from '@whity/ui/button';
import { Icon, type IconName } from '@whity/ui/icon';
import { PublicShell } from '@/components/auth/public-shell';
import { OutcomeIcon, type OutcomeTone } from '@/components/auth/public-outcome';
import type { WorkspaceBrand } from '@/lib/branding-context';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';
import { DateDisplayProvider, useDateDisplay } from '@whity/features/datetime';
import { api } from '@/lib/api/client';

/** The wire shape of `GET /api/v1/document-verifications/{token}`. */
type Verification = {
  verified: boolean;
  reason?: string;
  revoked_on?: string | null;
  reference?: string;
  issuer?: string;
  issued_on?: string | null;
  stage?: string;
  stage_on?: string | null;
  /** The `described` level (#1627): the template's public name, never its title. */
  kind?: string;
  revision?: string;
  pages?: number;
  /** A refused code whose document was revised, at `described` only. */
  replaced_by?: { revision: string; issued_on: string | null };
  /** Only beside a code that verified: the issuer's logo and colour (WHIT-1044). */
  brand?: WorkspaceBrand | null;
  /** The `signed` level (#1730): each signature and stamp, as a capacity with a date. */
  signatures?: PublicSignature[];
  /** The `signed` level (#1730): false when the trail's hash chain no longer holds. */
  trail_intact?: boolean;
};

/**
 * One signature or company stamp at the `signed` level (#1730). `capacity` is
 * the role signed as, or for a stamp the legal name it stands for. `name` is
 * there only when the workspace chose to show who signed.
 */
type PublicSignature = {
  kind: 'signature' | 'stamp';
  capacity: string | null;
  on_behalf_of: string | null;
  version: number;
  signed_on: string | null;
  name?: string;
};

/** What `GET /api/v1/document-verifications/{token}/files/{sha256}` answers (#1730). */
type FileMatch = 'current' | 'earlier' | 'none';

/**
 * The routing verbs the `stage` disclosure level can return.
 *
 * @i18n-keys documents
 *   verify.stage.issued = Issued
 *   verify.stage.forwarded = Forwarded on
 *   verify.stage.acknowledged = Acknowledged
 *   verify.stage.returned = Returned
 *   verify.stage.noted = Noted
 *   verify.stage.sent_back = Sent back
 *   verify.stage.resubmitted = Resubmitted
 *   verify.stage.signed = Signed
 *   verify.stage.stamped = Stamped
 *   verify.stage.declined = Declined
 */
const STAGE_LABELS: Record<string, { key: string; text: string }> = {
  issued: { key: 'verify.stage.issued', text: 'Issued' },
  forwarded: { key: 'verify.stage.forwarded', text: 'Forwarded on' },
  acknowledged: { key: 'verify.stage.acknowledged', text: 'Acknowledged' },
  returned: { key: 'verify.stage.returned', text: 'Returned' },
  noted: { key: 'verify.stage.noted', text: 'Noted' },
  sent_back: { key: 'verify.stage.sent_back', text: 'Sent back' },
  resubmitted: { key: 'verify.stage.resubmitted', text: 'Resubmitted' },
  signed: { key: 'verify.stage.signed', text: 'Signed' },
  stamped: { key: 'verify.stage.stamped', text: 'Stamped' },
  declined: { key: 'verify.stage.declined', text: 'Declined' },
};

/**
 * The sentence for each way a code can fail.
 *
 * `unrecognised` is what the server says by default for an unknown code, a
 * malformed one, a withdrawn one and a superseded one alike — so the copy must
 * not imply the reader mistyped something, and must not imply a document exists.
 * The other two appear only when the tenant chose to disclose them.
 *
 * @i18n-keys documents
 *   verify.refused.unrecognised = We can’t find this document
 *   verify.refused.unrecognisedBody = This code doesn’t match a document we can confirm. Check you scanned the code on the document, not one printed beside it.
 *   verify.refused.withdrawn = This code has been withdrawn
 *   verify.refused.withdrawnBody = The organisation has stopped standing behind the copy this code was printed on.
 *   verify.refused.superseded = A newer version exists
 *   verify.refused.supersededBody = This paper was genuine when it was issued, then replaced. Ask for the newer one.
 *   verify.refused.document_withdrawn = This document has been withdrawn
 *   verify.refused.document_withdrawnBody = The organisation that issued this document has withdrawn it and no longer stands behind it.
 */
const REFUSAL_COPY: Record<string, { titleKey: string; title: string; bodyKey: string; body: string }> = {
  unrecognised: {
    titleKey: 'verify.refused.unrecognised',
    title: 'We can’t find this document',
    bodyKey: 'verify.refused.unrecognisedBody',
    body:
      'This code doesn’t match a document we can confirm. ' +
      'Check you scanned the code on the document, not one printed beside it.',
  },
  withdrawn: {
    titleKey: 'verify.refused.withdrawn',
    title: 'This code has been withdrawn',
    bodyKey: 'verify.refused.withdrawnBody',
    body: 'The organisation has stopped standing behind the copy this code was printed on.',
  },
  document_withdrawn: {
    titleKey: 'verify.refused.document_withdrawn',
    title: 'This document has been withdrawn',
    bodyKey: 'verify.refused.document_withdrawnBody',
    body: 'The organisation that issued this document has withdrawn it and no longer stands behind it.',
  },
  superseded: {
    titleKey: 'verify.refused.superseded',
    title: 'A newer version exists',
    bodyKey: 'verify.refused.supersededBody',
    body: 'This paper was genuine when it was issued, then replaced. Ask for the newer one.',
  },
};

type Phase =
  | { kind: 'loading' }
  | { kind: 'ready'; data: Verification }
  | { kind: 'throttled' }
  | { kind: 'error' };

export default function VerifyDocumentPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = use(params);
  const t = useTranslation('documents');

  const [phase, setPhase] = useState<Phase>({ kind: 'loading' });
  // The signed-in follow-through. `null` means "no record reachable by this
  // reader", which is both the anonymous case and the signed-in-without-reach
  // case — deliberately the same state, so the page renders identically for
  // both and cannot be used to tell them apart.
  const [recordId, setRecordId] = useState<number | null>(null);
  // Told only to a reader who may open the record. The public answer above stays
  // what the organisation chose to disclose to everybody.
  const [recordWithdrawn, setRecordWithdrawn] = useState(false);

  useEffect(() => {
    let cancelled = false;

    // No credentials: this request must behave identically for a signed-in
    // reader and a stranger, and sending a cookie would make the response
    // depend on who is asking on a surface whose whole design is that it does
    // not.
    api.GET('/api/v1/document-verifications/{token}', {
      params: { path: { token } },
      credentials: 'omit',
    })
      .then(({ data: verification, response }) => {
        if (cancelled) return;
        if (response.status === 429) {
          setPhase({ kind: 'throttled' });
          return;
        }
        if (!response.ok) {
          setPhase({ kind: 'error' });
          return;
        }
        const body = (verification ?? {}) as unknown as { data?: Verification };
        setPhase(body.data ? { kind: 'ready', data: body.data } : { kind: 'error' });
      })
      .catch(() => {
        if (!cancelled) setPhase({ kind: 'error' });
      });

    return () => {
      cancelled = true;
    };
  }, [token]);

  useEffect(() => {
    let cancelled = false;

    // The SECOND request, and the only one that carries a session. It is an
    // ordinary RBAC-gated route: it answers 404 for a reader without reach and
    // 401/403 for one with no session at all, and every one of those outcomes
    // lands in the same silent branch. Failing quietly is correct here — the
    // absence of the button is not an error, it is the answer.
    api.GET('/api/v1/documents/by-verification/{token}', { params: { path: { token } } })
      .then(({ data: record, response }) => {
        if (cancelled || !response.ok) return;
        const body = (record ?? {}) as unknown as { data?: { id?: number; document_withdrawn?: boolean } };
        if (typeof body.data?.id === 'number') setRecordId(body.data.id);
        setRecordWithdrawn(body.data?.document_withdrawn === true);
      })
      .catch(() => {
        /* no record for this reader; the button simply does not appear */
      });

    return () => {
      cancelled = true;
    };
  }, [token]);

  const look = outcomeLook(phase);
  // The issuer's brand comes only beside a code that verified (WHIT-1044); every
  // other answer wears the host's, so it says nothing about whose code it was.
  const brand = phase.kind === 'ready' && phase.data.verified ? phase.data.brand ?? null : null;

  return (
    <PublicShell brand={brand}>
      <section
        className="grid gap-4 rounded-card border border-border bg-card p-6 text-card-foreground"
        aria-busy={phase.kind === 'loading' || undefined}
      >
        <p className="text-sm text-muted-foreground" dir="auto">
          {t('verify.eyebrow', 'Document verification')}
        </p>
        <OutcomeIcon name={look.icon} tone={look.tone} />
        <div className="grid gap-2">
          <h1 className="text-xl font-semibold text-foreground" data-testid="verify-title" dir="auto">
            {headline(t, phase)}
          </h1>
          <p className="text-muted-foreground" data-testid="verify-body" dir="auto">
            {body(t, phase)}
          </p>
        </div>

        {phase.kind === 'ready' && phase.data.verified && (
          <dl className="grid gap-2 text-sm">
            {/* The `described` level (#1627): enough to compare with the paper. */}
            <Fact label={t('verify.field.kind', 'Document')} value={phase.data.kind} />
            <Fact label={t('verify.field.issuer', 'Issued by')} value={phase.data.issuer} />
            {/*
              @date-display-ignore: this page is NOT governed by
              `ui.hide_dates`, and #1068 says so in as many words. That
              setting is about what a tenant's own STAFF see on their screens;
              the audience here is a stranger holding a printed sheet, for
              whom "issued on" is doing real verification work that no staff
              timestamp is doing. A verification page with no date is a
              substantially weaker one.

              Whether to show it stays this page's own decision, on its own
              key: `documents.qr_public_detail`, whose `undated` level (added
              by #1068 for exactly this) withholds `issued_on` server-side.
              The value is ABSENT from the payload then, so `Fact` renders
              nothing, and no client-side gate is involved at all.

              It is rendered verbatim because the SERVER already reduced it to
              a date (`VerificationPresenter::dateOnly`) and deliberately did
              not send a time. Re-parsing and re-localising it here would
              reintroduce a time-of-day this endpoint exists not to disclose.
            */}
            <Fact label={t('verify.field.issuedOn', 'Issued on')} value={phase.data.issued_on} />
            <Fact
              label={t('verify.field.reference', 'Reference')}
              value={phase.data.reference}
              /* Latin-alphanumeric; bidi reordering would scramble it. */
              monospaceLtr
            />
            <Fact label={t('verify.field.revision', 'Revision')} value={phase.data.revision} monospaceLtr />
            <Fact
              label={t('verify.field.pages', 'Pages')}
              value={phase.data.pages !== undefined ? String(phase.data.pages) : undefined}
              monospaceLtr
            />
            {phase.data.stage !== undefined && (
              <Fact
                label={t('verify.field.stage', 'Current stage')}
                value={stageLabel(t, phase.data.stage)}
              />
            )}
          </dl>
        )}

        {/* The `signed` level (#1730): told calmly, but before anything it would undermine. */}
        {phase.kind === 'ready' && phase.data.verified && phase.data.trail_intact === false && (
          <div
            className="flex gap-3 rounded-card border border-warning/40 bg-warning-tint px-4 py-3 text-sm text-warning-text"
            role="status"
            data-testid="verify-trail-changed"
          >
            <Icon name="alert" className="mt-0.5 shrink-0" />
            <p dir="auto">
              {phase.data.issuer
                ? t(
                    'verify.trailChanged',
                    'The record of how this was signed has been changed since. Check with {org} before relying on it.',
                    { org: phase.data.issuer },
                  )
                : t(
                    'verify.trailChangedNoIssuer',
                    'The record of how this was signed has been changed since. Check with the organisation that issued it before relying on it.',
                  )}
            </p>
          </div>
        )}

        {phase.kind === 'ready' && phase.data.verified && phase.data.signatures && phase.data.signatures.length > 0 && (
          <DateDisplayProvider hidden={false}>
            <SignatureList t={t} signatures={phase.data.signatures} />
          </DateDisplayProvider>
        )}

        {phase.kind === 'ready' && phase.data.verified && (
          <FileCheck t={t} token={token} issuer={phase.data.issuer} />
        )}

        {/* @date-display-ignore: same as `issued_on` directly above — this is
            the public verification surface, governed by
            `documents.qr_public_detail` and not by `ui.hide_dates`, and the
            server has already reduced the value to a date. */}
        {phase.kind === 'ready' && !phase.data.verified && phase.data.revoked_on && (
          <dl className="grid gap-2 text-sm">
            <Fact label={t('verify.field.withdrawnOn', 'Withdrawn on')} value={phase.data.revoked_on} />
          </dl>
        )}

        {/* Shown only at `described` (#1627). */}
        {phase.kind === 'ready' && !phase.data.verified && phase.data.replaced_by && (
          <div className="grid gap-3" data-testid="verify-replaced-by">
            <p className="text-sm" dir="auto">
              {t(
                'verify.replacedByBody',
                'Revision {revision} replaced it. Ask the organisation for that one.',
                { revision: phase.data.replaced_by.revision }
              )}
            </p>
            <dl className="grid gap-2 text-sm">
              <Fact
                label={t('verify.field.replacedBy', 'Replaced by revision')}
                value={phase.data.replaced_by.revision}
                monospaceLtr
              />
              {/* @date-display-ignore: the public verification surface, governed by
                  `documents.qr_public_detail` and not by `ui.hide_dates`, as above; the
                  server has already reduced the value to a date. */}
              <Fact label={t('verify.field.replacedOn', 'Replaced on')} value={phase.data.replaced_by.issued_on} />
            </dl>
          </div>
        )}

        {recordId !== null &&
          recordWithdrawn &&
          phase.kind === 'ready' &&
          phase.data.reason !== 'document_withdrawn' && (
          <p className="text-sm" data-testid="verify-record-withdrawn" dir="auto">
            {t('verify.recordWithdrawn', 'You can open this document, so you can also be told it has been withdrawn.')}
          </p>
        )}

        {recordId !== null && (
          <Button asChild variant="outline" className="w-full" data-testid="verify-open-record">
            <Link href={`/admin/document-library/${recordId}`}>
              {t('verify.openRecord', 'Open the full record')}
            </Link>
          </Button>
        )}

        {phase.kind === 'ready' && phase.data.verified && phase.data.issuer ? (
          // What a confirmation proves, and what it doesn't (the card's info box).
          <div className="flex gap-3 rounded-card bg-muted px-4 py-3 text-sm" data-testid="verify-proves">
            <Icon name="info" className="mt-0.5 text-muted-foreground" />
            <div className="grid gap-0.5">
              <p className="font-medium text-foreground" dir="auto">
                {t('verify.proves.title', 'What this proves')}
              </p>
              <p className="text-foreground" dir="auto">
                {footer(t, phase)}
              </p>
            </div>
          </div>
        ) : (
          <p className="text-center text-sm text-muted-foreground" dir="auto">
            {footer(t, phase)}
          </p>
        )}
      </section>
    </PublicShell>
  );
}

/**
 * One icon per answer (TplVerifyDocument note 2): genuine is the green seal,
 * a newer version amber, withdrawn and not found grey, never red, so a reader
 * isn't alarmed by a mistyped code. Checking is dashed: not yet.
 */
function outcomeLook(phase: Phase): { icon: IconName; tone: OutcomeTone } {
  if (phase.kind === 'loading') return { icon: 'clock', tone: 'dash' };
  if (phase.kind === 'throttled') return { icon: 'clock', tone: 'warn' };
  if (phase.kind === 'error') return { icon: 'offline', tone: 'warn' };
  if (phase.data.verified) return { icon: 'success', tone: 'ok' };
  const reason = phase.data.reason ?? 'unrecognised';
  if (reason === 'superseded' || phase.data.replaced_by) return { icon: 'sync', tone: 'warn' };
  if (reason === 'withdrawn' || reason === 'document_withdrawn') return { icon: 'ban', tone: 'muted' };
  return { icon: 'search', tone: 'muted' };
}

/**
 * One label/value pair, direction-agnostic.
 *
 * `text-start` rather than left/right so the whole row mirrors from
 * `<html dir>` with no per-language branch. Absent values render nothing at all
 * rather than an empty row — a blank beside "Issued by" reads as a broken page
 * on the one surface that has to look trustworthy.
 */
function Fact({
  label,
  value,
  monospaceLtr = false,
}: {
  label: string;
  value?: string | null;
  monospaceLtr?: boolean;
}) {
  if (value === undefined || value === null || value === '') return null;

  return (
    <div className="grid grid-cols-[minmax(6rem,auto)_1fr] items-baseline gap-4">
      <dt className="text-muted-foreground text-start" dir="auto">{label}</dt>
      <dd
        className={`text-foreground text-start ${monospaceLtr ? 'font-mono' : ''}`}
        dir={monospaceLtr ? 'ltr' : 'auto'}
      >
        {value}
      </dd>
    </div>
  );
}

function headline(t: TranslateFn, phase: Phase): string {
  if (phase.kind === 'loading') return t('verify.checking', 'Checking this document…');
  if (phase.kind === 'throttled') return t('verify.throttled', 'Too many checks');
  if (phase.kind === 'error') return t('verify.error', 'Verification is unavailable');
  if (phase.data.verified) return t('verify.genuine', 'This document is genuine');

  const copy = REFUSAL_COPY[phase.data.reason ?? 'unrecognised'] ?? REFUSAL_COPY.unrecognised;

  return t(copy.titleKey, copy.title);
}

/**
 * The small print, and the reason it is not one sentence.
 *
 * A browser pass caught this saying "This page confirms only that Whity issued
 * a document" on a page that had just REFUSED a code — asserting a confirmation
 * that had not happened, under the platform's own brand name rather than the
 * issuing organisation's. Both halves were wrong and neither could fail a test:
 * the string rendered perfectly, on top of an answer it contradicted.
 *
 * So the confirming sentence exists only where something was confirmed, and it
 * names the ISSUER from the payload — the organisation this page is vouching
 * for — never `branding.siteName`, which on an unauthenticated page is the
 * platform's name and is not who issued anything.
 */
function footer(t: TranslateFn, phase: Phase): string {
  if (phase.kind === 'ready' && phase.data.verified && phase.data.issuer) {
    // #1068: "on the date shown" has to go when there is no date shown. A
    // tenant on the `undated` disclosure level gets no `issued_on` at all, and
    // a page whose own sentence points at a row that is not there reads as
    // broken — on the one surface in the product that has to look trustworthy
    // to somebody who has never seen it before.
    if (phase.data.issued_on === undefined || phase.data.issued_on === null) {
      return t(
        'verify.footerVerifiedUndated',
        'This page confirms only that {org} issued this document. It does not show the document or its contents.',
        { org: phase.data.issuer },
      );
    }

    return t(
      'verify.footerVerified',
      'This page confirms only that {org} issued a document on the date shown. It does not show the document or its contents.',
      { org: phase.data.issuer },
    );
  }

  return t(
    'verify.footerGeneric',
    'This page checks codes printed on documents issued through this service. It never shows a document or its contents.',
  );
}

function body(t: TranslateFn, phase: Phase): string {
  if (phase.kind === 'loading') return '';
  if (phase.kind === 'throttled') {
    return t('verify.throttledBody', 'Please wait a little while and scan again.');
  }
  if (phase.kind === 'error') {
    return t('verify.errorBody', 'We could not check this code just now. Please try again shortly.');
  }
  if (phase.data.verified) {
    // #1068, same reason as the footer: at the `undated` level there is no date
    // row to point at.
    if (phase.data.issued_on === undefined || phase.data.issued_on === null) {
      return t(
        'verify.genuineBodyUndated',
        'It was issued by the organisation named below. Compare it with the paper in your hand.',
      );
    }

    return t(
      'verify.genuineBody',
      'It was issued by the organisation named below, on the date shown. Compare it with the paper in your hand.'
    );
  }

  const copy = REFUSAL_COPY[phase.data.reason ?? 'unrecognised'] ?? REFUSAL_COPY.unrecognised;

  return t(copy.bodyKey, copy.body);
}

/**
 * An UNKNOWN verb renders as itself rather than as an empty string.
 *
 * The server constrains `stage` to a closed vocabulary, so this branch should be
 * unreachable — but a client that silently blanks a value it does not recognise
 * is a client that hides the day the vocabulary grows.
 */
function stageLabel(t: TranslateFn, stage: string): string {
  const label = STAGE_LABELS[stage];

  return label ? t(label.key, label.text) : stage;
}

/**
 * The signatures and company stamps at the `signed` level (#1730): each as the
 * capacity it was made in and the day, with the person's name only when the
 * workspace chose to show it.
 *
 * DATES. Mounted inside `DateDisplayProvider hidden={false}`: like `issued_on`
 * above, this page is not governed by `ui.hide_dates` (#1068), which is about a
 * workspace's own staff screens. The signing day is part of what a holder
 * compares with the paper. It still goes through the one sanctioned formatter,
 * so it reads "21 Nov 2026" with Western digits like the rest of the product.
 *
 * The version is shown only when the signatures were made on different
 * versions, where it tells the reader something; on a document signed once it
 * would be noise.
 */
function SignatureList({ t, signatures }: { t: TranslateFn; signatures: PublicSignature[] }) {
  const dates = useDateDisplay();
  const versionsDiffer = new Set(signatures.map((s) => s.version)).size > 1;

  return (
    <div className="grid gap-2" data-testid="verify-signatures">
      <h2 className="text-sm font-medium text-foreground" dir="auto">
        {t('verify.signatures.title', 'Signatures and stamps')}
      </h2>
      <ul className="grid gap-2">
        {signatures.map((signature, index) => {
          const day = dates.date(signature.signed_on);

          return (
            <li
              key={index}
              className="flex gap-3 rounded-card border border-border px-4 py-3 text-sm"
              data-testid="verify-signature"
            >
              <Icon
                name={signature.kind === 'stamp' ? 'evidence' : 'signature'}
                className="mt-0.5 shrink-0 text-muted-foreground"
              />
              <div className="grid gap-0.5">
                {signature.name ? (
                  <p className="font-medium text-foreground" dir="auto" data-testid="verify-signature-name">
                    {signature.name}
                  </p>
                ) : null}
                <p className="text-foreground" dir="auto">
                  {signatureLine(t, signature)}
                  {day !== null ? <span className="text-muted-foreground">{` · ${day}`}</span> : null}
                </p>
                {versionsDiffer ? (
                  <p className="text-muted-foreground" dir="auto">
                    {t('verify.signatures.version', 'On version {version}', { version: String(signature.version) })}
                  </p>
                ) : null}
              </div>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

/** "Signed as Director, for Example Bakery" / "Company stamp of Example Bakery LLC". */
function signatureLine(t: TranslateFn, signature: PublicSignature): string {
  if (signature.kind === 'stamp') {
    return signature.capacity
      ? t('verify.signatures.stampOf', 'Company stamp of {org}', { org: signature.capacity })
      : t('verify.signatures.stamp', 'Company stamp');
  }
  if (signature.capacity && signature.on_behalf_of) {
    return t('verify.signatures.asFor', 'Signed as {capacity}, for {org}', {
      capacity: signature.capacity,
      org: signature.on_behalf_of,
    });
  }
  if (signature.capacity) {
    return t('verify.signatures.as', 'Signed as {capacity}', { capacity: signature.capacity });
  }
  if (signature.on_behalf_of) {
    return t('verify.signatures.for', 'Signed for {org}', { org: signature.on_behalf_of });
  }

  return t('verify.signatures.signed', 'Signed');
}

type FileCheckState =
  | { kind: 'idle' }
  | { kind: 'checking'; fileName: string }
  | { kind: 'answered'; fileName: string; match: FileMatch }
  | { kind: 'throttled' }
  | { kind: 'unsupported' }
  | { kind: 'failed' };

/**
 * "Check a file" (#1730): is the PDF in the reader's hands the one the issuer
 * stored?
 *
 * The browser hashes the file and sends ONLY the SHA-256. The file itself never
 * leaves the device, and the page says so, because the person holding it may
 * well be holding something private. Offered only beside a code that verified:
 * against a code that did not, every file would answer "none", which says
 * nothing.
 *
 * The drop area follows the `Input` dropzone's look in @whity/ui (dashed
 * `border-input`, the round primary-tint icon) but is its own small element: the
 * shared one keeps a list of picked files with an English remove label, and
 * nothing here is kept or removed.
 */
function FileCheck({ t, token, issuer }: { t: TranslateFn; token: string; issuer?: string }) {
  const inputId = useId();
  const [state, setState] = useState<FileCheckState>({ kind: 'idle' });
  const [dragging, setDragging] = useState(false);

  const check = async (file: File) => {
    const subtle = typeof crypto !== 'undefined' ? crypto.subtle : undefined;
    if (!subtle) {
      setState({ kind: 'unsupported' });
      return;
    }
    setState({ kind: 'checking', fileName: file.name });
    try {
      const digest = await subtle.digest('SHA-256', await readBytes(file));
      const sha256 = Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, '0')).join('');
      const { data, response } = await api.GET('/api/v1/document-verifications/{token}/files/{sha256}', {
        params: { path: { token, sha256 } },
        credentials: 'omit',
      });
      if (response.status === 429) {
        setState({ kind: 'throttled' });
        return;
      }
      const match = (data as { data?: { match?: string } } | undefined)?.data?.match;
      if (!response.ok || (match !== 'current' && match !== 'earlier' && match !== 'none')) {
        setState({ kind: 'failed' });
        return;
      }
      setState({ kind: 'answered', fileName: file.name, match });
    } catch {
      setState({ kind: 'failed' });
    }
  };

  const pick = (files: FileList | null) => {
    const file = files?.[0];
    if (file) void check(file);
  };

  return (
    <div className="grid gap-3" data-testid="verify-file-check">
      <div className="grid gap-0.5">
        <h2 className="text-sm font-medium text-foreground" dir="auto">
          {t('verify.fileCheck.title', 'Check a file')}
        </h2>
        <p className="text-sm text-muted-foreground" dir="auto">
          {t(
            'verify.fileCheck.privacy',
            'Choose the PDF you were given. It is checked on this device and never uploaded.',
          )}
        </p>
      </div>
      <label
        htmlFor={inputId}
        onDragOver={(e) => {
          e.preventDefault();
          setDragging(true);
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={(e) => {
          e.preventDefault();
          setDragging(false);
          pick(e.dataTransfer.files);
        }}
        className={`flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-5 text-center transition-colors hover:border-primary/60 hover:bg-muted/30 focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/30 ${
          dragging ? 'border-primary bg-primary/10' : 'border-input bg-card/60'
        }`}
        data-testid="verify-file-drop"
      >
        <span className="flex size-10 items-center justify-center rounded-full bg-primary/10 text-primary" aria-hidden="true">
          <Icon name="upload" />
        </span>
        <span className="text-sm font-medium text-foreground" dir="auto">
          {t('verify.fileCheck.prompt', 'Choose a PDF, or drop it here')}
        </span>
        <input
          id={inputId}
          type="file"
          accept="application/pdf,.pdf"
          className="sr-only"
          data-testid="verify-file-input"
          onChange={(e) => {
            pick(e.target.files);
            e.target.value = '';
          }}
        />
      </label>
      <FileCheckAnswer t={t} state={state} issuer={issuer} />
    </div>
  );
}

function FileCheckAnswer({ t, state, issuer }: { t: TranslateFn; state: FileCheckState; issuer?: string }) {
  if (state.kind === 'idle') return null;

  if (state.kind === 'checking') {
    return (
      <p className="text-sm text-muted-foreground" role="status" dir="auto" data-testid="verify-file-answer">
        {t('verify.fileCheck.checking', 'Checking {file}…', { file: state.fileName })}
      </p>
    );
  }

  const answered = state.kind === 'answered';
  const current = answered && state.match === 'current';
  const tone = current
    ? 'bg-success-tint text-success-text'
    : answered
      ? 'bg-warning-tint text-warning-text'
      : 'bg-muted text-foreground';
  const icon: IconName = current ? 'success' : answered ? 'alert' : 'info';

  return (
    <div
      className={`flex gap-3 rounded-card px-4 py-3 text-sm ${tone}`}
      role="status"
      data-testid="verify-file-answer"
      data-match={answered ? state.match : undefined}
    >
      <Icon name={icon} className="mt-0.5 shrink-0" />
      <div className="grid gap-0.5">
        {answered && (
          <p className="font-medium" dir="auto">
            {state.fileName}
          </p>
        )}
        <p dir="auto">{fileAnswer(t, state, issuer)}</p>
      </div>
    </div>
  );
}

function fileAnswer(t: TranslateFn, state: FileCheckState, issuer?: string): string {
  if (state.kind === 'answered') {
    if (state.match === 'current') {
      return issuer
        ? t('verify.fileCheck.current', 'This file is the one {org} issued.', { org: issuer })
        : t('verify.fileCheck.currentNoIssuer', 'This file is the one that was issued.');
    }
    if (state.match === 'earlier') {
      return t(
        'verify.fileCheck.earlier',
        'This file is an earlier version of this document. Ask for the current one.',
      );
    }

    return t(
      'verify.fileCheck.none',
      'This file doesn’t match. It may have been changed, or it may be a different document.',
    );
  }
  if (state.kind === 'throttled') {
    return t('verify.fileCheck.throttled', 'Too many checks. Please wait a little while and try again.');
  }
  if (state.kind === 'unsupported') {
    return t('verify.fileCheck.unsupported', 'This browser can’t check files. Try an up-to-date browser.');
  }

  return t('verify.fileCheck.failed', 'We couldn’t check the file just now. Please try again shortly.');
}

/** A file's bytes: `Blob.arrayBuffer` where the browser has it, a FileReader where it doesn't. */
function readBytes(file: Blob): Promise<ArrayBuffer> {
  if (typeof file.arrayBuffer === 'function') return file.arrayBuffer();

  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result as ArrayBuffer);
    reader.onerror = () => reject(reader.error);
    reader.readAsArrayBuffer(file);
  });
}
