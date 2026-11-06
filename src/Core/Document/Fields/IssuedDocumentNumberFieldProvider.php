<?php

declare(strict_types=1);

namespace Whity\Core\Document\Fields;

use Whity\Core\Document\Issuing\DocumentIssuing;

/**
 * The number an issued document carries, offered to every template so it prints
 * (WHIT-684). It resolves to nothing here on purpose: the number isn't known until
 * the record is raised, and on a final-approval rule not until much later, so
 * {@see DocumentIssuing} fills it from the record for each print. A preview shows
 * the sample.
 *
 * Not the `document.number` field of {@see IssuedDocumentFieldProvider}: that one
 * belongs to a template written ABOUT another document (a cover letter), and is that
 * document's id.
 */
final class IssuedDocumentNumberFieldProvider implements DocumentFieldProvider
{
    private FieldLabels $labels;

    public function __construct(?FieldLabels $labels = null)
    {
        $this->labels = $labels ?? new FieldLabels();
    }

    public function recordType(): string
    {
        return '*';
    }

    public function recordLabel(): string
    {
        return $this->labels->get('fields.group.issuedDocument', 'This document');
    }

    public function groups(int $tenantId): array
    {
        return [[
            'group' => $this->recordLabel(),
            'items' => [[
                'path' => DocumentIssuing::NUMBER_FIELD,
                'label' => $this->labels->get('fields.issuedDocument.number', 'This document\'s number'),
                'sample' => 'INV-2026-0142',
            ], [
                'path' => DocumentIssuing::REVISION_FIELD,
                'label' => $this->labels->get('fields.issuedDocument.revision', 'This document\'s revision'),
                'sample' => 'A',
            ]],
        ]];
    }

    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): array
    {
        return ['label' => '', 'values' => []];
    }

    public function records(int $tenantId, int $callerId, callable $hasPermission, string $query, int $limit): array
    {
        return [];
    }
}
