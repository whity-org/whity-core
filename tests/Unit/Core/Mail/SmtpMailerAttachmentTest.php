<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Whity\Core\Mail\MailAttachment;
use Whity\Core\Mail\MailException;
use Whity\Core\Mail\OutgoingEmail;
use Whity\Core\Mail\SmtpConfig;
use Whity\Core\Mail\SmtpConnection;
use Whity\Core\Mail\SmtpMailer;

// FakeSmtpConnection lives beside SmtpMailerTest.
require_once __DIR__ . '/SmtpMailerTest.php';

/**
 * WHIT-947: an email can carry files. With attachments the message is
 * multipart/mixed, the body (text, or text and HTML) is its first part, and
 * each file follows base64-encoded with its name.
 */
final class SmtpMailerAttachmentTest extends TestCase
{
    private static function mailer(FakeSmtpConnection $conn): SmtpMailer
    {
        return new SmtpMailer(new SmtpConfig('mailpit', 1025, 'no-reply@whity.local', 'Whity', 'none'), fn (): SmtpConnection => $conn);
    }

    private static function conn(): FakeSmtpConnection
    {
        return new FakeSmtpConnection(['220 ready', '250 ok', '250 OK', '250 OK', '354 go', '250 queued', '221 Bye']);
    }

    public function testAttachmentsWrapTheBodyInMultipartMixed(): void
    {
        $conn = self::conn();
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
        self::mailer($conn)->sendThreaded(new OutgoingEmail(
            'lina@example.com',
            'Booked: Cake tasting',
            'text body',
            '<p>html body</p>',
            attachments: [new MailAttachment('booking.ics', 'text/calendar; charset=utf-8; method=PUBLISH', $ics)],
        ));
        $sent = $conn->written();

        self::assertMatchesRegularExpression('/Content-Type: multipart\/mixed; boundary="=_whity_mixed_[0-9a-f]{24}"/', $sent);
        self::assertMatchesRegularExpression('/Content-Type: multipart\/alternative; boundary="=_whity_[0-9a-f]{24}"/', $sent);
        self::assertStringContainsString('Content-Disposition: attachment; filename="booking.ics"', $sent);
        self::assertStringContainsString('Content-Type: text/calendar; charset=utf-8; method=PUBLISH; name="booking.ics"', $sent);
        self::assertStringContainsString(base64_encode($ics), $sent);
        // The body comes first, then the file.
        self::assertLessThan(strpos($sent, 'booking.ics'), strpos($sent, 'text body'));
        self::assertStringEndsWith("\r\n.\r\nQUIT\r\n", $sent);
    }

    public function testWithoutAttachmentsTheMessageIsUnchanged(): void
    {
        $conn = self::conn();
        self::mailer($conn)->sendThreaded(new OutgoingEmail('lina@example.com', 'Hi', 'text only'));
        $sent = $conn->written();

        self::assertStringNotContainsString('multipart/mixed', $sent);
        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $sent);
    }

    public function testANameThatCouldBreakTheHeaderIsRefused(): void
    {
        $this->expectException(MailException::class);
        new MailAttachment("booking\".ics\r\nX-Evil: 1", 'text/calendar', 'x');
    }

    public function testATypeThatCouldBreakTheHeaderIsRefused(): void
    {
        $this->expectException(MailException::class);
        new MailAttachment('booking.ics', "text/calendar\r\nX-Evil: 1", 'x');
    }
}
