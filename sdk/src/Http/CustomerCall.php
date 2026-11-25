<?php

declare(strict_types=1);

namespace Whity\Sdk\Http;

/**
 * The customer calling one of your customer routes (SDK 1.173, WHIT-1424;
 * design section 41 §9, CA.18).
 *
 * The host sets it on a route declared `'customer' => true` when the caller
 * is a customer: the customer area's token, or a session whose only
 * memberships in the workspace are customer ones. Every field comes from the
 * host, never from the request, so a handler can trust it:
 *
 *  - {@see self::$profileId}: the person.
 *  - {@see self::$workspaceId}: the workspace (also the request's tenant).
 *  - {@see self::$contactIds}: the person's contacts in that workspace. A
 *    contact links to a person when they join, when staff invite them, or
 *    through one of their VERIFIED email addresses, never an unverified one.
 *
 * Scope every query by these. An id in the path or body that is not theirs
 * must answer 404, the same as one that does not exist.
 *
 * It is null when staff call the route: answer them by their permission as
 * on any other route.
 */
final class CustomerCall
{
    public const ATTRIBUTE = 'whity.customer_call';

    /** @param list<int> $contactIds */
    public function __construct(
        public readonly int $profileId,
        public readonly int $workspaceId,
        public readonly array $contactIds,
    ) {
    }

    public static function from(Request $request): ?self
    {
        $value = $request->getAttribute(self::ATTRIBUTE);

        return $value instanceof self ? $value : null;
    }

    /** Whether a contact id is one of the customer's own. */
    public function ownsContact(int $contactId): bool
    {
        return in_array($contactId, $this->contactIds, true);
    }
}
