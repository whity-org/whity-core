<?php

declare(strict_types=1);

namespace Whity\Core\Queue\Jobs;

use Whity\Core\Plugin\Store\StoreMarks;
use Whity\Sdk\JobInterface;

/**
 * Fetches the Whity store's Verified marks for store-installed code plugins (WHIT-1014).
 *
 * The scheduler dispatches it every 15 minutes. {@see StoreMarks::refresh()} only fetches what is
 * due (a statement over an hour old, or near its expiry), so this is cheap when nothing changed.
 */
final class RefreshStoreMarksJob implements JobInterface
{
    public const NAME = 'core.plugins.refresh_store_marks';

    public function __construct(private readonly StoreMarks $marks)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return $this->marks->refresh();
    }
}
