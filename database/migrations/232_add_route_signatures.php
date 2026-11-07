<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use RuntimeException;
use Whity\Database\Database;

/**
 * AddRouteSignatures (WHIT-922, #1726) — the signing act.
 *
 * WHAT IT ADDS
 * ------------
 *  - `document_route_events.prev_hash`, `entry_hash` and `entry_body`: each
 *    trail entry written from now on stores the hash of the entry before it on
 *    the same document, its own hash, and the exact text that was hashed.
 *  - `document_route_steps.sign_capacity` and `sign_on_behalf_of`: on a `sign`
 *    step, the capacity the signer signs in and whom they sign for, fixed by the
 *    route so the signer sees them and cannot change them.
 *  - `route_signatures`: one row per signature. Written once, never updated.
 *    It names the signer, capacity, step, the subject version and its hash, how
 *    the signer confirmed, the time, the address and browser, the consent
 *    wording's version and the drawn signature, and it is sealed with the
 *    instance key.
 *  - `saved_signatures`: a person's drawn signature, kept for reuse. A
 *    preference, not evidence: the signature record keeps its own copy.
 *  - `signing_challenges`: the one-time codes sent by email for signing, and
 *    the record of a fresh sign-in through the workspace's sign-in provider.
 *    Each is bound to the route it was asked for (and a code to the content
 *    hash it was sent for), and is used only there.
 *  - `instance_signing_keys`: the instance's Ed25519 key pairs. The private key
 *    is stored encrypted with the platform encryption key; the public key is
 *    published.
 *
 * EXISTING TRAIL ROWS ARE NOT TOUCHED
 * -----------------------------------
 * Nothing here updates a trail row. Rows written before this migration keep
 * NULL hashes. The first new entry on a document links to an ANCHOR: a hash
 * computed, at that moment, over the document's earlier entries, so the chain
 * still commits to the history before it without rewriting it. The audit check
 * recomputes the anchor from those rows.
 *
 * Idempotent (IF NOT EXISTS) and reversible. down() refuses once a signature
 * or a chained trail entry exists, because dropping either would destroy
 * evidence; it never deletes trail rows.
 */
