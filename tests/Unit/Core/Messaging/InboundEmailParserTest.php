<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Messaging;

use PHPUnit\Framework\TestCase;
use Whity\Core\Mail\MailException;
use Whity\Core\Mail\OutgoingEmail;
use Whity\Core\Messaging\Email\EmailChannel;
use Whity\Core\Messaging\Email\InboundEmailParser;

/** Reading customers' email, and the headers ours carry (ADR 0016 §4). */
final class InboundEmailParserTest extends TestCase
{
    public function testAMultipartReplyIsReadWithItsThreadingIds(): void
    {
        $raw = implode("\r\n", [
            'From: =?UTF-8?B?2LPYp9ix2Kk=?= <Sara@Example.TEST>',
            'To: hello@bakery.example',
            'Subject: Re: =?UTF-8?Q?Cake_order?=',
            'Message-ID: <reply-1@mail.example.test>',
            'In-Reply-To: <m.7.abc@whity.test>',
            'References: <m.5.aaa@whity.test>',
            '  <m.7.abc@whity.test>',
            'Content-Type: multipart/mixed; boundary="outer"',
            '',
            '--outer',
            'Content-Type: multipart/alternative; boundary="inner"',
            '',
            '--inner',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            'Yes please, two cakes =E2=80=94 thank you.',
            '--inner',
            'Content-Type: text/html; charset=UTF-8',
            '',
            '<p>Yes please</p>',
            '--inner--',
            '--outer',
            'Content-Type: application/pdf',
            'Content-Disposition: attachment; filename="x.pdf"',
            'Content-Transfer-Encoding: base64',
            '',
            'JVBERi0=',
            '--outer--',
        ]);

        $m = InboundEmailParser::parse($raw);

        self::assertNotNull($m);
        self::assertSame('sara@example.test', $m->from);
        self::assertSame('سارة', $m->fromName);
        self::assertSame('Re: Cake order', $m->subject);
        self::assertSame('Yes please, two cakes — thank you.', $m->body);
        self::assertSame('<p>Yes please</p>', $m->htmlBody);
        self::assertSame('<reply-1@mail.example.test>', $m->externalId);
        self::assertSame('<m.7.abc@whity.test>', $m->inReplyTo);
        self::assertSame(['<m.5.aaa@whity.test>', '<m.7.abc@whity.test>'], $m->references);
        self::assertSame(1, $m->attachmentsNotShown);
    }

    public function testAnHtmlOnlyBase64MailBecomesText(): void
    {
        $raw = "From: a@example.test\nSubject: Hi\nContent-Type: text/html; charset=ISO-8859-1\n"
            . "Content-Transfer-Encoding: base64\n\n" . base64_encode(mb_convert_encoding('<b>Caf&eacute;</b><script>x()</script><br>Bye', 'ISO-8859-1', 'UTF-8'));

        $m = InboundEmailParser::parse($raw);

        self::assertNotNull($m);
        self::assertSame("Café\nBye", $m->body);
    }

    public function testMailWithoutASenderIsRefused(): void
    {
        self::assertNull(InboundEmailParser::parse("Subject: no sender\n\nhello"));
        self::assertNull(InboundEmailParser::parse(''));
    }

    public function testReplyPrefixesAreStripped(): void
    {
        self::assertSame('Cake order', InboundEmailParser::baseSubject('Re: RE: Fwd: Cake order'));
        self::assertSame('طلب كعكة', InboundEmailParser::baseSubject('رد: طلب كعكة'));
        self::assertSame('Cake order', InboundEmailParser::baseSubject('Cake order'));
    }

    public function testSignatureIsCheckedInConstantTimeOverTheExactBody(): void
    {
        $sig = EmailChannel::sign('body', 'secret');
        self::assertTrue(EmailChannel::signatureMatches('body', $sig, 'secret'));
        self::assertFalse(EmailChannel::signatureMatches('body ', $sig, 'secret'));
        self::assertFalse(EmailChannel::signatureMatches('body', $sig, 'other'));
        self::assertFalse(EmailChannel::signatureMatches('body', '', 'secret'));
    }

    public function testOutgoingHeadersRefuseInjection(): void
    {
        $email = new OutgoingEmail('a@example.test', 'S', 'B', null, 'Bakery', '<m.1.x@h.test>', '<r@h.test>', ['<a@h.test>'], 'in@bakery.example');
        self::assertSame([
            'Message-ID: <m.1.x@h.test>',
            'In-Reply-To: <r@h.test>',
            'References: <a@h.test>',
            'Reply-To: in@bakery.example',
        ], $email->extraHeaders());

        $this->expectException(MailException::class);
        new OutgoingEmail('a@example.test', 'S', 'B', null, null, null, "<r@h.test>\r\nBcc: x@evil.test");
    }
}
