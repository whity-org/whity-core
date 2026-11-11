<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

/**
 * Who a message goes to (ADR 0016 §2): a person in the workspace, or an
 * address on a channel.
 */
final class Recipient
{
    private function __construct(
        public readonly ?int $profileId,
        public readonly ?string $address,
        public readonly ?string $name,
    ) {
    }

    /** A customer (or member) of the workspace; their primary email is used for email. */
    public static function person(int $profileId): self
    {
        return new self($profileId, null, null);
    }

    /** An address on the channel: an email address, a phone number, a chat id. */
    public static function address(string $address, ?string $name = null): self
    {
        $address = trim($address);
        if ($address === '' || strpbrk($address, "\r\n") !== false) {
            throw new MessagingException('The address is empty or not valid.');
        }

        return new self(null, $address, $name !== null && trim($name) !== '' ? trim($name) : null);
    }
}
