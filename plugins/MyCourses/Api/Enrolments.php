<?php

declare(strict_types=1);

namespace MyCourses\Api;

use PDO;

/**
 * The enrolments table, every query bound to a workspace (WHIT-1426). Customer
 * reads also bind the customer's own contact ids; nothing here takes either
 * from a request.
 */
final class Enrolments
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * A customer's enrolments in a workspace.
     *
     * @param list<int> $contactIds
     * @return list<array<string, mixed>>
     */
    public function ofContacts(int $workspaceId, array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($contactIds), '?'));
        $stmt = $this->db->prepare("SELECT * FROM mycourses_enrolments WHERE tenant_id = ? AND contact_id IN ($in) ORDER BY id");
        $stmt->execute([$workspaceId, ...$contactIds]);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function setDone(int $workspaceId, int $id, int $done): void
    {
        $this->db->prepare('UPDATE mycourses_enrolments SET lessons_done = :done WHERE id = :id AND tenant_id = :tenant')
            ->execute([':done' => $done, ':id' => $id, ':tenant' => $workspaceId]);
    }

    /** @return list<array<string, mixed>> */
    public function all(int $workspaceId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM mycourses_enrolments WHERE tenant_id = :tenant ORDER BY id');
        $stmt->execute([':tenant' => $workspaceId]);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function enrol(int $workspaceId, int $contactId, string $course, int $lessons): void
    {
        $this->db->prepare('INSERT INTO mycourses_enrolments (tenant_id, contact_id, course, lessons, lessons_done) VALUES (:tenant, :contact, :course, :lessons, 0)')
            ->execute([':tenant' => $workspaceId, ':contact' => $contactId, ':course' => $course, ':lessons' => $lessons]);
    }
}
