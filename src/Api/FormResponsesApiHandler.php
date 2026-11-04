<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Audit\AuditLogger;
use Whity\Core\Form\FieldType;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\LocalizedLabel;
use Whity\Core\Form\ResponsesCsv;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * What a form's responses add up to (F.4): the summary and the CSV export. Both on
 * `forms:read`, the permission that already reads every one of these rows, and both
 * bound to the caller's tenant through the form they name. Staff answers appear in
 * neither.
 *
 * An export is the one way these answers leave the system whole, so each one goes in
 * the audit log with who took it and how many rows.
 */
final class FormResponsesApiHandler
{
    /** How many weeks the "responses per week" series looks back. */
    private const WEEKS = 12;

    private const PAGE = 200;

    public function __construct(
        private readonly FormRepository $forms,
        private readonly FormFieldRepository $fields,
        private readonly FormSubmissionRepository $submissions,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * GET /api/v1/forms/{id}/responses/summary — a count per choice for each choice
     * question, responses per week, and where responses are in their routing. Text and
     * file questions aren't summarised.
     *
     * @param array<string, string> $params
     */
    public function summary(Request $request, array $params): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }
            $formId = (int) ($params['id'] ?? 0);
            if ($this->forms->find($tenantId, $formId) === null) {
                return Response::error('Form not found', 404);
            }

            $fields = FormFieldRepository::applicantFields($this->fields->listForForm($tenantId, $formId));

            $questions = [];
            foreach ($fields as $field) {
                $type = (string) $field['field_type'];
                if (in_array($type, [FieldType::SELECT, FieldType::MULTISELECT, FieldType::CHECKBOX], true)) {
                    $questions[(string) $field['field_key']] = ['field' => $field, 'answered' => 0, 'counts' => []];
                }
            }

            $total = 0;
            $weeks = [];
            $routing = ['not routed' => 0, 'in progress' => 0, 'completed' => 0, 'withdrawn' => 0];

