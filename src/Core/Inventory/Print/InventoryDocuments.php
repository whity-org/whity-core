<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Print;

use PDO;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Issuing\ActingAs;
use Whity\Core\Document\Issuing\DocumentIssueRefused;
use Whity\Core\Document\Issuing\IssueInput;
use Whity\Core\Document\Issuing\TemplateIssuing;
use Whity\Core\Inventory\InventoryRejectedException;

/**
 * Printing inventory's papers (IV.20, WHIT-1234): a purchase order, a goods
 * received note, and the label sheet items print on.
 *
 * Each prints from a template of the workspace's, seeded the first time it is
 * needed from {@see InventoryStarters} and published, so it prints straight
 * away in the workspace's document theme. From then on the template is the
 * workspace's: a person can change it in the editor, and printing uses what
 * they published. A template someone deleted is seeded again on the next
 * print, as printing an order must always work.
 *
 * A purchase order or a receipt is issued through the one issuing path, as
 * the person printing it, so it is numbered, kept and verifiable like any
 * other document. Printing the same order again returns the same document
 * until the order or its template changes; a receipt never changes, so it
 * has one per template version.
 */
final class InventoryDocuments
{
    /** @var \Closure(): TemplateIssuing */
    private \Closure $issuing;

    /** @var (\Closure(int, int): (array{bytes: string, content_type: string}|null))|null */
    private ?\Closure $pdf;

    /**
     * @param \Closure(): TemplateIssuing $issuing built when first needed
     * @param (\Closure(int, int): (array{bytes: string, content_type: string}|null))|null $pdf
     *        tenant id, document id => the document's latest PDF
     */
    public function __construct(
        private readonly PDO $db,
        private readonly DocumentSourceRepository $sources,
        private readonly DocumentSourceVersionRepository $versions,
        \Closure $issuing,
        ?\Closure $pdf = null,
    ) {
        $this->issuing = $issuing;
        $this->pdf = $pdf;
    }

    /**
     * The workspace's template for a document in a language, seeded and
     * published when it has none.
     */
    public function templateId(int $tenantId, string $document, string $lang): int
    {
        $lang = $lang === 'ar' ? 'ar' : 'en';
        $key = InventoryStarters::key($document, $lang);
        $stmt = $this->db->prepare(
            'SELECT id FROM document_sources WHERE tenant_id = :tenant_id AND starter_key = :starter_key ORDER BY id LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':starter_key' => $key]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $starter = (new InventoryStarters())->build($document, $lang);
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $sourceId = $this->sources->create($tenantId, [
                'kind' => $starter['kind'],
                'title' => $starter['title'],
                'lang' => $starter['lang'],
                'default_view' => 'page',
                'page' => $starter['page'],
                'record_type' => $starter['record_type'],
                'blocks' => $starter['blocks'],
                'own_fields' => $starter['own_fields'],
                'scope' => DocumentAccessPolicy::SCOPE_SYSTEM,
                'created_by' => null,
                'starter_key' => $key,
            ]);
            if ($this->versions->publish($sourceId, $tenantId, 1, null) === null) {
                throw new \RuntimeException('The inventory template could not be published');
            }
            if ($own) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $sourceId;
    }

    /**
     * The template ids by document and language, for the screens that print.
     *
     * @return array<string, array{en: int, ar: int}>
     */
    public function templates(int $tenantId): array
    {
        $out = [];
        foreach (InventoryStarters::documents() as $document) {
            $out[$document] = ['en' => $this->templateId($tenantId, $document, 'en'), 'ar' => $this->templateId($tenantId, $document, 'ar')];
        }

        return $out;
    }

    /**
     * Print a purchase order; the order keeps the document it last printed as.
     *
     * @return array{document_id: int, number: string|null, title: string, replayed: bool, pdf: array{bytes: string, content_type: string}|null}
     * @throws DocumentIssueRefused|InventoryRejectedException
     */
    public function printOrder(ActingAs $actor, int $orderId, string $lang): array
    {
        $stmt = $this->db->prepare("SELECT id, updated_at FROM inv_orders WHERE tenant_id = :tenant_id AND id = :id AND kind = 'purchase'");
        $stmt->execute([':tenant_id' => $actor->tenantId, ':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order)) {
            throw InventoryRejectedException::notFound('Purchase order');
        }
        $changed = strtotime((string) $order['updated_at']);
        $result = $this->issue(
            $actor,
            InventoryStarters::PURCHASE_ORDER,
            $lang,
            PurchaseOrderFieldProvider::RECORD_TYPE . ':' . $orderId,
            'inventory/purchase-order/' . $orderId . '/' . ($changed !== false ? $changed : 0),
        );
        $this->db->prepare('UPDATE inv_orders SET document_id = :document_id WHERE tenant_id = :tenant_id AND id = :id')
            ->execute([':document_id' => $result['document_id'], ':tenant_id' => $actor->tenantId, ':id' => $orderId]);

        return $result;
    }

    /**
     * Print a goods received note for one receipt.
     *
     * @return array{document_id: int, number: string|null, title: string, replayed: bool, pdf: array{bytes: string, content_type: string}|null}
     * @throws DocumentIssueRefused|InventoryRejectedException
     */
    public function printReceipt(ActingAs $actor, int $operationId, string $lang): array
    {
        $stmt = $this->db->prepare("SELECT id FROM inv_operations WHERE tenant_id = :tenant_id AND id = :id AND kind = 'receipt'");
        $stmt->execute([':tenant_id' => $actor->tenantId, ':id' => $operationId]);
        if ($stmt->fetchColumn() === false) {
            throw InventoryRejectedException::notFound('Receipt');
        }

        return $this->issue(
            $actor,
            InventoryStarters::GOODS_RECEIVED,
            $lang,
            ReceiptFieldProvider::RECORD_TYPE . ':' . $operationId,
            'inventory/goods-received/' . $operationId,
        );
    }

    /**
     * @return array{document_id: int, number: string|null, title: string, replayed: bool, pdf: array{bytes: string, content_type: string}|null}
     */
    private function issue(ActingAs $actor, string $document, string $lang, string $record, string $originKey): array
    {
        $sourceId = $this->templateId($actor->tenantId, $document, $lang);
        // The template's published version is part of what the paper is: a
        // template someone changed prints a new document, not the old one again.
        $stmt = $this->db->prepare('SELECT published_version FROM document_sources WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $actor->tenantId, ':id' => $sourceId]);
        $version = (int) $stmt->fetchColumn();
        $originKey .= '/' . $sourceId . '.' . $version;
        $issued = ($this->issuing)()->issue($actor, new IssueInput(sourceId: $sourceId, record: $record, originKey: $originKey));
        $doc = $issued['document'];
        $id = (int) $doc['id'];
        $number = $doc['number'] ?? $doc['document_number'] ?? null;

        return [
            'document_id' => $id,
            'number' => is_string($number) && $number !== '' ? $number : null,
            'title' => (string) ($doc['title'] ?? ''),
            'replayed' => $issued['replayed'],
            'pdf' => $this->pdf !== null ? ($this->pdf)($actor->tenantId, $id) : null,
        ];
    }
}
