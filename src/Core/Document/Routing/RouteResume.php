<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * Where a route resumes when the author resubmits after a send-back
 * (WHIT-919, #1723). The sender chooses when sending back, and the choice is
 * recorded on the `sent_back` event (`resume_from`, CHECK-constrained in
 * migration 226).
 */
final class RouteResume
{
    /** Back to the step that sent it back, with its people resolved again. */
    public const SENDER = 'sender';

    /** Back to step 1, as if newly submitted. Earlier approvals stay in the trail. */
    public const START = 'start';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::SENDER, self::START];
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::all(), true);
    }
}