            foreach ($this->rows($tenantId, $formId) as $row) {
                $total++;
                $state = (string) ($row['state'] ?? 'not routed');
                $routing[$state] = ($routing[$state] ?? 0) + 1;

                $week = self::weekOf((string) ($row['submitted_at'] ?? ''));
                if ($week !== null) {
                    $weeks[$week] = ($weeks[$week] ?? 0) + 1;
                }

                // Still a response received, but its author no longer stands behind these answers.
                if ($state === 'withdrawn') {
                    continue;
                }

                $data = is_array($row['data'] ?? null) ? $row['data'] : [];
                foreach ($questions as $key => $question) {
                    if (!array_key_exists($key, $data)) {
                        continue;
                    }
                    $questions[$key]['answered']++;
                    foreach (is_array($data[$key]) ? $data[$key] : [$data[$key]] as $value) {
                        $choice = is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : '');
                        $questions[$key]['counts'][$choice] = ($questions[$key]['counts'][$choice] ?? 0) + 1;
                    }
                }
            }

            return Response::json(['data' => [
                'total' => $total,
                // The summary reads at most this many, newest first.
                'truncated' => $total >= ResponsesCsv::MAX_ROWS,
                'per_week' => self::weekSeries($weeks),
                'routing' => [
                    'not_routed' => $routing['not routed'],
                    'in_progress' => $routing['in progress'],
                    'completed' => $routing['completed'],
                    'withdrawn' => $routing['withdrawn'],
                ],
                'questions' => array_values(array_map([self::class, 'presentQuestion'], $questions)),
            ]]);
        } catch (\Exception $e) {
            error_log('[FormResponsesApiHandler] summary failed: ' . $e->getMessage());

            return Response::error('Failed to summarise the responses', 500);
        }
    }

    /**
     * GET /api/v1/forms/{id}/responses/export?language=ar — the responses as a CSV file.
     *
     * @param array<string, string> $params
     */
    public function export(Request $request, array $params): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }
            $formId = (int) ($params['id'] ?? 0);
            $form = $this->forms->find($tenantId, $formId);
            if ($form === null) {
                return Response::error('Form not found', 404);
            }

            $language = self::language($request);
            $fields = FormFieldRepository::applicantFields($this->fields->listForForm($tenantId, $formId));

            $rows = iterator_to_array($this->rows($tenantId, $formId), false);
            $csv = ResponsesCsv::build($fields, $rows, $language);

            $actor = $request->user;
            $this->audit?->record('forms.responses_exported', [
                'tenant_id' => $tenantId,
                'target_type' => 'form',
                'target_id' => $formId,
                'metadata' => [
                    'rows' => count($rows),
                    'profile_id' => is_object($actor) && isset($actor->profile_id) ? $actor->profile_id : null,
                ],
            ]);

            // The form key is the tenant's own slug, restricted to safe characters
            // when it was written, so it can't break out of the header.
            $filename = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) ($form['form_key'] ?? 'form')) . '-responses.csv';

            return new Response(200, $csv, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                // Sensitive by nature, so no shared cache keeps it for the next caller.
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Whity-Rows-Truncated' => count($rows) >= ResponsesCsv::MAX_ROWS ? 'true' : 'false',
            ]);
        } catch (\Exception $e) {
            error_log('[FormResponsesApiHandler] export failed: ' . $e->getMessage());

            return Response::error('Failed to export the responses', 500);
        }
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function rows(int $tenantId, int $formId): \Generator
    {
        $read = 0;
        while ($read < ResponsesCsv::MAX_ROWS) {
            $page = $this->submissions->listForTenant($tenantId, $formId, null, self::PAGE, $read);
            foreach ($page as $row) {
                yield $row;
            }
            $read += count($page);
            if (count($page) < self::PAGE) {
                return;
            }
        }
    }

    /**
     * @param array{field: array<string, mixed>, answered: int, counts: array<string, int>} $question
     * @return array<string, mixed>
     */
    private static function presentQuestion(array $question): array
    {
        $field = $question['field'];
        $labels = [];
        foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
            if (is_array($option) && is_string($option['value'] ?? null)) {
                $labels[$option['value']] = $option['label'] ?? null;
            }
        }

        $options = [];
        foreach ($question['counts'] as $value => $count) {
            $options[] = ['value' => (string) $value, 'label' => $labels[$value] ?? null, 'count' => $count];
        }
        usort($options, static fn (array $a, array $b): int => [$b['count'], $a['value']] <=> [$a['count'], $b['value']]);

        /** @var array<string, string> $label */
        $label = is_array($field['label'] ?? null) ? $field['label'] : [];

        return [
            'field_key' => (string) $field['field_key'],
            'field_type' => (string) $field['field_type'],
            'label' => $label === [] ? ['en' => LocalizedLabel::preferred($label)] : $label,
            'answered' => $question['answered'],
            'options' => $options,
        ];
    }

    private static function weekOf(string $timestamp): ?string
    {
        $time = strtotime($timestamp);

        return $time === false ? null : gmdate('o-\WW', $time);
    }

    /**
     * The last weeks in order, including the ones nobody responded in, so a chart shows
     * a quiet week rather than skipping it.
     *
     * @param array<string, int> $weeks
     * @return list<array{week: string, count: int}>
     */
    private static function weekSeries(array $weeks): array
    {
        $series = [];
        for ($ago = self::WEEKS - 1; $ago >= 0; $ago--) {
            $week = gmdate('o-\WW', time() - $ago * 7 * 86400);
            $series[] = ['week' => $week, 'count' => $weeks[$week] ?? 0];
        }

        return $series;
    }

    private static function language(Request $request): string
    {
        $query = parse_url($request->getPath(), PHP_URL_QUERY);
        $parsed = [];
        if (is_string($query)) {
            parse_str($query, $parsed);
        }
        $wanted = $parsed['language'] ?? ($_GET['language'] ?? 'en');

        return $wanted === 'ar' ? 'ar' : 'en';
    }
}
