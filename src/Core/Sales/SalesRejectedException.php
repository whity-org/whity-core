<?php

declare(strict_types=1);

namespace Whity\Core\Sales;

/**
 * A quote the workspace can't have as asked (WHIT-1321). `clientMessage` is
 * written for the person and safe to show; `extra` may name the `field` or a
 * `code` (`vetoed`, `expired`, `route_needed`).
 */
final class SalesRejectedException extends \RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly string $clientMessage,
        public readonly int $status = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($clientMessage);
    }

    public static function notFound(string $what): self
    {
        return new self($what . ' not found', 404);
    }

    public static function field(string $field, string $message, int $status = 422): self
    {
        return new self($message, $status, ['field' => $field]);
    }
}
