<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateDocumentIssueBatches (WHIT-997, #1741): bulk issuing from one template
 * (SDK 1.83, `Documents::issueMany()`).
 *
 * `document_issue_batches` is one batch: who asked (a person, or a plugin in
 * the background with the permissions it declared), the template, and the
 * running counts a status read returns. `owner` is `plugin:<name>` or
 * `person:<id>`, and `batch_key` is unique per workspace and owner: sending the
 * same batch again returns it instead of starting another, and two plugins
 * never share a key. `request_hash` tells a true resend from a different batch
 * under a reused key. `backoffs` counts the render tier's refusals in a row, so
 * the worker waits longer each time.
 *
 * `document_issue_batch_items` is one document each, in request order. An item
 * is `pending` until the worker reaches it, then `issued`, `replayed`,
 * `refused` or `failed`, or `cancelled` when the batch was cancelled first. Every item has an origin key, so a batch resumed after
 * a crash replays an item that was issued but not yet marked, instead of
 * issuing it twice.
 *
 * Idempotent and reversible.
 */
final class CreateDocumentIssueBatches
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_issue_batches (
                id                 BIGSERIAL    PRIMARY KEY,
                tenant_id          BIGINT       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                owner              VARCHAR(80)  NOT NULL,
                batch_key          VARCHAR(100) NOT NULL,
                request_hash       VARCHAR(64)  NOT NULL,
                actor_profile_id   BIGINT,
                actor_plugin       VARCHAR(64),
                actor_permissions  TEXT,
                template_slot      VARCHAR(105),
                template_source_id BIGINT,
                render             VARCHAR(8)   NOT NULL DEFAULT 'auto',
                status             VARCHAR(16)  NOT NULL DEFAULT 'queued'
                                   CHECK (status IN ('queued', 'running', 'done', 'cancelled')),
                total              INTEGER      NOT NULL DEFAULT 0,
                issued             INTEGER      NOT NULL DEFAULT 0,
                replayed           INTEGER      NOT NULL DEFAULT 0,
                refused            INTEGER      NOT NULL DEFAULT 0,
                failed             INTEGER      NOT NULL DEFAULT 0,
                cancelled          INTEGER      NOT NULL DEFAULT 0,
                backoffs           INTEGER      NOT NULL DEFAULT 0,
                created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at        TIMESTAMP,
                UNIQUE (tenant_id, owner, batch_key)
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_issue_batch_items (
                id           BIGSERIAL    PRIMARY KEY,
                tenant_id    BIGINT       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                batch_id     BIGINT       NOT NULL REFERENCES document_issue_batches(id) ON DELETE CASCADE,
                position     INTEGER      NOT NULL,
                record       VARCHAR(200),
                field_values TEXT,
                title        VARCHAR(255),
                origin_key   VARCHAR(160) NOT NULL,
                status       VARCHAR(16)  NOT NULL DEFAULT 'pending'
                             CHECK (status IN ('pending', 'issued', 'replayed', 'refused', 'failed', 'cancelled')),
                document_id  BIGINT,
                reason       VARCHAR(64),
                message      VARCHAR(500),
                updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (batch_id, position)
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_issue_batch_items_pending ON document_issue_batch_items(tenant_id, batch_id, status, position)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS document_issue_batch_items');
        $db->exec('DROP TABLE IF EXISTS document_issue_batches');
    }
}