final class AddRouteSignatures
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE document_route_events ADD COLUMN IF NOT EXISTS prev_hash VARCHAR(64)');
        $db->exec('ALTER TABLE document_route_events ADD COLUMN IF NOT EXISTS entry_hash VARCHAR(64)');
        $db->exec('ALTER TABLE document_route_events ADD COLUMN IF NOT EXISTS entry_body TEXT');
        // One successor per entry: two entries naming the same predecessor would
        // be a fork in the chain, so the second insert is refused.
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_document_route_events_chain
                ON document_route_events(tenant_id, document_id, prev_hash)
             WHERE prev_hash IS NOT NULL'
        );

        $db->exec('ALTER TABLE document_route_steps ADD COLUMN IF NOT EXISTS sign_capacity VARCHAR(200)');
        $db->exec('ALTER TABLE document_route_steps ADD COLUMN IF NOT EXISTS sign_on_behalf_of VARCHAR(200)');

        // `event_id` has no ON DELETE action: a trail entry that carries a
        // signature record cannot be removed on its own. Removing the whole
        // document removes both in the same statement.
        //
        // The signer is kept by id AND by name and address as they were at
        // signing. No foreign key on the signer: a sealed record must not be
        // changed by a later cascade.
        $db->exec("
            CREATE TABLE IF NOT EXISTS route_signatures (
                id                BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id         INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                document_id       BIGINT       NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
                route_id          BIGINT       NOT NULL REFERENCES document_routes(id) ON DELETE CASCADE,
                event_id          BIGINT       NOT NULL REFERENCES document_route_events(id),
                step_id           BIGINT       NOT NULL,
                subject_type      VARCHAR(64)  NOT NULL,
                subject_id        BIGINT       NOT NULL,
                subject_version   INTEGER      NOT NULL,
                content_hash      VARCHAR(64)  NOT NULL,
                signer_profile_id INTEGER      NOT NULL,
                signer_name       VARCHAR(255) NOT NULL,
                signer_email      VARCHAR(320),
                capacity          VARCHAR(200),
                on_behalf_of      VARCHAR(200),
                confirmed_by      VARCHAR(32)  NOT NULL
                    CHECK (confirmed_by IN ('totp', 'passkey', 'email_code', 'fresh_sign_in')),
                signed_at         TIMESTAMP    NOT NULL,
                ip_address        VARCHAR(64),
                user_agent        VARCHAR(512),
                consent_version   VARCHAR(32)  NOT NULL,
                appearance        TEXT         NOT NULL,
                appearance_hash   VARCHAR(64)  NOT NULL,
                record_hash       VARCHAR(64)  NOT NULL,
                seal              TEXT         NOT NULL,
                seal_key_id       VARCHAR(64)  NOT NULL,
                UNIQUE (event_id)
            )
        ");
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_route_signatures_tenant_document
                ON route_signatures(tenant_id, document_id, id)'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_route_signatures_tenant_subject
                ON route_signatures(tenant_id, subject_type, subject_id, subject_version)'
        );

        $db->exec("
            CREATE TABLE IF NOT EXISTS saved_signatures (
                id         BIGSERIAL NOT NULL PRIMARY KEY,
                tenant_id  INTEGER   NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                profile_id INTEGER   NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                appearance TEXT      NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, profile_id)
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS signing_challenges (
                id          BIGSERIAL   NOT NULL PRIMARY KEY,
                tenant_id   INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                profile_id  INTEGER     NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                method      VARCHAR(32) NOT NULL CHECK (method IN ('email_code', 'fresh_sign_in')),
                route_id    BIGINT,
                content_hash VARCHAR(64),
                code_hash   VARCHAR(128),
                attempts    INTEGER     NOT NULL DEFAULT 0,
                expires_at  TIMESTAMP   NOT NULL,
                consumed_at TIMESTAMP,
                created_at  TIMESTAMP   NOT NULL DEFAULT NOW()
            )
        ");
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_signing_challenges_tenant_profile
                ON signing_challenges(tenant_id, profile_id, method, id)'
        );

        $db->exec("
            CREATE TABLE IF NOT EXISTS instance_signing_keys (
                id         SERIAL      NOT NULL PRIMARY KEY,
                key_id     VARCHAR(64) NOT NULL UNIQUE,
                algorithm  VARCHAR(16) NOT NULL DEFAULT 'Ed25519',
                public_key VARCHAR(128) NOT NULL,
                secret_key TEXT        NOT NULL,
                created_at TIMESTAMP   NOT NULL DEFAULT NOW(),
                retired_at TIMESTAMP
            )
        ");
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();
        $signatures = 0;
        $exists = self::tableExists($pdo, 'route_signatures');
        if ($exists) {
            $stmt = $pdo->query('SELECT COUNT(*) FROM route_signatures');
            if ($stmt !== false) {
                $signatures = (int) $stmt->fetchColumn();
                // Closed before any DDL: an open read cursor locks the table on SQLite.
                $stmt->closeCursor();
            }
        }
        $stmt = $pdo->query('SELECT COUNT(*) FROM document_route_events WHERE entry_hash IS NOT NULL');
        $chained = 0;
        if ($stmt !== false) {
            $chained = (int) $stmt->fetchColumn();
            $stmt->closeCursor();
        }

        if ($signatures > 0 || $chained > 0) {
            throw new RuntimeException(
                'Cannot roll back migration 232: signatures or linked trail entries already exist, '
                . 'and they are kept as evidence.'
            );
        }

        $db->exec('DROP TABLE IF EXISTS instance_signing_keys');
        $db->exec('DROP TABLE IF EXISTS signing_challenges');
        $db->exec('DROP TABLE IF EXISTS saved_signatures');
        $db->exec('DROP TABLE IF EXISTS route_signatures');
        $db->exec('ALTER TABLE document_route_steps DROP COLUMN IF EXISTS sign_on_behalf_of');
        $db->exec('ALTER TABLE document_route_steps DROP COLUMN IF EXISTS sign_capacity');
        $db->exec('DROP INDEX IF EXISTS uq_document_route_events_chain');
        $db->exec('ALTER TABLE document_route_events DROP COLUMN IF EXISTS entry_body');
        $db->exec('ALTER TABLE document_route_events DROP COLUMN IF EXISTS entry_hash');
        $db->exec('ALTER TABLE document_route_events DROP COLUMN IF EXISTS prev_hash');
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $stmt = $driver === 'sqlite'
            ? $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?")
            : $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_name = ? AND table_schema = current_schema()');
        $stmt->execute([$table]);
        $found = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();

        return $found;
    }
}
