<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Printing stays off on an install that upgrades with a renderer (WHIT-1018;
 * owner, 8 Nov 2026).
 *
 * WHIT-1001 turned `documents.render_enabled` on by default. Before it, an
 * install running the render service with no saved value did not print, so the
 * new default alone would start storing PDFs on upgrade. This writes `false`
 * for exactly that install, and someone turns printing on when they want it.
 *
 * It writes only when ALL of these hold:
 *
 *  - NO SAVED VALUE. `ON CONFLICT DO NOTHING`: a saved true or false is the
 *    operator's decision and is never replaced.
 *  - AN EXISTING INSTALL: a workspace besides system tenant 0. A FRESH INSTALL
 *    RUNS THIS MIGRATION TOO, before it seeds, so it has only tenant 0 here and
 *    keeps the default: new installs print out of the box.
 *  - A RENDERER IS CONFIGURED, from this process's own settings: a URL, a
 *    shared secret of at least 32 characters that is not the development
 *    default, and `COMPOSE_PROFILES` either absent or naming `render`.
 *
 * "Configured" and not "ready", because readiness cannot be asked from here:
 * `whity update` stops every service but the database before it migrates, so a
 * live check would call every renderer unreachable. `whity install` writes
 * `COMPOSE_PROFILES`, which makes the answer exact there. A deployment that
 * sets a real secret but runs no renderer gets `false` as well, and stays off
 * until someone turns it on. An install with no renderer settings is left
 * alone, and the default applies if one is set up later.
 *
 * Idempotent: a second run finds the saved value. down() deliberately does
 * nothing: this row cannot be told from one an operator saved, and the code a
 * rollback returns to reads `false` as its default anyway.
 */
final class KeepRenderOffForExistingRenderers
{
    private const KEY = 'documents.render_enabled';

    /** docker-compose.yml's local value: a checkout, not a deployment. */
    private const DEV_SECRET = 'dev_render_shared_secret_change_in_production';

    private const MIN_SECRET_LENGTH = 32;

    public static function up(Database $db): void
    {
        if (!self::rendererConfigured() || !self::installExists($db)) {
            return;
        }

        // App_settings is a sanctioned global table (no tenant_id column).
        $db->query(
            "INSERT INTO app_settings (setting_key, value, updated_at)
             VALUES (:key, 'false', CURRENT_TIMESTAMP)
             ON CONFLICT (setting_key) DO NOTHING",
            [':key' => self::KEY]
        );
    }

    public static function down(Database $db): void
    {
    }

    private static function installExists(Database $db): bool
    {
        return $db->query('SELECT 1 FROM tenants WHERE id <> 0 LIMIT 1')->fetchColumn() !== false;
    }

    private static function rendererConfigured(): bool
    {
        // Unset means the app's own default address, as it does at runtime.
        if (trim(self::env('RENDER_SERVICE_URL') ?? 'http://render:8130') === '') {
            return false;
        }

        $secret = self::env('RENDER_SHARED_SECRET') ?? '';
        if (strlen($secret) < self::MIN_SECRET_LENGTH || $secret === self::DEV_SECRET) {
            return false;
        }

        $profiles = self::env('COMPOSE_PROFILES');
        if ($profiles === null) {
            return true;
        }

        return in_array('render', array_map('trim', explode(',', $profiles)), true);
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) ? $value : null;
    }
}
