<?php

declare(strict_types=1);

namespace Whity\Core\Security;

use Whity\Core\Document\Signing\SigningChallengeRepository;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailContent;

/**
 * Sends the one-time code an admin confirms a gating approval with (AG.1).
 * The code is only ever in this message and in the admin's hands.
 */
final class IdentityCheckMailer
{
    public function __construct(
        private readonly BrandedEmailSender $sender,
    ) {
    }

    public function send(string $email, string $code, string $what): void
    {
        $minutes = (int) (SigningChallengeRepository::EMAIL_CODE_TTL / 60);
        $this->sender->deliver(
            0,
            $email,
            'Your confirmation code: ' . $code,
            new EmailContent(
                heading: 'Confirm it\'s you',
                paragraphs: [
                    'Use this code to confirm you are ' . $what . '.',
                    'It expires in ' . $minutes . ' minutes and can be used once.',
                ],
                callout: $code,
                footnote: 'If you are not approving anything right now, ignore this message. Nothing is approved without the code.',
            ),
        );
    }
}
