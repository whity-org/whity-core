<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Sdk\Calendar\Bookings;
use Whity\Sdk\Calendar\WorkspaceBookings;

/** {@see Bookings} pinned to the workspace the call runs in (SDK 1.161, D2). */
final class HostWorkspaceBookings implements WorkspaceBookings
{
    use PinnedWorkspace;

    private const SERVICE = 'Bookings';

    /** @param \Closure(): ?int $workspace */
    public function __construct(private readonly Bookings $bookings, \Closure $workspace)
    {
        $this->workspace = $workspace;
    }

    public function confirmPaid(int $bookingId, string $paymentRef): bool
    {
        return $this->bookings->confirmPaid($this->workspace(), $bookingId, $paymentRef);
    }

    public function release(int $bookingId, string $reason): bool
    {
        return $this->bookings->release($this->workspace(), $bookingId, $reason);
    }
}
