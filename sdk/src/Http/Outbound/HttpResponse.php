<?php

declare(strict_types=1);

namespace Whity\Sdk\Http\Outbound;

/** What the other server answered (SDK 1.114). */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }

    /** @return array<mixed> The decoded JSON object or array, or [] when the body is not one. */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
