<?php

declare(strict_types=1);

namespace Whity\Core\Sales\Print;

use PDO;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Issuing\ActingAs;
use Whity\Core\Document\Issuing\DocumentIssueRefused;
use Whity\Core\Document\Issuing\IssueInput;
use Whity\Core\Document\Issuing\TemplateIssuing;
use Whity\Core\Sales\SalesRejectedException;

/**
 * Printing quotes (design section 34 §3.5, WHIT-1341), the way inventory
 * prints its purchase orders ({@see \Whity\Core\Inventory\Print\InventoryDocuments}).
 *
 * A quote prints from the workspace's template, seeded and published the
 * first time a quote prints ({@see QuoteStarter}); from then on it is the
 * workspace's to change in the editor. Each copy is issued through the one
 * issuing path as the person printing, so it is numbered, kept and verifiable.
 *
 * When a quote is sent, or given a new link, a copy is issued in each
 * language, carrying the link and its QR (QT.5); the one in the workspace's
 * language becomes the quote's printed copy (`sales_quotes.document_id`).
 * Printing again returns the copy in the language asked for, which never
 * changes (FP rules); a quote that has not gone out yet prints a working copy
 * without the QR, made again when the draft changes.
 */
final class QuoteDocuments
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

    /** The workspace's quote template in a language, seeded and published when it has none. */
    public function templateId(int $tenantId, string $lang): int
    {
        $lang = $lang === 'ar' ? 'ar' : 'en';
        $key = QuoteStarter::key($lang);
        $stmt = $this->db->prepare(
            'SELECT id FROM document_sources WHERE tenant_id = :tenant_id AND starter_key = :starter_key ORDER BY id LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':starter_key' => $key]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $starter = (new QuoteStarter())->build($lang);
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
                throw new \RuntimeException('The quote template could not be published');
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

    /** @return array{en: int, ar: int} the template ids, for the screens that open one to change */
    public function templates(int $tenantId): array
    {
        return ['en' => $this->templateId($tenantId, 'en'), 'ar' => $this->templateId($tenantId, 'ar')];
    }

    /**
     * Issue the copy that goes out with the quote, carrying its public link
     * and QR, and keep it as the quote's printed copy.
     *
     * @return array{document_id: int, number: string|null, title: string, replayed: bool, pdf: array{bytes: string, content_type: string}|null}
     * @throws DocumentIssueRefused|SalesRejectedException
     */
    public function issueSent(ActingAs $actor, int $quoteId, string $lang, string $link): array
    {
        $quote = $this->quote($actor->tenantId, $quoteId);
        $lang = $lang === 'ar' ? 'ar' : 'en';
        // One copy per link and language: a new link prints new copies, the old ones' QR no longer opens.
        $origin = self::sentOrigin($quoteId) . substr(hash('sha256', $link), 0, 16);
        $result = QuoteFieldProvider::withLink($actor->tenantId, $quoteId, $link, fn (): array => $this->issue($actor, $lang, $quoteId, $origin));
        $other = $lang === 'ar' ? 'en' : 'ar';
        try {
            QuoteFieldProvider::withLink($actor->tenantId, $quoteId, $link, fn (): array => $this->issue($actor, $other, $quoteId, $origin));
        } catch (\Throwable $e) {
            // The copy in the other language is a convenience; Print makes a working one without it.
            error_log('[QuoteDocuments] the ' . $other . ' copy of quote ' . $quoteId . ' could not be made: ' . $e->getMessage());
        }
        $this->db->prepare('UPDATE sales_quotes SET document_id = :document_id WHERE tenant_id = :tenant_id AND id = :id')
            ->execute([':document_id' => $result['document_id'], ':tenant_id' => $actor->tenantId, ':id' => $quote['id']]);

        return $result;
    }

    /**
     * Print a quote: the copy it went out as, or a working copy while it is a draft.
     *
     * @return array{document_id: int, number: string|null, title: string, replayed: bool, pdf: array{bytes: string, content_type: string}|null}
     * @throws DocumentIssueRefused|SalesRejectedException
     */
    public function print(ActingAs $actor, int $quoteId, string $lang): array
    {
        $quote = $this->quote($actor->tenantId, $quoteId);
        $kept = $quote['document_id'] !== null ? (int) $quote['document_id'] : null;
        if ($kept !== null && !in_array($quote['status'], ['draft', 'waiting_approval', 'ready'], true)) {
            // The copy that went out in this language, when there is one.
            $stmt = $this->db->prepare(
                'SELECT id FROM documents WHERE tenant_id = :tenant_id AND record_type = :record_type AND record_id = :record_id
                    AND document_source_id = :source AND origin_key LIKE :origin ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([
                ':tenant_id' => $actor->tenantId,
                ':record_type' => QuoteFieldProvider::RECORD_TYPE,
                ':record_id' => (string) $quoteId,
                ':source' => $this->templateId($actor->tenantId, $lang),
                ':origin' => self::sentOrigin($quoteId) . '%',
            ]);
            $inLanguage = $stmt->fetchColumn();
            if ($inLanguage !== false) {
                $kept = (int) $inLanguage;
            }
            $stmt = $this->db->prepare('SELECT id, title, number FROM documents WHERE tenant_id = :tenant_id AND id = :id');
            $stmt->execute([':tenant_id' => $actor->tenantId, ':id' => $kept]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($doc)) {
                $number = $doc['number'] ?? null;

                return [
                    'document_id' => $kept,
                    'number' => is_string($number) && $number !== '' ? $number : null,
                    'title' => (string) ($doc['title'] ?? ''),
                    'replayed' => true,
                    'pdf' => $this->pdf !== null ? ($this->pdf)($actor->tenantId, $kept) : null,
                ];
            }
        }
        $changed = strtotime((string) $quote['updated_at']);

        return $this->issue($actor, $lang, $quoteId, 'sales/quote/' . $quoteId . '/copy/' . ($changed !== false ? $changed : 0));
    }

    /** The start of the origin key of a copy issued with the quote's link. */
    private static function sentOrigin(int $quoteId): string
    {
        return 'sales/quote/' . $quoteId . '/link/';
    }

    /** @return array{id: int, status: string, document_id: int|string|null, updated_at: string} */
    private function quote(int $tenantId, int $quoteId): array
    {
        $stmt = $this->db->prepare('SELECT id, status, document_id, updated_at FROM sales_quotes WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $quoteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw SalesRejectedException::notFound('Quote');
        }

        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'document_id' => $row['document_id'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @return array{document_id: int, number: string|null, title: string, replayed: bool, pdf: array{bytes: string, content_type: string}|null}
     */
    private function issue(ActingAs $actor, string $lang, int $quoteId, string $originKey): array
    {
        $sourceId = $this->templateId($actor->tenantId, $lang);
        // The template's published version is part of what the paper is.
        $stmt = $this->db->prepare('SELECT published_version FROM document_sources WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $actor->tenantId, ':id' => $sourceId]);
        $version = (int) $stmt->fetchColumn();
        $originKey .= '/' . $sourceId . '.' . $version;
        $issued = ($this->issuing)()->issue($actor, new IssueInput(
            sourceId: $sourceId,
            record: QuoteFieldProvider::RECORD_TYPE . ':' . $quoteId,
            originKey: $originKey,
        ));
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
