<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use PDO;

/**
 * Which plugin issued each document (migration 214, SDK 1.63, WHIT-886).
 *
 * Every statement binds the workspace and the plugin slug, so a link in one
 * workspace is invisible from another, and one plugin's documents are invisible
 * to every other plugin.
 */
final class PluginDocumentLinks
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Record that $pluginSlug issued $documentId, then return the plugin that
     * holds the link. A document already linked to a different plugin keeps that
     * link (the insert does nothing), and the returned slug shows it, so the
     * caller can refuse.
     */
    public function claim(int $tenantId, string $pluginSlug, int $documentId): string
    {
        $stmt = $this->db->prepare(
            'INSERT INTO plugin_document_links (tenant_id, plugin_slug, document_id)
             VALUES (:tenant_id, :plugin_slug, :document_id)
             ON CONFLICT (tenant_id, document_id) DO NOTHING'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':plugin_slug' => $pluginSlug,
            ':document_id' => $documentId,
        ]);

        $owner = $this->db->prepare(
            'SELECT plugin_slug FROM plugin_document_links
              WHERE tenant_id = :tenant_id AND document_id = :document_id'
        );
        $owner->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);
        $slug = $owner->fetchColumn();

        return is_string($slug) ? $slug : '';
    }

    /** Whether $pluginSlug issued $documentId in $tenantId. */
    public function owns(int $tenantId, string $pluginSlug, int $documentId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM plugin_document_links
              WHERE tenant_id = :tenant_id AND plugin_slug = :plugin_slug AND document_id = :document_id'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':plugin_slug' => $pluginSlug,
            ':document_id' => $documentId,
        ]);

        return $stmt->fetchColumn() !== false;
    }
}
