<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;
use Whity\Core\Document\Fields\DocumentFieldProvider;
use Whity\Core\Document\Fields\FieldLabels;
use Whity\Core\RBAC\CorePermissions;

/**
 * A form's submissions as a record type for document templates (WHIT-921,
 * #1725), so a template can be written for "a supplier request" and print its
 * answers. That is what a route's finalise step renders a submission with.
 *
 * One record type per form, `form.<form key>`, supplied per workspace through
 * {@see \Whity\Core\Document\Fields\DocumentFieldRegistry::addTenantSource()}.
 * Its fields are `submission.<field key>` for each question the applicant
 * answers, and five about the submission itself: its reference, who sent it,
 * when, and the version and SHA-256 of the answers the final document was made
 * from.
 *
 * Previewing a template with a real submission needs `forms:read`, which reads
 * every submission.
 *
 * @i18n-keys documents
 *   fields.submission.reference = Reference
 *   fields.submission.sentBy = Sent by
 *   fields.submission.sentOn = Sent on
 *   fields.submission.version = Version approved
 *   fields.submission.contentHash = SHA-256 of the answers approved
 */
final class FormSubmissionFieldProvider implements DocumentFieldProvider
{
    public const REFERENCE = 'submission.reference';
    public const SENT_BY = 'submission.sent_by';
    public const SENT_ON = 'submission.sent_on';
    public const VERSION = 'submission.version';
    public const CONTENT_HASH = 'submission.content_hash';

    /** How many recent submissions the record picker searches. */
    private const SEARCH_WINDOW = 200;

    private FieldLabels $labels;

    /**
     * @param array<string, mixed> $form A normalized `forms` row.
     */
    public function __construct(
        private readonly PDO $db,
        private readonly array $form,
        ?FieldLabels $labels = null,
    ) {
        $this->labels = $labels ?? new FieldLabels();
    }

    /**
     * One provider per form in the workspace.
     *
     * @return list<self>
     */
    public static function forTenant(PDO $db, int $tenantId, ?FieldLabels $labels = null): array
    {
        $out = [];
        foreach ((new FormRepository($db))->listForTenant($tenantId, null, 200) as $form) {
            $out[] = new self($db, $form, $labels);
        }

        return $out;
    }

    public static function recordTypeFor(string $formKey): string
    {
        return 'form.' . $formKey;
    }

    public static function path(string $fieldKey): string
    {
        return 'submission.' . $fieldKey;
    }

    public function recordType(): string
    {
        return self::recordTypeFor((string) $this->form['form_key']);
    }

    public function recordLabel(): string
    {
        /** @var array<string, string> $name */
        $name = is_array($this->form['name'] ?? null) ? $this->form['name'] : [];
        $label = LocalizedLabel::preferred($name);

        return $label !== '' ? $label : (string) $this->form['form_key'];
    }

    public function groups(int $tenantId): array
    {
        $items = [];
        foreach ($this->fields($tenantId) as $field) {
            /** @var array<string, string> $label */
            $label = is_array($field['label'] ?? null) ? $field['label'] : [];
            $text = LocalizedLabel::preferred($label);
            $items[] = [
                'path' => self::path((string) $field['field_key']),
                'label' => $text !== '' ? $text : (string) $field['field_key'],
            ];
        }
        $items[] = ['path' => self::REFERENCE, 'label' => $this->labels->get('fields.submission.reference', 'Reference'), 'sample' => 'R-2026-0042'];
        $items[] = ['path' => self::SENT_BY, 'label' => $this->labels->get('fields.submission.sentBy', 'Sent by'), 'sample' => 'Huda Saleh'];
        $items[] = ['path' => self::SENT_ON, 'label' => $this->labels->get('fields.submission.sentOn', 'Sent on'), 'sample' => '2026-11-08'];
        $items[] = ['path' => self::VERSION, 'label' => $this->labels->get('fields.submission.version', 'Version approved'), 'sample' => '2'];
        $items[] = ['path' => self::CONTENT_HASH, 'label' => $this->labels->get('fields.submission.contentHash', 'SHA-256 of the answers approved')];

        return [['group' => $this->recordLabel(), 'items' => $items]];
    }

    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): ?array
    {
        if ($recordId === null || !ctype_digit($recordId) || !$hasPermission(CorePermissions::FORMS_READ)) {
            return null;
        }
        $submission = (new FormSubmissionRepository($this->db))->find($tenantId, (int) $recordId);
        if ($submission === null || (int) $submission['form_id'] !== (int) $this->form['id']) {
            return null;
        }

        $subject = new FormSubmissionRouteSubject($this->db);
        $snapshot = $subject->snapshot($tenantId, (int) $recordId) ?? [];
        $values = $subject->finalValues($tenantId, (int) $recordId, [
            'version' => 0,
            'content_hash' => '',
            'data' => $snapshot,
        ], 'en');
        // A preview is not a final document: no version has been approved.
        $values[self::VERSION] = null;
        $values[self::CONTENT_HASH] = null;

        return ['label' => $this->name($submission), 'values' => $values];
    }

    public function records(int $tenantId, int $callerId, callable $hasPermission, string $query, int $limit): array
    {
        if (!$hasPermission(CorePermissions::FORMS_READ)) {
            return [];
        }
        $rows = (new FormSubmissionRepository($this->db))->listForTenant($tenantId, (int) $this->form['id'], null, self::SEARCH_WINDOW, 0);
        $q = mb_strtolower(trim($query));
        $out = [];
        foreach ($rows as $row) {
            $label = $this->name($row);
            if ($q !== '' && !str_contains(mb_strtolower($label), $q)) {
                continue;
            }
            $out[] = ['id' => (string) $row['id'], 'label' => $label];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $submission */
    private function name(array $submission): string
    {
        $parts = [$submission['reference'] ?? null, $submission['submitted_by_name'] ?? null, substr((string) $submission['submitted_at'], 0, 10)];

        return implode(' · ', array_filter($parts, static fn (mixed $p): bool => is_string($p) && $p !== ''));
    }

    /** @return list<array<string, mixed>> */
    private function fields(int $tenantId): array
    {
        return FormFieldRepository::applicantFields(
            (new FormFieldRepository($this->db))->listForForm($tenantId, (int) $this->form['id'])
        );
    }
}
