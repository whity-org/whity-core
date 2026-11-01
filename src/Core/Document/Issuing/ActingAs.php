<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

/**
 * Who issues (DOCUMENT-AUTOMATION.md 3.7): the tenant, the person, and their
 * permissions, which records, charts and images are read with. A plugin
 * acting in a request acts as the person and never widens their rights.
 *
 * A plugin acting with no person (a job, WHIT-742 slice 3) is
 * {@see self::background()}: no profile, `plugin` names it, and its
 * permissions are only those the plugin declares. It may issue only from its
 * own slots, and only where the workspace allowed it.
 */
final class ActingAs
{
    /** @var callable(string): bool */
    public readonly mixed $hasPermission;

    /** @param callable(string): bool $hasPermission */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $profileId,
        callable $hasPermission,
        // The plugin acting with no person; null for a person.
        public readonly ?string $plugin = null,
    ) {
        $this->hasPermission = $hasPermission;
    }

    /** @param list<string> $permissions what the plugin declares */
    public static function background(int $tenantId, string $plugin, array $permissions): self
    {
        return new self($tenantId, 0, static fn (string $permission): bool => in_array($permission, $permissions, true), $plugin);
    }

    /** The profile to record as the issuer: none for a plugin in the background. */
    public function issuerId(): ?int
    {
        return $this->plugin === null ? $this->profileId : null;
    }
}
