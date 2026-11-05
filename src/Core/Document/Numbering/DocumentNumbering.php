<?php

declare(strict_types=1);

namespace Whity\Core\Document\Numbering;

use PDO;
use Whity\Core\Form\FormReferenceFormat;
use Whity\Database\SequenceCounters;
use Whity\Sdk\Sql\SequenceAllocator;

/**
 * Hands a document its number, exactly once (WHIT-684).
 *
 * A retry, a replay or two approvers finishing together must never mint a second
 * number or renumber a document. So the row is CLAIMED before the counter is
 * touched: a guarded UPDATE sets `number_assigned_at` only where it is still null.
 * On PostgreSQL the second caller blocks on the row lock until the first commits,
 * then re-reads the row, finds it claimed and changes nothing; on SQLite the
 * database write lock does the same. The loser therefore never touches the counter,
 * so a race burns no number, and it is told the winner's number.
 *
 * The claim, the allocation and the number are one transaction (the caller's when
 * one is open), so a rollback undoes all three and the document can be numbered
 * again.
 *
 * The date in a number is the instant it was handed out, in UTC as for form
 * references, and the number is stored: nothing recomputes it.
 */
final class DocumentNumbering
{
    private readonly SequenceAllocator $sequences;

    public function __construct(
        private readonly PDO $db,
        ?SequenceAllocator $sequences = null,
    ) {
        $this->sequences = $sequences ?? new SequenceCounters($db);
    }

    /**
     * Whether this document is waiting for its number: it has a rule and no claim yet.
     */
    public function isPending(int $tenantId, int $documentId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM documents
              WHERE id = :id AND tenant_id = :tenant_id
                AND number_pattern IS NOT NULL AND number_assigned_at IS NULL'
        );
        $stmt->execute([':id' => $documentId, ':tenant_id' => $tenantId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The document's number, handing it out if nobody has.
     *
     * @return array{number: string|null, minted: bool} `minted` is true for the one
     *         call that handed it out. The number is null only for a document with
     *         no numbering rule.
     */
    public function assign(int $tenantId, int $documentId, ?\DateTimeImmutable $at = null): array
    {
        $at = ($at ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $claim = $this->db->prepare(
                'UPDATE documents SET number_assigned_at = :at
                  WHERE id = :id AND tenant_id = :tenant_id
                    AND number_pattern IS NOT NULL AND number_assigned_at IS NULL'
            );
            $claim->execute([':at' => $at->format('Y-m-d H:i:s'), ':id' => $documentId, ':tenant_id' => $tenantId]);
            $minted = $claim->rowCount() > 0;

            $number = $minted
                ? $this->mint($tenantId, $documentId, $at)
                : $this->stored($tenantId, $documentId);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return ['number' => $number, 'minted' => $minted];
    }

    private function mint(int $tenantId, int $documentId, \DateTimeImmutable $at): string
    {
        $stmt = $this->db->prepare('SELECT number_pattern FROM documents WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $documentId, ':tenant_id' => $tenantId]);
        $pattern = (string) $stmt->fetchColumn();

        // Hashed again under its own prefix so a form and a document that happen to
        // use the same wording don't share a series.
        $counter = 'document-number:' . md5(FormReferenceFormat::counterName($pattern, $at));
        $number = FormReferenceFormat::render($pattern, $this->sequences->next($tenantId, $counter), $at);

        $write = $this->db->prepare('UPDATE documents SET number = :number WHERE id = :id AND tenant_id = :tenant_id');
        $write->execute([':number' => $number, ':id' => $documentId, ':tenant_id' => $tenantId]);

        return $number;
    }

    private function stored(int $tenantId, int $documentId): ?string
    {
        $stmt = $this->db->prepare('SELECT number FROM documents WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $documentId, ':tenant_id' => $tenantId]);
        $number = $stmt->fetchColumn();

        return is_string($number) ? $number : null;
    }
}
