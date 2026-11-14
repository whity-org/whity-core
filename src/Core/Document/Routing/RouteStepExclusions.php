<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use InvalidArgumentException;

/**
 * The people questions whose answers a step leaves out (WHIT-1018,
 * `exclude_named_in`): a committee member named as the project's supervisor
 * or co-supervisor is not asked to approve it, is not counted in the quorum,
 * and is never printed among its approvals.
 *
 * Only a subject that names people ({@see RouteSubjectPeopleInterface}) is
 * affected; on any other subject the setting changes nothing.
 *
 * Stateless — worker-safe.
 */
final class RouteStepExclusions
{
    public const MAX = 10;

    private const KEY = '/^[a-z][a-z0-9_]{0,127}$/';

    /**
     * Static helper only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * @return list<string>
     *
     * @throws InvalidArgumentException With a sentence naming what is wrong.
     */
    public static function parse(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || ($raw !== [] && !array_is_list($raw))) {
            throw new InvalidArgumentException("'exclude_named_in' must be a list of question keys.");
        }
        if (count($raw) > self::MAX) {
            throw new InvalidArgumentException('A step can leave out the people named in at most ' . self::MAX . ' questions.');
        }
        $out = [];
        foreach ($raw as $key) {
            if (!is_string($key) || preg_match(self::KEY, $key) !== 1) {
                throw new InvalidArgumentException(
                    "'exclude_named_in' holds question keys: small letters, digits and _, starting with a letter."
                );
            }
            $out[$key] = $key;
        }

        return array_values($out);
    }

    /** @param list<string> $keys */
    public static function encode(array $keys): ?string
    {
        return $keys === [] ? null : (string) json_encode($keys);
    }

    /**
     * A stored column; one that cannot be read leaves nobody out.
     *
     * @return list<string>
     */
    public static function decode(mixed $stored): array
    {
        $decoded = is_array($stored) ? $stored : (is_string($stored) && $stored !== '' ? json_decode($stored, true) : null);
        try {
            return is_array($decoded) ? self::parse(array_values($decoded)) : [];
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    /**
     * @param list<string> $keys
     *
     * @throws InvalidArgumentException
     */
    public static function assertAllowed(array $keys, ?string $kind): void
    {
        if ($keys !== [] && $kind === RouteStepKind::FINALISE) {
            throw new InvalidArgumentException('The final document stage reaches nobody, so it has nobody to leave out.');
        }
    }
}
