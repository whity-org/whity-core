<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Document\Qr\QrRevocationReason;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\RBAC\CorePermissions;

/**
 * Taking back an issued document (S5, design 2.9 D.3).
 *
 * WHAT IS KEPT: everything. The document, its artifacts, its trail and its codes
 * all stay, because "what did this organisation issue, and when did it stop
 * standing behind it" is the question somebody asks later.
 *
 * WHAT CHANGES, in one transaction, so a failure cannot leave a withdrawn document
 * with a live code or a code retired for a withdrawal that did not happen:
 *
 *   - `documents.withdrawn_at` and `withdrawn_by` are set;
 *   - every live code is retired as `withdrawn`. Earlier printings keep their
 *     `superseded` row, which is why the public page reads the document as well as
 *     the token ({@see \Whity\Api\DocumentVerificationApiHandler});
 *   - open inbox items close, each route getting one trail row that closes them,
 *     so nobody is left being asked to decide on a document that no longer stands.
 *
 * There is no un-withdraw. The confirmation says so, and a person who withdrew by
 * mistake issues the document again.
 */
final class DocumentWithdrawal
{
    public function __construct(
        private readonly PDO $db,
        private readonly DocumentRepository $documents,
        private readonly DocumentQrTokenRepository $tokens,
        private readonly RouteRecipientRepository $recipients,
        private readonly RouteEventRepository $events,
        private readonly ?AuditLoggerInterface $audit = null,
    ) {
    }

    /**
     * Withdraw a document. Returns what the act did, or null when another request
     * had already withdrawn it.
     *
     * @param array<string, mixed> $document A row the caller is allowed to see.
     * @return array{codes: int, items: int}|null
     */
    public function withdraw(int $tenantId, int $actorId, array $document): ?array
    {
        $documentId = (int) $document['id'];

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            if (!$this->documents->markWithdrawn($documentId, $tenantId, $actorId)) {
                if ($ownTransaction) {
                    $this->db->rollBack();
                }

                return null;
            }

            $codes = $this->tokens->revokeAllLive(
                $tenantId,
                $documentId,
                $actorId,
                QrRevocationReason::WITHDRAWN,
            );

            $items = 0;
            foreach ($this->recipients->routesWithOpenItems($documentId, $tenantId) as $routeId) {
                $eventId = $this->events->appendWithdrawal($tenantId, $documentId, $routeId, $actorId);
                $items += $this->recipients->closeOpenOnRoute($tenantId, $routeId, $eventId);
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->audit?->record('documents.document.withdrawn', [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $actorId,
            'target_type'   => 'document',
            'target_id'     => $documentId,
            'metadata'      => [
                'title' => (string) $document['title'],
                'codes_withdrawn' => $codes,
                'items_closed' => $items,
                'permission' => CorePermissions::DOCUMENTS_WITHDRAW,
            ],
        ]);

        return ['codes' => $codes, 'items' => $items];
    }
}
