<?php

declare(strict_types=1);

namespace Whity\Core\CustomerArea;

use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailBranding;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailOptions;

/**
 * The email that invites a contact to their account in the workspace's
 * customer area (WHIT-1428; design section 41 Q4, CA.6; EM.1): in the
 * workspace's brand and language, one button, plain words.
 */
final class CustomerAccountInviteMailer
{
    public function __construct(
        private readonly BrandedEmailSender $sender,
        /** The accept page, e.g. https://app.whity.dev/accept-invitation. */
        private readonly string $acceptUrlBase,
    ) {
    }

    /** @param string|null $workspace The workspace's own name, as the customer area shows it; the brand's name when null. */
    public function send(int $tenantId, string $email, string $name, string $rawToken, int $ttlDays, ?string $workspace = null): void
    {
        $ar = $this->sender->language($tenantId) === 'ar';
        $brand = $this->sender->branding($tenantId);
        // The workspace's own name heads the email, as in its customer area.
        $workspace = $workspace !== null && trim($workspace) !== '' ? trim($workspace) : $brand->siteName;
        $base = rtrim($this->acceptUrlBase, '?&');
        $link = $base . (str_contains($base, '?') ? '&' : '?') . 'token=' . urlencode($rawToken);
        $greeting = trim($name) !== '' ? trim($name) : null;

        $this->sender->deliver($tenantId, $email, $ar
            ? 'حسابك لدى ' . $workspace
            : 'Your account at ' . $workspace, new EmailContent(
                heading: $ar
                    ? ($greeting !== null ? 'أهلًا ' . $greeting . '، حسابك لدى ' . $workspace . ' جاهز' : 'حسابك لدى ' . $workspace . ' جاهز')
                    : ($greeting !== null ? 'Hello ' . $greeting . ', your account at ' . $workspace . ' is ready' : 'Your account at ' . $workspace . ' is ready'),
                paragraphs: [$ar
                    ? 'ستجد حجوزاتك وفواتيرك ورسائلك مع ' . $workspace . ' في مكان واحد.'
                    : 'See your bookings, bills and messages with ' . $workspace . ' in one place.'],
                ctaLabel: $ar ? 'افتح حسابك' : 'Open your account',
                ctaUrl: $link,
                footnote: $ar
                    ? 'يعمل الرابط ' . $ttlDays . ' أيام ولمرة واحدة. إن لم تكن تتوقع هذه الرسالة فتجاهلها، فلن يُنشأ شيء.'
                    : 'The link works for ' . $ttlDays . ' days and only once. If you weren\'t expecting this, ignore it: nothing is created until you open it.',
                preheader: $ar ? 'حجوزاتك وفواتيرك ورسائلك في مكان واحد.' : 'Your bookings, bills and messages in one place.',
                notice: $ar ? 'أرسلتها ' . $workspace . ' إلى هذا العنوان.' : 'Sent by ' . $workspace . ' to this address.',
                language: $ar ? 'ar' : 'en',
            ), new EmailOptions(branding: new EmailBranding($workspace, $brand->brandColor, $brand->supportEmail, $brand->footerText, $brand->whiteLabel)));
    }
}
