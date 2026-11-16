<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Whity\Core\Mail\EmailBranding;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailLayout;

/**
 * {@see EmailLayout} renders transactional email into the branded shell (WC-email):
 * correct HTML + text, branding applied, content HTML-escaped, unsafe CTA URLs
 * dropped.
 */
final class EmailLayoutTest extends TestCase
{
    private function branding(): EmailBranding
    {
        return new EmailBranding(
            siteName: 'Acme',
            brandColor: '#123ABC',
            supportEmail: 'help@acme.test',
            footerText: 'Acme Inc · sovereign software',
        );
    }

    public function testRendersBrandedHtmlWithCtaAndFooter(): void
    {
        $layout = new EmailLayout();
        $r = $layout->render(
            new EmailContent(
                heading: 'Welcome to Acme',
                paragraphs: ['Your workspace is ready.', 'Invite your team to get started.'],
                ctaLabel: 'Open workspace',
                ctaUrl: 'https://acme.test/dashboard',
                footnote: 'Link expires in 24 hours.',
            ),
            $this->branding(),
        );

        // HTML: branding + content + CTA.
        self::assertStringContainsString('#123ABC', $r->html);           // brand colour
        self::assertStringContainsString('>A<', $r->html);               // brand tile initial
        self::assertStringContainsString('Welcome to Acme', $r->html);
        self::assertStringContainsString('Your workspace is ready.', $r->html);
        self::assertStringContainsString('href="https://acme.test/dashboard"', $r->html);
        self::assertStringContainsString('Open workspace', $r->html);
        self::assertStringContainsString('Link expires in 24 hours.', $r->html);
        self::assertStringContainsString('Acme Inc · sovereign software', $r->html);
        self::assertStringContainsString('mailto:help@acme.test', $r->html);
        self::assertStringContainsString('Sent with Whity', $r->html);
        self::assertStringNotContainsString('font-weight:700', $r->html);

        // Text alternative conveys the same essentials.
        self::assertStringContainsString('Welcome to Acme', $r->text);
        self::assertStringContainsString('Your workspace is ready.', $r->text);
        self::assertStringContainsString('Open workspace: https://acme.test/dashboard', $r->text);
        self::assertStringContainsString('help@acme.test', $r->text);
    }

    public function testEscapesContentToPreventInjection(): void
    {
        $layout = new EmailLayout();
        $r = $layout->render(
            new EmailContent(
                heading: 'Hi <script>alert(1)</script>',
                paragraphs: ['A & B <b>bold</b>'],
            ),
            $this->branding(),
        );

        self::assertStringNotContainsString('<script>alert(1)</script>', $r->html);
        self::assertStringContainsString('&lt;script&gt;', $r->html);
        self::assertStringContainsString('A &amp; B &lt;b&gt;bold&lt;/b&gt;', $r->html);
    }

    public function testDropsUnsafeCtaUrl(): void
    {
        $layout = new EmailLayout();
        $r = $layout->render(
            new EmailContent(
                heading: 'Careful',
                ctaLabel: 'Click',
                ctaUrl: 'javascript:alert(1)',
            ),
            $this->branding(),
        );

        // The dangerous scheme must never reach the HTML, and no button is emitted.
        self::assertStringNotContainsString('javascript:', $r->html);
        self::assertStringNotContainsString('Click', $r->html);
        self::assertStringNotContainsString('Click:', $r->text);
    }

    public function testRendersCalloutWhenPresent(): void
    {
        $layout = new EmailLayout();
        $r = $layout->render(
            new EmailContent(
                heading: 'Invitation',
                callout: 'Sara invited you to Acme Team',
            ),
            $this->branding(),
        );

        self::assertStringContainsString('Sara invited you to Acme Team', $r->html);
        self::assertStringContainsString('Sara invited you to Acme Team', $r->text);
    }

    public function testBrandingFallsBackToDefaultColorInitial(): void
    {
        $b = new EmailBranding('', '#000000', '', '');
        self::assertSame('W', $b->initial());
    }

    public function testWhiteLabelLeavesOutSentWithWhity(): void
    {
        $b = new EmailBranding('Example Bakery', '#123ABC', '', '', whiteLabel: true);
        $r = (new EmailLayout())->render(new EmailContent(heading: 'Hi'), $b);

        self::assertStringNotContainsString('Sent with Whity', $r->html);
        self::assertStringNotContainsString('Sent with Whity', $r->text);
    }

    public function testArabicIsRightToLeftWithItsOwnWords(): void
    {
        $r = (new EmailLayout())->render(
            new EmailContent(heading: 'أكّد بريدك', code: '482913', language: 'ar'),
            $this->branding(),
        );

        self::assertStringContainsString('dir="rtl"', $r->html);
        self::assertStringContainsString('Noto Sans Arabic', $r->html);
        self::assertStringContainsString('أُرسلت عبر Whity', $r->html);
        // Codes stay left to right inside Arabic.
        self::assertStringContainsString('dir="ltr">482913', $r->html);
    }

    public function testDrawsRowsChecklistProposalNoteAndDangerButton(): void
    {
        $r = (new EmailLayout())->render(
            new EmailContent(
                heading: 'A new sign-in to your account',
                ctaLabel: "It wasn't me",
                ctaUrl: 'https://acme.test/settings/security',
                callout: 'Ignore this email.',
                rows: [['When', '12 Nov, 18:02'], ['Device', 'Chrome on Windows']],
                checklist: ['Invite your team'],
                proposal: ['meta' => 'Billing assistant · for Amro Saleh', 'title' => 'Send 12 reminders', 'detail' => 'JOD 49.000'],
                calloutLead: "Wasn't you?",
                ctaTone: 'danger',
                notice: "Security emails can't be turned off.",
            ),
            $this->branding(),
        );

        self::assertStringContainsString('Chrome on Windows', $r->html);
        self::assertStringContainsString('Invite your team', $r->html);
        self::assertStringContainsString('2px dashed #123ABC', $r->html);
        self::assertStringContainsString('#b42318', $r->html);
        self::assertStringContainsString('Wasn&#039;t you?', $r->html);
        self::assertStringContainsString("Security emails can't be turned off.", $r->text);
        self::assertStringContainsString('Device: Chrome on Windows', $r->text);
    }
}
