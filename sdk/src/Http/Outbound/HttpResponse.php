<?php

declare(strict_types=1);

namespace Whity\Sdk\Http\Outbound;

/** What the other server answered (SDK 1.114; headers since 1.122). */
final class HttpResponse
{
    /** @var array<string, string> lower-case name => value */
    public readonly array $headers;

    /**
     * @param array<string, string> $headers The answer's headers (SDK 1.122). Names
     *        are lower-cased here; a header sent more than once is one value,
     *        joined with ", ".
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        array $headers = [],
    ) {
        $lower = [];
        foreach ($headers as $name => $value) {
            $key = strtolower((string) $name);
            $lower[$key] = isset($lower[$key]) ? $lower[$key] . ', ' . $value : (string) $value;
        }
        $this->headers = $lower;
    }

    /**
     * One header's value, by any spelling of its name, or null when the answer
     * has none (SDK 1.122). For example `Retry-After` on a 429, or `Location`
     * on a 3xx, which the client never follows by itself.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
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
