'use client';

/**
 * The brand side of the two-column sign-in page (#1734), and the way in to
 * "Verify a document" for someone holding a paper and no account.
 *
 * - Whity's own console: the "sheets on paper" pattern (docs/design/10-patterns.md).
 *   One sheet carries the message; the plane sits under the one thing that
 *   matters here, the verify action. Never Blueprint: that pattern is for
 *   technical content, not a sign-in page.
 * - A white-labelled workspace: its own logo and colour, and no Whity pattern.
 *
 * On phones only the verify action shows, under the form.
 */

import { useState } from 'react';
import { IconQrcode } from '@tabler/icons-react';
import { Button } from '@whity/ui/button';
import { TenantLogo } from '@whity/ui/tenant-logo';
import { PaperEyebrow, PaperGround, PaperHeadline, PaperSheet } from '@whity/ui/paper-sheet';
import { useTranslation } from '@whity/features/i18n';
import { useBranding } from '@/lib/branding-context';
import { isWhiteLabel } from '@/components/auth-shell';
import { VerifyDocumentDialog } from '@/components/verify-document-dialog';

export function SignInBrandPanel() {
  const branding = useBranding();
  const t = useTranslation('auth');
  const [verifyOpen, setVerifyOpen] = useState(false);
  const site = branding.siteName;
  const verifyLabel = t('login.panel.verify', 'Verify a document');
  const verifyHint = t('login.panel.verify.hint', 'Scan the QR code on a printed page. No account needed.');

  const dialog = <VerifyDocumentDialog open={verifyOpen} onOpenChange={setVerifyOpen} siteName={site} />;

  if (isWhiteLabel(branding)) {
    return (
      <aside
        data-testid="sign-in-brand-panel"
        className="flex flex-col justify-center px-4 pb-10 sm:px-10 lg:bg-primary lg:px-16 lg:py-16 lg:text-primary-foreground"
      >
        <div className="mx-auto w-full max-w-sm space-y-6 lg:max-w-md">
          <div className="hidden space-y-5 lg:block">
            <span className="inline-flex rounded-dialog bg-card p-3">
              <TenantLogo
                name={site}
                src={branding.logoSquareUrl ?? branding.logoWideUrl}
                shape={!branding.logoSquareUrl && branding.logoWideUrl ? 'wide' : 'square'}
                showName={false}
                size={48}
              />
            </span>
            <h2 className="text-3xl leading-tight font-semibold text-balance">{site}</h2>
            <p className="text-lg opacity-90">
              {t(
                'login.panel.partner.body',
                'Documents from {site} carry a QR code. Scan it to check that a paper is genuine.',
                { site }
              )}
            </p>
          </div>
          <Button
            type="button"
            variant="outline"
            size="lg"
            className="w-full lg:w-auto"
            data-testid="verify-document-open"
            onClick={() => setVerifyOpen(true)}
          >
            <IconQrcode aria-hidden />
            {verifyLabel}
          </Button>
        </div>
        {dialog}
      </aside>
    );
  }

  return (
    <PaperGround
      data-testid="sign-in-brand-panel"
      className="flex flex-col justify-center px-4 pb-10 sm:px-10 lg:px-16 lg:py-16"
    >
      <aside className="mx-auto w-full max-w-sm lg:max-w-md">
        {/* TplSignInTwoColumn (WHIT-1044): one sheet with one message, resting on
            the plane because its one action, Verify a document, is what matters
            here. The plane falls down-left in both directions (4.3), so the room
            for it is on the physical left, also in RTL. */}
        <PaperSheet plane className="hidden space-y-4 p-7 lg:ml-3 lg:block">
          <PaperEyebrow>{t('login.panel.eyebrowPrinted', 'Holding a printed page?')}</PaperEyebrow>
          <PaperHeadline>{t('login.panel.headlineGenuine', 'Check it’s genuine.')}</PaperHeadline>
          <p className="text-muted-foreground">
            {t('login.panel.bodyFoot', 'Scan the QR code at the foot of the page. No account needed.')}
          </p>
          <Button type="button" data-testid="verify-document-open" onClick={() => setVerifyOpen(true)}>
            <IconQrcode aria-hidden />
            {verifyLabel}
          </Button>
        </PaperSheet>
        {/* Under 1024px only the form shows, with Verify a document as a line under it. */}
        <p className="text-center lg:hidden">
          <Button
            type="button"
            variant="link"
            data-testid="verify-document-open-phone"
            onClick={() => setVerifyOpen(true)}
          >
            <IconQrcode aria-hidden />
            {verifyLabel}
          </Button>
          <span className="block text-sm text-muted-foreground">{verifyHint}</span>
        </p>
      </aside>
      {dialog}
    </PaperGround>
  );
}
