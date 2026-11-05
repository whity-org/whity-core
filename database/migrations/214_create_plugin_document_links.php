<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreatePluginDocumentLinks — which plugin issued each document (SDK 1.63, WHIT-886).
 *
 * A document is issued through the workspace's documents service and carries no
 * record of which plugin asked for it. A plugin's QR tracking and its scan history
 * must only ever answer for documents that plugin issued, so the link is written
 * at issue time, in the same request, and read before any tracking answer.
 *
 * One row per document: a document has one issuer. `plugin_slug` is the plugin's
 * storage slug. Rows go when the document or the workspace goes.
 *
 * Idempotent and reversible.
 */
final class CreatePluginDocumentLinks
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS plugin_document_links (
                id          BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id   INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                plugin_slug VARCHAR(64)  NOT NULL,
                document_id BIGINT       NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
                created_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, document_id)
            )
        ");
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_plugin_document_links_plugin
                ON plugin_document_links(tenant_id, plugin_slug)'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS plugin_document_links CASCADE');
    }
}
