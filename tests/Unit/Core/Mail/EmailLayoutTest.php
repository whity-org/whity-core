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
        // EM.1: "Sent with Whity" closes the footer (the copyright line is gone).
        self::assertStringContainsString('Sent with Whity', $r->html);
        self::assertStringNotContainsString('&copy;', $r->html);

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

    /** EM.1: 17 px body, a 52 px main button, nothing under 13 px, no weight 700. */
    public function testFollowsTheEm1Scale(): void
    {
        $r = (new EmailLayout())->render(
            new EmailContent(
                heading: 'Welcome',
                paragraphs: ['Body text.'],
                ctaLabel: 'Open',
                ctaUrl: 'https://acme.test',
                footnote: 'Small print.',
            ),
            $this->branding(),
        );

        self::assertStringContainsString('font-size:17px;line-height:1.6', $r->html);
        self::assertStringContainsString('padding:15px 30px;font-size:17px;font-weight:600;line-height:22px', $r->html);
        self::assertStringNotContainsString('font-weight:700', $r->html);
        self::assertStringNotContainsString('font-weight:bold', $r->html);
        preg_match_all('/font-size:(\d+(?:\.\d+)?)px/', $r->html, $m);
        self::assertNotEmpty($m[1]);
        self::assertGreaterThanOrEqual(13, min(array_map('floatval', $m[1])));
        self::assertSame(1, substr_count($r->html, '<a href="https://acme.test"'));
        self::assertStringContainsString('lang="en" dir="ltr"', $r->html);
        self::assertStringContainsString("'Noto Sans'", $r->html);
    }

    public function testArabicIsRightToLeftWithCodesAndAmountsLeftToRight(): void
    {
        $r = (new EmailLayout())->render(
            new EmailContent(
                heading: 'أكّد بريدك الإلكتروني',
                paragraphs: ['أدخل هذا الرمز حيث سجّلت:'],
                rows: [['المجموع', 'JOD 49.000']],
                code: '482913',
                locale: 'ar-JO',
            ),
            $this->branding(),
        );

        self::assertStringContainsString('<html lang="ar" dir="rtl"', $r->html);
        self::assertStringContainsString("'Noto Sans Arabic'", $r->html);
        self::assertStringContainsString('text-align:right" dir="rtl"', $r->html);
        self::assertMatchesRegularExpression('#<p dir="ltr"[^>]*>482913</p>#', $r->html);
        self::assertStringContainsString('<span dir="ltr">JOD 49.000</span>', $r->html);
        self::assertStringContainsString('أُرسلت عبر Whity', $r->html);
        self::assertStringContainsString('تحتاج مساعدة؟', $r->text);
        self::assertStringContainsString('المجموع: JOD 49.000', $r->text);
        self::assertStringContainsString('482913', $r->text);
    }

    public function testWorkspaceLogoReplacesTheTileAndWhiteLabelDropsTheCredit(): void
    {
        $b = new EmailBranding(
            siteName: 'Example Bakery',
            brandColor: '#B4532A',
            supportEmail: '',
            footerText: '',
            logoUrl: 'https://app.example.test/api/v1/branding/asset/7/logo.png',
            sentWithWhity: false,
        );
        $r = (new EmailLayout())->render(new EmailContent(heading: 'Hello'), $b);

        self::assertStringContainsString('<img src="https://app.example.test/api/v1/branding/asset/7/logo.png"', $r->html);
        self::assertStringContainsString('alt="Example Bakery"', $r->html);
        self::assertStringNotContainsString('>E<', $r->html);
        self::assertStringNotContainsString('Sent with Whity', $r->html);
        self::assertStringNotContainsString('Sent with Whity', $r->text);
    }

    public function testRefusesAnUnsafeLogoAndColour(): void
    {
        $b = new EmailBranding('Acme', 'red;background:url(x)', '', '', 'javascript:alert(1)');
        $r = (new EmailLayout())->render(new EmailContent(heading: 'Hello'), $b);

        self::assertStringNotContainsString('javascript:', $r->html);
        self::assertStringNotContainsString('url(x)', $r->html);
        self::assertStringContainsString(EmailBranding::DEFAULT_COLOR, $r->html);
        self::assertStringContainsString('>A<', $r->html);
    }

    public function testPendingCalloutIsDashedInTheBrandColour(): void
    {
        $r = (new EmailLayout())->render(
            new EmailContent(heading: 'This needs your approval', callout: 'Send the March invoices', calloutPending: true),
            $this->branding(),
        );

        self::assertStringContainsString('border:2px dashed #123ABC', $r->html);
    }

    public function testPreheaderIsHiddenPreviewText(): void
    {
        $r = (new EmailLayout())->render(
            new EmailContent(heading: 'Hello', preheader: 'The link works for 7 days.'),
            $this->branding(),
        );

        self::assertStringContainsString('opacity:0">The link works for 7 days.</div>', $r->html);
    }
}
