<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Closure;

/**
 * SMTP transport for {@see Mailer} (WC-email) — a small, dependency-free client
 * (the container has no composer/mail SDK) speaking the SMTP submission dialogue
 * over an injectable {@see SmtpConnection}:
 *
 *   greeting(220) → EHLO → [STARTTLS(220) → EHLO] → [AUTH LOGIN or XOAUTH2 (235)]
 *   → MAIL FROM(250) → RCPT TO(250/251) → DATA(354) → body + <CRLF>.<CRLF> (250) → QUIT
 *
 * The connection is abstracted so the protocol is unit-tested with a scripted
 * fake (no live server). Delivery is best-effort: a {@see MailException} is thrown
 * on any unexpected reply and the caller (verification flow, hook notifications)
 * treats mail as non-critical and catches it.
 */
final class SmtpMailer implements Mailer, ThreadedMailer
{
    /** @var Closure(SmtpConfig): SmtpConnection */
    private Closure $connector;

    /**
     * @param (Closure(SmtpConfig): SmtpConnection)|null $connector Test seam; defaults
     *        to a real stream connection.
     */
    public function __construct(
        private readonly SmtpConfig $config,
        ?Closure $connector = null,
    ) {
        $this->connector = $connector ?? static fn(SmtpConfig $c): SmtpConnection => StreamSmtpConnection::connect($c);
    }

    public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
    {
        $this->deliver($toEmail, $subject, $textBody, $htmlBody, null, []);
    }

    /** Messages (ADR 0016): the same dialogue, with the conversation's headers. */
    public function sendThreaded(OutgoingEmail $email): void
    {
        $this->deliver($email->to, $email->subject, $email->textBody, $email->htmlBody, $email->fromName, $email->extraHeaders());
    }

    /** @param list<string> $extraHeaders already checked for line breaks by {@see OutgoingEmail} */
    private function deliver(string $toEmail, string $subject, string $textBody, ?string $htmlBody, ?string $fromName, array $extraHeaders): void
    {
        // WHIT-991: no mail from a proposal preview.
        \Whity\Core\Sandbox\SideEffectGuard::assertAllowed('Sending mail');
        // Validate the addresses BEFORE opening the socket / writing any command.
        // A CR/LF in an address would inject SMTP commands into the MAIL FROM /
        // RCPT TO envelope (recipient smuggling), which are written well before
        // the DATA phase — so the guard must run here, not inside buildMessage().
        $fromEmail = self::assertNoCrlf($this->config->fromEmail, 'from address');
        $toEmail = self::assertNoCrlf($toEmail, 'recipient address');

        $conn = ($this->connector)($this->config);
        try {
            $this->expect($conn, 220);            // server greeting
            $this->ehlo($conn);

            if ($this->config->usesStartTls()) {
                $this->command($conn, 'STARTTLS', 220);
                $conn->enableCrypto();
                $this->ehlo($conn);               // re-EHLO over the encrypted channel
            }

            if ($this->config->usesXoauth2()) {
                $this->authXoauth2($conn, $fromEmail);
            } elseif ($this->config->hasAuth()) {
                $this->authLogin($conn);
            }

            $this->command($conn, 'MAIL FROM:<' . $fromEmail . '>', 250);
            // 250 = ok, 251 = will forward — both accept the recipient.
            $this->command($conn, 'RCPT TO:<' . $toEmail . '>', 250, 251);
            $this->command($conn, 'DATA', 354);
            // Dot-stuff the WHOLE payload (headers + body) once, so a body line
            // starting with '.' — in either MIME part — cannot end DATA early.
            $message = self::dotStuff($this->buildMessage($toEmail, $fromEmail, $subject, $textBody, $htmlBody, $fromName, $extraHeaders));
            $conn->write($message . "\r\n.\r\n");
            $this->expect($conn, 250);            // message accepted

            // Politely end the session; a QUIT failure must not fail a sent message.
            try {
                $this->command($conn, 'QUIT', 221);
            } catch (MailException) {
                // ignore — the mail was already accepted above.
            }
        } finally {
            $conn->close();
        }
    }

    private function ehlo(SmtpConnection $conn): void
    {
        $this->command($conn, 'EHLO ' . $this->config->ehloDomain, 250);
    }

    private function authLogin(SmtpConnection $conn): void
    {
        $this->command($conn, 'AUTH LOGIN', 334);
        $this->command($conn, base64_encode((string) $this->config->username), 334);
        $this->command($conn, base64_encode((string) $this->config->password), 235);
    }

    /**
     * AUTH XOAUTH2 (WHIT-901). The initial response is
     * base64("user=<mailbox>^Aauth=Bearer <token>^A^A"). On a refused token the
     * server sends a 334 challenge holding its error, which the client answers with
     * an empty line to get the final failure code.
     *
     * @throws SmtpAuthRejectedException When the server refuses the token.
     */
    private function authXoauth2(SmtpConnection $conn, string $fromEmail): void
    {
        $user = $this->config->hasAuth() ? (string) $this->config->username : $fromEmail;
        if (strpbrk($user, "\r\n\x01") !== false) {
            throw new MailException('invalid SMTP username: contains a line break');
        }

        $conn->write('AUTH XOAUTH2 ' . base64_encode(
            'user=' . $user . "\x01auth=Bearer " . $this->config->accessToken . "\x01\x01"
        ) . "\r\n");

        $code = $this->readReplyCode($conn);
        if ($code === 334) {
            $conn->write("\r\n");
            $code = $this->readReplyCode($conn);
        }
        if ($code === 235) {
            return;
        }
        // Only the code is kept. The server's words can echo account detail.
        if (in_array($code, self::XOAUTH2_REJECTED_CODES, true)) {
            throw new SmtpAuthRejectedException(sprintf('SMTP refused the XOAUTH2 token (%d)', $code));
        }
        throw new MailException(sprintf('unexpected SMTP reply %d (expected 235)', $code));
    }

