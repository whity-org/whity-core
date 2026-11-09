<?php

declare(strict_types=1);

namespace Glossary\Api;

use PDO;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\I18n\LocalizedText;

/**
 * Workspace-scoped CRUD over `glossary_terms`.
 *
 * Every statement filters on the caller's workspace, so another workspace's
 * term answers 404. A request with no resolved workspace is refused.
 */
final class GlossaryTermsApiHandler
{
    private const MAX_CODE_LENGTH = 64;

    /** Longest text in one language. The columns are unbounded TEXT. */
    private const MAX_TEXT_LENGTH = 2000;

    public function __construct(private readonly PDO $db)
    {
    }

    public function list(): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Workspace context is required', 403);
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT id, code, term, definition FROM glossary_terms WHERE tenant_id = :tenant_id ORDER BY code, id'
            );
            $stmt->execute([':tenant_id' => $tenantId]);

            /** @var list<array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json(['data' => array_map([$this, 'toPublic'], $rows)], 200);
        } catch (\Throwable) {
            return Response::error('Failed to fetch terms', 500);
        }
    }

    public function create(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Workspace context is required', 403);
        }

        $fields = $this->readFields($request);
        if (is_string($fields)) {
            return Response::error($fields, 400);
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO glossary_terms (tenant_id, code, term, definition)
                 VALUES (:tenant_id, :code, :term, :definition)'
            );
            $stmt->execute([':tenant_id' => $tenantId] + $fields);

            $row = $this->find((int) $this->db->lastInsertId(), $tenantId);

            return $row === null
                ? Response::error('Failed to add the term', 500)
                : Response::json(['data' => $this->toPublic($row)], 201);
        } catch (\Throwable) {
            return Response::error('Failed to add the term', 500);
        }
    }

    public function update(Request $request, int $id): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Workspace context is required', 403);
        }

        $fields = $this->readFields($request);
        if (is_string($fields)) {
            return Response::error($fields, 400);
        }

        try {
            $stmt = $this->db->prepare(
                'UPDATE glossary_terms SET code = :code, term = :term, definition = :definition
                 WHERE id = :id AND tenant_id = :tenant_id'
            );
            $stmt->execute([':id' => $id, ':tenant_id' => $tenantId] + $fields);

            if ($stmt->rowCount() === 0) {
                return Response::error('Term not found', 404);
            }

            $row = $this->find($id, $tenantId);

            return $row === null
                ? Response::error('Term not found', 404)
                : Response::json(['data' => $this->toPublic($row)], 200);
        } catch (\Throwable) {
            return Response::error('Failed to change the term', 500);
        }
    }

    public function delete(int $id): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Workspace context is required', 403);
        }

        try {
            $stmt = $this->db->prepare('DELETE FROM glossary_terms WHERE id = :id AND tenant_id = :tenant_id');
            $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);

            return $stmt->rowCount() === 0
                ? Response::error('Term not found', 404)
                : Response::json(['data' => ['id' => $id]], 200);
        } catch (\Throwable) {
            return Response::error('Failed to remove the term', 500);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, code, term, definition FROM glossary_terms WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Validate the body. Returns the bound parameters, or what is wrong.
     *
     * @return array{':code': string, ':term': string, ':definition': string}|string
     */
    private function readFields(Request $request): array|string
    {
        $body = json_decode($request->getBody(), true);
        if (!is_array($body)) {
            return 'The body must be a JSON object';
        }

        $code = $body['code'] ?? null;
        if (!is_string($code) || trim($code) === '' || mb_strlen($code) > self::MAX_CODE_LENGTH) {
            return 'code must be text of at most ' . self::MAX_CODE_LENGTH . ' characters';
        }

        $term = LocalizedText::normalize($body['term'] ?? null);
        if ($term === null || $term === []) {
            return 'term needs text in at least one language, for example {"ar": "...", "en": "..."}';
        }

        $definition = LocalizedText::normalize($body['definition'] ?? []);
        if ($definition === null) {
            return 'definition must be an object with "ar" and "en" text';
        }

        foreach ([...array_values($term), ...array_values($definition)] as $text) {
            if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
                return 'Each language can have at most ' . self::MAX_TEXT_LENGTH . ' characters';
            }
        }

        return [
            ':code' => trim($code),
            ':term' => json_encode($term, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':definition' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toPublic(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'term' => $this->decode($row['term'] ?? null),
            'definition' => $this->decode($row['definition'] ?? null),
        ];
    }

    /**
     * A stored value as a JSON object, `{}` rather than `[]` when it is empty.
     */
    private function decode(mixed $stored): object
    {
        $value = is_string($stored) ? LocalizedText::normalize(json_decode($stored, true)) : null;

        return (object) ($value ?? []);
    }
}
