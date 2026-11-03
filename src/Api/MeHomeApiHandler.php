<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Tenant\TenantContext;

/**
 * Two parts of the home page that had no route (WHIT-872, section 20).
 *
 *   GET /api/me/recent-documents   the caller's drafts and what they sent this week
 *   GET /api/me/setup-steps        what still blocks real work in this workspace
 *
 * Recent documents is session-gated and answers only with rows the caller created in
 * this workspace, so it needs no permission, like `/api/me/form-submissions`.
 *
 * Setup steps is gated on `settings:write`, because the card is for people who can
 * change the workspace (owners and admins, section 20). It answers with facts, not
 * wording or links, and leaves out a step that doesn't apply: an install with no
 * plans has no plan to choose, and the card must be able to finish.
 */
final class MeHomeApiHandler
{
    private const RECENT_LIMIT = 5;
    private const RECENT_DAYS = 7;
    private const SYSTEM_TENANT_ID = 0;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string, string> $params */
    public function recentDocuments(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        $actor    = $request->user;
        $profile  = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
        if ($tenantId === null || $profile === null) {
            return Response::error('Unauthenticated', 401);
        }

        $since = gmdate('Y-m-d H:i:s', time() - self::RECENT_DAYS * 86400);

        $sent = $this->db->prepare(
            'SELECT d.id, d.title, d.created_at AS at,
                    (SELECT p.display_name
                       FROM document_route_recipients r
                       JOIN profiles p ON p.id = r.profile_id
                      WHERE r.document_id = d.id AND r.tenant_id = d.tenant_id AND r.closed_by_event_id IS NULL
                      ORDER BY r.id ASC LIMIT 1) AS waiting_with
               FROM documents d
              WHERE d.tenant_id = :tenant_id AND d.created_by = :profile AND d.created_at >= :since
              ORDER BY d.created_at DESC, d.id DESC
              LIMIT :limit'
        );
        $drafts = $this->db->prepare(
            'SELECT id, title, updated_at AS at
               FROM document_sources
              WHERE tenant_id = :tenant_id AND created_by = :profile AND published_version IS NULL AND updated_at >= :since
              ORDER BY updated_at DESC, id DESC
              LIMIT :limit'
        );
        foreach ([$sent, $drafts] as $stmt) {
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $stmt->bindValue(':profile', $profile, PDO::PARAM_INT);
            $stmt->bindValue(':since', $since, PDO::PARAM_STR);
            $stmt->bindValue(':limit', self::RECENT_LIMIT, PDO::PARAM_INT);
            $stmt->execute();
        }

        $rows = [];
        foreach ($sent->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $with = $row['waiting_with'] ?? null;
            $rows[] = [
                'kind'        => 'document',
                'id'          => (int) $row['id'],
                'title'       => (string) $row['title'],
                'status'      => $with !== null ? 'with' : 'issued',
                'waitingWith' => $with !== null ? (string) $with : null,
                'at'          => (string) $row['at'],
            ];
        }
        foreach ($drafts->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'kind'        => 'draft',
                'id'          => (int) $row['id'],
                'title'       => (string) $row['title'],
                'status'      => 'draft',
                'waitingWith' => null,
                'at'          => (string) $row['at'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return Response::json(['data' => array_slice($rows, 0, self::RECENT_LIMIT)], 200);
    }

    /** @param array<string, string> $params */
    public function setupSteps(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }
        // The system tenant runs the platform; it has no customers to invoice.
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            return Response::json(['data' => ['steps' => [], 'done' => 0, 'total' => 0]], 200);
        }

        $steps = [
            ['key' => 'branding', 'done' => $this->settingSet($tenantId, [
                SettingsRegistry::BRANDING_LOGO_WIDE,
                SettingsRegistry::BRANDING_LOGO_SQUARE,
                SettingsRegistry::BRANDING_MAIN_COLOR,
            ])],
            ['key' => 'team', 'done' => $this->count(
                "SELECT COUNT(*) FROM memberships WHERE tenant_id = :tenant_id AND status = 'active'",
                $tenantId
            ) > 1],
            ['key' => 'agent', 'done' => $this->count(
                "SELECT COUNT(*) FROM agents WHERE tenant_id = :tenant_id AND status = 'active'",
                $tenantId
            ) > 0],
            ['key' => 'tax', 'done' => $this->settingSet($tenantId, [SettingsRegistry::COMPANY_TAX_ID])],
        ];

        // @tenant-guard-ignore: plans is a platform catalogue with no tenant column
        $plans = $this->db->query('SELECT COUNT(*) FROM plans WHERE is_active');
        if ($plans !== false && (int) $plans->fetchColumn() > 0) {
            $steps[] = ['key' => 'plan', 'done' => $this->count('SELECT COUNT(*) FROM tenant_plan WHERE tenant_id = :tenant_id', $tenantId) > 0];
        }

        return Response::json(['data' => [
            'steps' => $steps,
            'done'  => count(array_filter($steps, static fn (array $step): bool => $step['done'])),
            'total' => count($steps),
        ]], 200);
    }

    private function count(string $sql, int $tenantId): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @param list<string> $keys Whether this workspace itself gave any of these a value. */
    private function settingSet(int $tenantId, array $keys): bool
    {
        $names = [];
        $params = [':tenant_id' => $tenantId];
        foreach ($keys as $i => $key) {
            $names[] = ":key{$i}";
            $params[":key{$i}"] = $key;
        }
        $stmt = $this->db->prepare(
            'SELECT value FROM tenant_settings WHERE tenant_id = :tenant_id AND setting_key IN (' . implode(', ', $names) . ')'
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
            // Stored as JSON: an empty string and null both mean nothing is set.
            $text = trim((string) $value, " \"\t\r\n");
            if ($text !== '' && $text !== 'null') {
                return true;
            }
        }

        return false;
    }
}