    /**
     * Write a command line (CRLF-terminated) and assert the reply's status code is
     * one of $accepted.
     */
    private function command(SmtpConnection $conn, string $line, int ...$accepted): void
    {
        $conn->write($line . "\r\n");
        $this->expect($conn, ...$accepted);
    }

    /**
     * Cap on continuation lines in one SMTP reply — a hostile/misconfigured server
     * must not be able to pin the worker in the read loop with endless "code-"
     * lines (each within the socket read timeout, so the timeout alone never trips).
     */
    private const MAX_REPLY_LINES = 100;

    /** Replies meaning "this token is no good", as opposed to an unsupported command. */
    private const XOAUTH2_REJECTED_CODES = [454, 530, 534, 535];

    /**
     * Read a (possibly multi-line) SMTP reply and assert its 3-digit status code
     * is one of $accepted. Multi-line replies use "code-" continuation and a final
     * "code " line.
     */
    private function expect(SmtpConnection $conn, int ...$accepted): void
    {
        $code = $this->readReplyCode($conn);

        if (!in_array($code, $accepted, true)) {
            throw new MailException(sprintf('unexpected SMTP reply %d (expected %s)', $code, implode('/', $accepted)));
        }
    }

    /**
     * Read a (possibly multi-line) SMTP reply and return its status code.
     */
    private function readReplyCode(SmtpConnection $conn): int
    {
        $code = 0;
        $lines = 0;
        do {
            if (++$lines > self::MAX_REPLY_LINES) {
                throw new MailException('SMTP reply exceeded the maximum number of continuation lines');
            }
            $line = $conn->readLine();
            $code = (int) substr($line, 0, 3);
            $continues = isset($line[3]) && $line[3] === '-';
        } while ($continues);

        return $code;
    }

    /**
     * Build the RFC 5322 message: minimal headers + body. Text-only when
     * $htmlBody is null, otherwise multipart/alternative (text + HTML). Bodies use
     * CRLF; the caller dot-stuffs the whole payload once. $fromEmail/$toEmail are
     * already CRLF-validated by send().
     *
     * @param list<string> $extraHeaders already checked for line breaks by {@see OutgoingEmail}
     */
    private function buildMessage(
        string $toEmail,
        string $fromEmail,
        string $subject,
        string $textBody,
        ?string $htmlBody,
        ?string $fromName = null,
        array $extraHeaders = [],
    ): string {
        // A conversation names the workspace as the sender, on the instance's address.
        $displayName = $fromName !== null && $fromName !== '' ? $fromName : $this->config->fromName;
        $from = $displayName !== ''
            ? sprintf('%s <%s>', self::encodeHeader($displayName), $fromEmail)
            : $fromEmail;

        $headers = [
            'From: ' . $from,
            'To: ' . $toEmail,
            'Subject: ' . self::encodeHeader($subject),
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'MIME-Version: 1.0',
            ...$extraHeaders,
        ];

        if ($htmlBody === null) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';

            return implode("\r\n", $headers) . "\r\n\r\n" . self::normalizeCrlf($textBody);
        }

        // multipart/alternative: text first (fallback), HTML second (preferred).
        $boundary = '=_whity_' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $parts = [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            self::normalizeCrlf($textBody),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            self::normalizeCrlf($htmlBody),
            '--' . $boundary . '--',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
    }

    /**
     * Reject a value containing a CR or LF (email header injection). Returns the
     * value unchanged when clean.
     *
     * @throws MailException When $value contains a CR or LF.
     */
    private static function assertNoCrlf(string $value, string $label): string
    {
        if (strpbrk($value, "\r\n") !== false) {
            throw new MailException(sprintf('invalid %s: contains a line break', $label));
        }

        return $value;
    }

    /**
     * Encode a header value safely: any CR/LF is stripped first (a header value is
     * single-line, and a newline here would be header injection), then the value is
     * RFC 2047-encoded when it contains non-ASCII so unicode subjects and display
     * names survive intact.
     */
    private static function encodeHeader(string $value): string
    {
        // Fold away any embedded line breaks — a header value is a single line, and
        // display names / subjects are free text that must never break the header.
        // A run of CR/LF collapses to one space.
        $value = (string) preg_replace('/[\r\n]+/', ' ', $value);

        if (preg_match('/[\x80-\xFF]/', $value) === 1) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    /**
     * Normalise any mix of CR / LF / CRLF to canonical CRLF line endings.
     */
    private static function normalizeCrlf(string $body): string
    {
        return (string) str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $body);
    }

    /**
     * Normalise to CRLF and dot-stuff (a line starting with '.' gets an extra '.')
     * so no content line — in any MIME part — can prematurely terminate the DATA
     * phase. Applied once to the whole message payload.
     */
    private static function dotStuff(string $message): string
    {
        $lines = explode("\r\n", self::normalizeCrlf($message));
        foreach ($lines as $i => $line) {
            if (isset($line[0]) && $line[0] === '.') {
                $lines[$i] = '.' . $line;
            }
        }
        return implode("\r\n", $lines);
    }
}
