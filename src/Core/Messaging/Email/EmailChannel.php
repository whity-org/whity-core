<?php

declare(strict_types=1);

namespace Whity\Core\Messaging\Email;

use Closure;
use Whity\Core\Mail\OutgoingEmail;
use Whity\Core\Mail\ThreadedMailer;
use Whity\Core\Messaging\MessagingSettings;
use Whity\Sdk\Messaging\ChannelCapabilities;
use Whity\Sdk\Messaging\MessageChannel;
use Whity\Sdk\Messaging\OutboundMessage;
use Whity\Sdk\Messaging\SendOutcome;

/**
 * Core's email channel (ADR 0016 §4).
 *
 * OUT: through the instance's mailer, from its address with the workspace's
 * name, with a Message-ID of our own, `In-Reply-To` and `References` from the
 * thread, and `Reply-To` set to the workspace's inbound address so answers come
 * back to the conversation.
 *
 * IN: the workspace's forwarder posts the raw message to
 * `/api/public/messages/inbound/email/{workspace}`, signed with
 * `X-Whity-Signature: sha256=<hex hmac of the body>` under the workspace's
 * inbound secret. Unsigned or wrongly signed requests read as nothing.
 */
final class EmailChannel implements MessageChannel
{
    public const KEY = 'email';
    public const SIGNATURE_HEADER = 'x-whity-signature';

    /**
     * @param Closure(int): ?string $workspaceName tenant id => its display name
     */
    public function __construct(
        private readonly ThreadedMailer $mailer,
        private readonly MessagingSettings $settings,
        private readonly Closure $workspaceName,
        private readonly string $messageIdHost,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): array
    {
        return ['en' => 'Email', 'ar' => 'البريد الإلكتروني'];
    }

    public function isEnabled(int $tenantId): bool
    {
        return true;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(supportsSubject: true, maxBodyLength: 20000);
    }

    public function send(OutboundMessage $message): SendOutcome
    {
        if (filter_var($message->to, FILTER_VALIDATE_EMAIL) === false) {
            return SendOutcome::failed('The email address is not valid.');
        }

        $messageId = sprintf('<m.%d.%s@%s>', $message->messageId, bin2hex(random_bytes(6)), $this->messageIdHost);
        $subject = trim((string) $message->subject);
        if ($subject === '') {
            $subject = 'Message';
        }
        if ($message->inReplyTo !== null && InboundEmailParser::baseSubject($subject) === $subject) {
            $subject = 'Re: ' . $subject;
        }

        $name = ($this->workspaceName)($message->tenantId);
        $name = $name !== null ? trim((string) preg_replace('/[\r\n]+/', ' ', $name)) : null;
        $replyTo = $this->settings->get($message->tenantId)['inbound_email_address'];

        try {
            $this->mailer->sendThreaded(new OutgoingEmail(
                to: $message->to,
                subject: $subject,
                textBody: $message->body,
                htmlBody: null,
                fromName: $name !== '' ? $name : null,
                messageId: $messageId,
                inReplyTo: self::validId($message->inReplyTo),
                references: array_values(array_filter(array_map([self::class, 'validId'], $message->references))),
                replyTo: $replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false ? $replyTo : null,
            ));
        } catch (\Throwable $e) {
            error_log('[EmailChannel] send failed: ' . $e->getMessage());

            return SendOutcome::failed('The mail server did not accept the message.', true);
        }

        return SendOutcome::sent($messageId);
    }

    public function receive(int $tenantId, string $rawBody, array $headers): array
    {
        $secret = $this->settings->inboundSecret($tenantId);
        $given = (string) ($headers[self::SIGNATURE_HEADER] ?? '');
        if ($secret === null || !self::signatureMatches($rawBody, $given, $secret)) {
            return [];
        }

        $raw = $rawBody;
        $trimmed = ltrim($rawBody);
        if (str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);
            $raw = is_array($decoded) && is_string($decoded['raw'] ?? null) ? $decoded['raw'] : '';
        }

        $message = InboundEmailParser::parse($raw);

        return $message === null ? [] : [$message];
    }

    public function templates(int $tenantId): array
    {
        return [];
    }

    public static function sign(string $rawBody, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    }

    public static function signatureMatches(string $rawBody, string $given, string $secret): bool
    {
        return $given !== '' && hash_equals(self::sign($rawBody, $secret), trim($given));
    }

    private static function validId(?string $id): ?string
    {
        return $id !== null && preg_match('/^<[^<>\s]+@[^<>\s]+>$/', $id) === 1 ? $id : null;
    }
}
