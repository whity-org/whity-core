<?php

declare(strict_types=1);

namespace Whity\Core\Sales;

use Whity\Sdk\JobInterface;

/**
 * Marks sent quotes past their valid-until date as expired, in every
 * workspace (WHIT-1321, QT.4). Internal: the scheduler queues it once an hour,
 * so a quote expires within the hour after its last valid day. Opening a quote
 * expires it too, so nobody acts on one that is out of date.
 */
final class QuoteExpiryJob implements JobInterface
{
    public const NAME = 'core.sales.quote_expiry';

    /** @param \Closure(): Quotes $quotes built on the first run, so a worker that never runs it builds nothing */
    public function __construct(private readonly \Closure $quotes)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return ['expired' => (($this->quotes)())->expireDue()];
    }
}
