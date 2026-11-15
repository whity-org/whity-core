<?php

declare(strict_types=1);

namespace Whity\Core\Document\Signing;

use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailContent;

/**
 * Sends the one-time code a signer confirms a signature with (WHIT-922).
 *
 * Direct-call, like the invitation and password-reset mailers: the code is
 * only ever in this message and in the signer's hands.
 */
final class SigningCodeMailer
{
    public function __construct(
        private readonly BrandedEmailSender $sender,
    ) {
    }

    public function send(string $email, string $code, string $documentTitle): void
    {
        $minutes = (int) (SigningChallengeRepository::EMAIL_CODE_TTL / 60);
        $this->sender->deliver(
            0,
            $email,
            'Your signing code: ' . $code,
            new EmailContent(
                heading: 'Your signing code',
                paragraphs: [
                    'Use this code to confirm your electronic signature on "' . $documentTitle . '".',
                    'It expires in ' . $minutes . ' minutes and can be used once.',
                ],
                footnote: 'If you are not signing anything right now, ignore this message. Nothing is signed without the code.',
                code: $code,
                notice: 'Sent to ' . $email . '.',
            ),
        );
    }
}
