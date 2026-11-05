<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

/**
 * The host feature flags an agent declaration may turn on or off (SDK 1.64).
 *
 * Only these names are exposed. A flag the host does not know is refused, so a
 * plugin cannot switch on a behaviour the host never offered. Each flag only
 * narrows what the agent does; none lets it act above the person's permissions.
 */
final class AgentFeatures
{
    /** The agent may suggest an action; a person accepts it. */
    public const SUGGESTIONS = 'suggestions';

    /** The agent may open a proposal a person accepts or declines. */
    public const PROPOSALS = 'proposals';

    /** The agent may keep reference documents (`mayRead`). */
    public const REFERENCE_DOCUMENTS = 'reference_documents';

    public const ALL = [self::SUGGESTIONS, self::PROPOSALS, self::REFERENCE_DOCUMENTS];

    public const MAX = 10;

    private function __construct()
    {
    }

    /**
     * @param list<string> $errors
     */
    public static function check(mixed $features, string $path, array &$errors): void
    {
        if ($features === null) {
            return;
        }
        if (!is_array($features) || (array_is_list($features) && $features !== [])) {
            $errors[] = "{$path}.features: an object of feature to true or false";
            return;
        }
        if (count($features) > self::MAX) {
            $errors[] = "{$path}.features: at most " . self::MAX;
        }
        foreach ($features as $name => $on) {
            if (!is_string($name) || !in_array($name, self::ALL, true)) {
                $errors[] = "{$path}.features: '{$name}' is not a feature the host exposes";
            } elseif (!is_bool($on)) {
                $errors[] = "{$path}.features.{$name}: true or false";
            }
        }
    }
}
