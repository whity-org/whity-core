<?php

declare(strict_types=1);

namespace Whity\Core\Messaging\Email;

use Closure;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailOptions;
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
     * @param (Closure(int, int): ?string)|null $authorName (tenant id, message id) => who wrote it
     */
    public function __construct(
        private readonly ThreadedMailer $mailer,
        private readonly MessagingSettings $settings,
        private readonly Closure $workspaceName,
        private readonly string $messageIdHost,
        /** E2 `message-reply`: with the shared sender, the email is the workspace's HTML card. */
        private readonly ?BrandedEmailSender $sender = null,
        private readonly ?Closure $authorName = null,
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
        $replyTo = $replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false ? $replyTo : null;
        $inReplyTo = self::validId($message->inReplyTo);
        $references = array_values(array_filter(array_map([self::class, 'validId'], $message->references)));

        if ($this->sender !== null) {
            try {
                $this->sender->deliver(
                    $message->tenantId,
                    $message->to,
                    $subject,
                    $this->replyContent($message, $replyTo !== null),
                    // `message-reply` comes from the workspace itself, as Messages always has.
                    new EmailOptions(replyTo: $replyTo, messageId: $messageId, inReplyTo: $inReplyTo, references: $references, fromName: $name !== null && $name !== '' ? $name : null),
                );
            } catch (\Throwable $e) {
                error_log('[EmailChannel] send failed: ' . $e->getMessage());

                return SendOutcome::failed('The mail server did not accept the message.', true);
            }

            return SendOutcome::sent($messageId);
        }

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
                replyTo: $replyTo,
            ));
        } catch (\Throwable $e) {
            error_log('[EmailChannel] send failed: ' . $e->getMessage());

            return SendOutcome::failed('The mail server did not accept the message.', true);
        }

        return SendOutcome::sent($messageId);
    }

    /**
     * The 3.4 `message-reply` card: "**Awss Haddad** from Example Bakery
     * replied:", the message as a quote, and a line saying a reply comes back
     * to the conversation (only when replies do: the workspace has an inbound
     * address). The footer carries the workspace's address.
     */
    private function replyContent(OutboundMessage $message, bool $repliesReturn): EmailContent
    {
        assert($this->sender !== null);
        $ar = $this->sender->language($message->tenantId) === 'ar';
        $workspace = $this->sender->branding($message->tenantId)->siteName;
        $author = $this->authorName !== null ? ($this->authorName)($message->tenantId, $message->messageId) : null;
        $author = $author !== null && trim($author) !== '' ? trim($author) : $workspace;
        $answer = $message->inReplyTo !== null;
        $address = $this->sender->workspaceContact($message->tenantId, $ar ? 'ar' : 'en')['address'];
        $preview = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $message->body)), 0, 140);

        return new EmailContent(
            heading: '',
            footnote: $repliesReturn ? ($ar
                ? 'ردّ على هذه الرسالة للإجابة؛ تصل مباشرة إلى المحادثة نفسها.'
                : 'Reply to this email to answer; it goes straight to the same conversation.') : null,
            preheader: $preview !== '' ? $preview : null,
            notice: implode(' · ', array_filter([$workspace, $address], static fn (?string $v): bool => $v !== null && $v !== '')),
            language: $ar ? 'ar' : 'en',
            leadLine: $author === $workspace
                ? ['before' => '', 'strong' => $workspace, 'after' => $ar ? ($answer ? ' ردّت:' : ' كتبت:') : ($answer ? ' replied:' : ' wrote:')]
                : ($ar
                    ? ['before' => $answer ? 'ردّ ' : 'كتب ', 'strong' => $author, 'after' => ' من ' . $workspace . ':']
                    : ['before' => '', 'strong' => $author, 'after' => ' from ' . $workspace . ($answer ? ' replied:' : ' wrote:')]),
            quote: $message->body,
        );
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
