<?php

declare(strict_types=1);

namespace Whity\Core\Security;

/**
 * Too many wrong "confirm it's you" codes for this person lately (#2223).
 * Thrown before the code is checked; the HTTP side answers 429.
 */
final class IdentityCheckThrottledException extends \RuntimeException
{
}
