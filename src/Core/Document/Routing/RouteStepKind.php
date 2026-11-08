<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * What a route step IS (WHIT-918, #1722, migration 224).
 *
 * Until now a step's behaviour lived in two columns, `decision` and
 * `satisfied_by`. `kind` names it once, so the step kinds the routes plan adds
 * (`sign`, `stamp`, `finalise`) have somewhere to live. The two old columns are
 * still written and still drive the engine in this release; `kind` is derived
 * from them on every write, so the three cannot disagree.
 */
final class RouteStepKind
{
    /** People act on it: forward, acknowledge, return. */
    public const REVIEW = 'review';
    /** A gate answered with a verdict under a quorum. */
    public const DECIDE = 'decide';
    /** Satisfied by delivery: people are told, nobody is asked. */
    public const DELIVER = 'deliver';
    /** People sign it (#1726). */
    public const SIGN = 'sign';
    /** A stamp holder applies the company stamp (#1729). */
    public const STAMP = 'stamp';
    /** The engine produces the final document (#1725). */
    public const FINALISE = 'finalise';

    /**
     * The `rule_kind` a finalise step stores (WHIT-921). A finalise step names
     * no rule because it reaches nobody: the engine satisfies it. The column is
     * NOT NULL, so it holds this reserved word, which no routing rule can be
     * registered under (core's rules are fixed, and a plugin's are namespaced).
     */
    public const FINALISE_RULE = 'finalise';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::REVIEW, self::DECIDE, self::DELIVER, self::SIGN, self::STAMP, self::FINALISE];
    }

    public static function isValid(string $kind): bool
    {
        return in_array($kind, self::all(), true);
    }

    /**
     * The kind the two older columns describe. The same mapping migration 224
     * uses for its backfill.
     */
    public static function derive(bool $decision, string $satisfiedBy): string
    {
        if ($decision) {
            return self::DECIDE;
        }

        return RouteSatisfaction::isDelivery($satisfiedBy) ? self::DELIVER : self::REVIEW;
    }
}
