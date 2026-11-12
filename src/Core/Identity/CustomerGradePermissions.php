<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use Whity\Core\RBAC\CorePermissions;

/**
 * Plugin permissions a customer may hold (#1872, SDK 1.104).
 *
 * {@see CustomerSafeRole} drops privileged actions (`manage`, `upload`,
 * `review`, ...) for customers on every resource, because core cannot tell
 * whether a plugin's `manage` reaches one record or all of them. A plugin that
 * scopes a permission to the caller's own records says so through
 * {@see \Whity\Sdk\PluginCustomerPermissionsInterface}; the plugin loader
 * records what it declares here, and this class decides what counts.
 *
 * PROCESS-WIDE, LIKE THE ROUTES. The loader fills it when a plugin's
 * capabilities are registered and empties a plugin's entry when they are torn
 * down (disable, uninstall, reload), so a disabled plugin's permissions are
 * privileged again for customers in the same request cycle its routes go away.
 *
 * DECIDED AT ASK TIME, not at declare time. A permission counts only while all
 * of these hold:
 *  - an active plugin declared it customer-grade and also declares it as one of
 *    its permissions, and it is the plugin that owns that slug (the loader
 *    passes only slugs this plugin claimed);
 *  - it is plain `resource:action` with no wildcard;
 *  - the resource is not a core resource ({@see CorePermissions}) and not an
 *    administrative one ({@see CustomerSafeRole::isAdminResource()});
 *  - no OTHER registered plugin declares any permission under the same
 *    resource. A plugin vouches only for a resource it alone owns, whichever
 *    of the two loaded first.
 */
final class CustomerGradePermissions
{
    /** @var array<string, array<string, true>> owner => customer-grade slug set */
    private static array $customer = [];

    /** @var array<string, array<string, true>> owner => every resource it declares permissions under */
    private static array $resources = [];

    /** @var array<string, true>|null */
    private static ?array $coreResources = null;

    private function __construct()
    {
    }

    /**
     * Record a plugin's declaration, replacing any earlier one for the same owner.
     *
     * @param string       $owner        The loader's identity for the plugin.
     * @param list<string> $declared     Every permission the plugin declares.
     * @param list<string> $customerSlugs The ones it says are customer-grade,
     *                                    already limited to slugs it owns.
     * @return list<string> The customer-grade slugs refused outright (shape,
     *                      core or admin resource, not declared), for the log.
     */
    public static function declare(string $owner, array $declared, array $customerSlugs): array
    {
        $resources = [];
        $declaredSet = [];
        foreach ($declared as $permission) {
            $resource = self::resourceOf($permission);
            if ($resource !== null) {
                $resources[$resource] = true;
                $declaredSet[$permission] = true;
            }
        }

        $accepted = [];
        $refused = [];
        foreach ($customerSlugs as $slug) {
            if (!isset($declaredSet[$slug]) || self::isCoreOrAdmin($slug)) {
                $refused[] = $slug;
                continue;
            }
            $accepted[$slug] = true;
        }

        self::$resources[$owner] = $resources;
        if ($accepted === []) {
            unset(self::$customer[$owner]);
        } else {
            self::$customer[$owner] = $accepted;
        }

        return $refused;
    }

    /** Forget a plugin: disabled, uninstalled or about to be re-registered. */
    public static function remove(string $owner): void
    {
        unset(self::$customer[$owner], self::$resources[$owner]);
    }

    /** Forget every plugin (a full reload, and tests). */
    public static function reset(): void
    {
        self::$customer = [];
        self::$resources = [];
    }

    /** Whether a customer may hold this permission because an active plugin vouches for it. */
    public static function isCustomerGrade(string $permission): bool
    {
        $permission = strtolower(trim($permission));
        $resource = self::resourceOf($permission);
        if ($resource === null || self::isCoreOrAdmin($permission)) {
            return false;
        }

        $owner = null;
        foreach (self::$customer as $candidate => $slugs) {
            if (isset($slugs[$permission])) {
                $owner = $candidate;
                break;
            }
        }
        if ($owner === null) {
            return false;
        }

        foreach (self::$resources as $other => $resources) {
            if ($other !== $owner && isset($resources[$resource])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every permission that counts right now, for the sign-up screen.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::$customer as $slugs) {
            foreach (array_keys($slugs) as $slug) {
                if (self::isCustomerGrade($slug)) {
                    $out[] = $slug;
                }
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }

    private static function isCoreOrAdmin(string $permission): bool
    {
        $resource = self::resourceOf($permission);
        if ($resource === null) {
            return true;
        }
        if (self::$coreResources === null) {
            self::$coreResources = [];
            foreach (CorePermissions::all() as $core) {
                self::$coreResources[explode(':', $core, 2)[0]] = true;
            }
        }

        return isset(self::$coreResources[$resource]) || CustomerSafeRole::isAdminResource($resource);
    }

    /** The resource of a plain `resource:action` slug, or null for anything else. */
    private static function resourceOf(string $permission): ?string
    {
        if (preg_match('/^([a-z][a-z0-9_]*):[a-z][a-z0-9_]*$/', $permission, $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
