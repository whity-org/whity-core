<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

/**
 * How {@see MessagingService::send()} sends (ADR 0016 §2).
 *
 * `source` says why the message exists, for the thread and for reports:
 * `staff.reply`, `agent.reply`, `calendar.reminder`, or a plugin's own word.
 */
final class SendOptions
{
    public function __construct(
        public readonly string $channel = 'email',
        public readonly ?string $subject = null,
        public readonly string $source = 'staff.reply',
        public readonly ?int $authorProfileId = null,
        public readonly bool $byAgent = false,
        public readonly bool $newConversation = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/', $source) !== 1) {
            throw new MessagingException('The source must be a short lowercase word such as calendar.reminder.');
        }
    }
}
