<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * SDK identity (v1.175).
 *
 * {@see self::VERSION} is the version a host application evaluates plugin
 * SDK-constraints against ({@see PluginRequirementsInterface::getSdkConstraint()}).
 * It MUST match the `version` field in the package's composer.json — the host
 * test suite pins the two together so they cannot drift.
 *
 * Versioning policy (additive): new capabilities land in minor versions, one
 * minor per core release (decision D7), and no 1.x minor removes or changes
 * anything a plugin uses. What each minor added is in `sdk/CHANGELOG.md`,
 * newest first; it replaced the ledger that was this docblock in 1.162.
 * The newest entry there is this version, and CI checks that they agree
 * (`scripts/ci-sdk-version-collision.php`).
 *
 * Breaking changes require a new major version.
 */
final class Sdk
{
    /** The SDK contract version shipped by this package. */
    public const VERSION = '1.175.0';

    /**
     * Static identity only — never instantiated.
     */
    private function __construct()
    {
    }
}
