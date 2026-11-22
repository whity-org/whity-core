<?php

declare(strict_types=1);

namespace Whity\Core\Navigation;

use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * The menu items and settings pages an operator has hidden on this instance
 * (whity-org/whity-core#2386).
 *
 * A product that runs Whity next to its own app may not want some of Whity's
 * pages: hiding an id removes the item from every menu and makes its pages
 * answer 404. The ids are the stable ones in web/lib/routes/stable-ids.json:
 *
 *  - a menu item by its id: `calendar`, `contacts`, `plugin-<featureId>`;
 *  - a Settings page as `settings.<id>`: `settings.sso`, `settings.import`.
 *
 * Two sources, and an id hidden in either is hidden:
 *
 *  - the `WHITY_HIDDEN_NAV_IDS` environment variable, for an operator who
 *    configures the container and wants it fixed there;
 *  - the instance setting `navigation.hidden_ids`, for one who sets it on the
 *    settings screen or with `whity-cli`.
 *
 * A union rather than one overriding the other: hiding takes a page away, so
 * neither source should be able to bring back one the other hid.
 *
 * This hides; it does not grant or deny. The API behind a hidden page keeps
 * its own permission checks, and a page nobody may open stays closed whether
 * or not it is hidden.
 */
final class HiddenNavIds
{
    public const SETTING = SettingsRegistry::NAVIGATION_HIDDEN_IDS;

    public const ENV = 'WHITY_HIDDEN_NAV_IDS';

    /** The prefix that marks a Settings page id rather than a menu item id. */
    public const SETTINGS_PREFIX = 'settings.';

    /** One id: a menu or settings id, or `settings.` and one. */
    public const ID_RE = '/^(settings\.)?[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/';

    /** More than this is a mistake, not a configuration. */
    public const MAX_IDS = 200;

    /**
     * Ids the setting may not hide: the screen where the setting itself is
     * changed. Hiding them from the screen would leave no way back except the
     * command line. The environment variable can still hide them; whoever sets
     * that controls the container.
     *
     * @var list<string>
     */
    public const NOT_FROM_SETTING = ['website-settings', 'settings.general'];

    /**
     * Parse a comma-separated list into ids, dropping blanks and duplicates.
     *
     * @return list<string>
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = trim($part);
            if ($id !== '' && preg_match(self::ID_RE, $id) === 1) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Why a value for the setting is refused, or null when it is fine.
     */
    public static function validate(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $p): bool => $p !== ''));
        if (count($parts) > self::MAX_IDS) {
            return self::SETTING . ' holds at most ' . self::MAX_IDS . ' ids.';
        }
        foreach ($parts as $id) {
            if (preg_match(self::ID_RE, $id) !== 1) {
                return self::SETTING . " has \"{$id}\", which is not an id. Use menu ids like calendar or plugin-reports, and settings.<id> for a Settings page, separated by commas.";
            }
            if (in_array($id, self::NOT_FROM_SETTING, true)) {
                return self::SETTING . " cannot hide {$id}: that is where this setting is changed. Use the " . self::ENV . ' environment variable instead.';
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function fromEnvironment(): array
    {
        $value = $_ENV[self::ENV] ?? getenv(self::ENV);

        return self::parse(is_string($value) ? $value : null);
    }

    /**
     * Every hidden id, from both sources. A settings read that fails counts as
     * nothing stored: hiding is cosmetic, so it fails open to the full menu
     * rather than breaking navigation.
     *
     * @return list<string>
     */
    public static function all(?SettingsService $settings): array
    {
        $ids = self::fromEnvironment();

        if ($settings !== null) {
            try {
                $stored = $settings->getGlobal()[self::SETTING] ?? '';
                $ids = array_merge($ids, self::parse($stored));
            } catch (\Throwable $e) {
                error_log('[navigation] reading ' . self::SETTING . ' failed: ' . $e->getMessage());
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * The hidden ids split by kind.
     *
     * @param list<string> $ids
     * @return array{nav: list<string>, settings: list<string>}
     */
    public static function split(array $ids): array
    {
        $nav = [];
        $settings = [];
        foreach ($ids as $id) {
            if (str_starts_with($id, self::SETTINGS_PREFIX)) {
                $settings[] = substr($id, strlen(self::SETTINGS_PREFIX));
            } else {
                $nav[] = $id;
            }
        }

        return ['nav' => $nav, 'settings' => $settings];
    }
}
