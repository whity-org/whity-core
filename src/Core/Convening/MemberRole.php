<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

/**
 * The SEAT a person holds on a convening body.
 *
 * THIS IS NOT A PERMISSION, and the distinction is the reason the class carries
 * a docblock at all. Holding the chair grants nothing in RBAC: every act in this
 * subsystem is gated by {@see \Whity\Core\RBAC\CorePermissions::CONVENING_MANAGE}
 * and `CONVENING_DECIDE`, resolved by the same {@see \Whity\Auth\RoleChecker}
 * every other route uses. A body could be chaired by somebody who may not so
 * much as read the tenant's meetings, and that would be a configuration mistake
 * rather than a privilege escalation.
 *
 * The seat is a label in the minute-book, not a way to act for someone. A
 * body's decision answers a document's approval only as the person who
 * records it ({@see DecisionRouteBridge}, #2247): being chair does not let the
 * secretary approve in the chair's name, and being on the body does not put
 * anyone's name on a routing act they did not perform.
 *
 * Three values and no more. `deputy chair`, `observer` and `co-opted member` are
 * all real seats in real institutions and all of them are the same three
 * questions to this code — may they speak for the body, do they minute it, are
 * they on it — so they are a LABEL an organisation puts on a membership, not a
 * fourth branch here. A vocabulary that grows with every institution's
 * constitution stops being a vocabulary.
 */
final class MemberRole
{
    /** Speaks for the body. First candidate to carry its decision to a route. */
    public const CHAIR = 'chair';

    /** Minutes the body. Second candidate, and usually the person recording. */
    public const SECRETARY = 'secretary';

    /** Sits on the body. */
    public const MEMBER = 'member';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::CHAIR, self::SECRETARY, self::MEMBER];
    }

    public static function isValid(string $role): bool
    {
        return in_array($role, self::all(), true);
    }

    /**
     * How strongly a seat speaks for the body, lower first.
     *
     * Orders a body's member list, chair first. It is a
     * function rather than a bare array so a caller cannot accidentally sort by
     * the alphabetical order of the constants, which would put `chair` after
     * neither and `member` before `secretary` — an order with no meaning that
     * would still look deliberate.
     */
    public static function precedence(string $role): int
    {
        return match ($role) {
            self::CHAIR => 0,
            self::SECRETARY => 1,
            default => 2,
        };
    }
}
