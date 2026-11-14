<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailContent;
use Whity\Sdk\Notification\NotificationMessage;
use Whity\Sdk\Notification\NotificationTransport;
use Whity\Sdk\Notification\SendResult;

/**
 * The notification `email` channel, delivered (E2). Until now the channel was
 * served by {@see LogTransport}, which wrote a log line and reported "sent",
 * so `/admin/notifications` showed email deliveries that never left.
 *
 * Sends through {@see BrandedEmailSender} like every other core email: the
 * workspace's brand, its language (or the notification's own locale), the
 * subject as the heading and each paragraph of the body below it. The address
 * is the dispatcher's `to` when it is one, else the recipient profile's primary
 * email. Whether the email may go at all was decided at dispatch
 * ({@see \Whity\Core\Mail\EmailGate}).
 *
 * A failure is reported, not thrown, so the delivery job records it and the
 * queue retries it with backoff.
 */
final class NotificationEmailTransport implements NotificationTransport
{
    public const CHANNEL = 'email';

    public function __construct(
        private readonly BrandedEmailSender $sender,
        private readonly ProfileEmailRepository $emails,
    ) {
    }

    public function channel(): string
    {
        return self::CHANNEL;
    }

    public function send(NotificationMessage $message): SendResult
    {
        $to = $this->address($message->recipient);
        if ($to === null) {
            return SendResult::failed('the recipient has no email address');
        }

        $language = $message->locale !== null && $message->locale !== ''
            ? (str_starts_with(strtolower($message->locale), 'ar') ? 'ar' : 'en')
            : $this->sender->language($message->tenantId);
        $subject = trim($message->subject) !== '' ? trim($message->subject) : ($language === 'ar' ? 'إشعار' : 'Notification');
        $paragraphs = array_values(array_filter(
            array_map('trim', preg_split('/\R+/', $message->body) ?: []),
            static fn (string $p): bool => $p !== '',
        ));

        try {
            $this->sender->deliver($message->tenantId, $to, $subject, new EmailContent(
                heading: $subject,
                paragraphs: $paragraphs,
                notice: $this->sender->gate()->isSecurity($message->type)
                    ? ($language === 'ar' ? 'لا يمكن إيقاف رسائل الأمان.' : "Security emails can't be turned off.")
                    : ($language === 'ar' ? 'غيّر الرسائل التي تصلك من التفضيلات › الإشعارات.' : 'Change which emails you get in Preferences › Notifications.'),
                language: $language,
            ));
        } catch (\Throwable $e) {
            return SendResult::failed($e->getMessage());
        }

        return SendResult::sent('email');
    }

    /**
     * Mail settings are the operator's (Settings › Mail), so a channel config
     * has nothing to check.
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public function validateConfig(array $config): array
    {
        return [];
    }

    private function address(string $recipient): ?string
    {
        $recipient = trim($recipient);
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false) {
            return $recipient;
        }
        if (ctype_digit($recipient) && (int) $recipient > 0) {
            $row = $this->emails->findPrimaryForProfile((int) $recipient);
            $email = trim((string) ($row['email'] ?? ''));

            return $email !== '' ? $email : null;
        }

        return null;
    }
}
