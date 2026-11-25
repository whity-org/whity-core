<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateNativeSignInCodes: the one-time code a native app (Whity Desktop)
 * receives on its loopback listener after a sign-in through the identity
 * provider, and exchanges once for a token-mode session (WHIT-1406; owner
 * decision B of 25 Nov 2026, whity-desktop ADR 0001 decision 5).
 *
 * A row lives for a minute between `/api/v1/auth/sso/{provider}/callback`
 * and `/api/v1/auth/sso/native/exchange` and is deleted on use, so a code
 * cannot be replayed. Only the code's SHA-256 is stored. `code_challenge` is
 * the PKCE S256 challenge the app sent at `/start`; the exchange must bring
 * the matching verifier. `provider_tenant_id` and `tenant_trust` carry the
 * trust tier of the provider that signed the person in, so the session is
 * confined exactly as a browser sign-in through the same provider would be;
 * both the person and the provider's workspace cascade, since a code for a
 * deleted account or workspace must not outlive it.
 *
 * `expires_at` is Unix seconds, compared in PHP, so the rule reads the same on
 * Postgres and SQLite. Install-wide table (no tenant_id), like `plugin_installs`.
 * Safe to run twice; reversible.
 */
final class CreateNativeSignInCodes
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS native_sign_in_codes (
                id                 SERIAL PRIMARY KEY,
                code_hash          VARCHAR(64)  NOT NULL UNIQUE,
                profile_id         INTEGER      NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                email              VARCHAR(255) NOT NULL,
                provider_id        INTEGER      NOT NULL,
                provider_tenant_id INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                tenant_trust       BOOLEAN      NOT NULL DEFAULT FALSE,
                code_challenge     VARCHAR(128) NOT NULL,
                expires_at         BIGINT       NOT NULL,
                created_at         TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_native_sign_in_codes_expires ON native_sign_in_codes (expires_at)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS native_sign_in_codes');
    }
}
