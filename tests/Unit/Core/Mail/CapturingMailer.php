<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use Whity\Core\Mail\Mailer;

/** Keeps every email sent, for the mail tests. */
final class CapturingMailer implements Mailer
{
    /** @var list<array{0:string,1:string,2:string,3:?string}> */
    public array $sent = [];

    public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
    {
        $this->sent[] = [$toEmail, $subject, $textBody, $htmlBody];
    }
}
