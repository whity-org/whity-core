<?php

declare(strict_types=1);

namespace Whity\Core\Form\Print;

use PDO;
use Whity\Core\Document\Print\SourceDocumentPrinter;
use Whity\Core\Document\Routing\RouteApprovals;
use Whity\Core\Document\Routing\RouteLeftOut;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepValueRepository;
use Whity\Core\Document\Theme\FormPrintStyle;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\FormSubmissionReviewRepository;
use Whity\Core\Form\FormSubmissionRouteSubject;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Everything {@see FormPrintout} needs for one submission, read from the
 * workspace (design 3.4, P1): the form's sections and questions, the answers,
 * the office's answers, the workspace's letterhead and theme with the plan
 * gate applied, and the route's decision, approvals and the people it left
 * out.
 *
 * The plan gate (`documents.form_printouts`) is read here, each time a payload
 * is made: a printout made after a trial ends uses the standard layout, and
 * one made before is a stored PDF that never changes (FP.2).
 */
final class FormPrintoutService
{
    /** Route values a reviewer may enter that the decision block prints (WHIT-1016). */
    private const CONDITIONS_KEYS = ['conditions', 'approval_conditions'];

    public function __construct(
        private readonly PDO $db,
        private readonly SourceDocumentPrinter $print,
        private readonly ?EntitlementService $entitlements = null,
        private readonly ?SettingsService $settings = null,
    ) {
    }

    /**
     * Whether this workspace may customise its form printouts (FP.2).
     */
    public function customAllowed(int $tenantId): bool
    {
        return $this->entitlements?->isGranted($tenantId, EntitlementRegistry::DOCUMENTS_FORM_PRINTOUTS) ?? true;
    }

    /**
     * The printout's payload for Whity Render, or null when the submission
     * isn't this workspace's.
     *
     * @param array{
     *   lang?: ?string,
     *   answers?: ?array<string, mixed>,
     *   routeId?: ?int,
     *   approved?: bool,
     *   verify?: ?array{url: string, reference?: ?string},
     *   theme?: ?array<string, mixed>,
     *   preview?: bool,
     * } $options `answers` are the approved version's when a route finishes;
     *            `theme` the tokens to use instead of the workspace's (a preview
     *            of unsaved settings).
     * @return array<string, mixed>|null
     */
    public function payload(int $tenantId, int $submissionId, array $options = []): ?array
    {
        $submission = (new FormSubmissionRepository($this->db))->find($tenantId, $submissionId);
        if ($submission === null) {
            return null;
        }
        $form = (new FormRepository($this->db))->find($tenantId, (int) $submission['form_id']);
        if ($form === null) {
            return null;
        }
        $fields = (new FormFieldRepository($this->db))->listForForm($tenantId, (int) $form['id']);
        $answers = $options['answers'] ?? (is_array($submission['data']) ? $submission['data'] : []);
        $lang = $options['lang'] ?? $this->language($tenantId, $submission['submitted_by_profile_id'] ?? null);

        $resolved = $this->print->theme($tenantId, null, null);
        $tokens = $resolved['tokens'];
        if (is_array($options['theme'] ?? null)) {
            $tokens = array_replace($tokens, $options['theme']);
        }
        $style = FormPrintStyle::resolve(is_array($tokens['form'] ?? null) ? $tokens['form'] : null, $this->customAllowed($tenantId));
        $letterhead = $this->print->printedLetterhead($tenantId, $tokens);
        $routeId = $options['routeId'] ?? $this->routeId($tenantId, $submissionId);
        $arabic = $this->arabic($tenantId);

        /** @phpstan-ignore argument.type (the shapes are checked where they are built) */
        return (new FormPrintout())->build([
            'lang' => (string) $lang,
            'style' => $style,
            'theme' => $tokens,
            'workspace' => [
                'name' => (string) $letterhead['name'],
                'lines' => $letterhead['lines'],
                'logo' => $letterhead['logo'] ?? null,
                'nameAr' => $arabic[0],
                'linesAr' => $arabic[1],
            ],
            'form' => [
                'title' => $form['title'] ?? $form['name'] ?? '',
                'description' => $form['description'] ?? null,
                'sections' => is_array($form['sections'] ?? null) ? $form['sections'] : [],
                'fields' => $fields,
            ],
            'answers' => $answers,
            'office' => $this->office($tenantId, $submissionId),
            'submission' => [
                'reference' => isset($submission['reference']) ? (string) $submission['reference'] : null,
                'submittedAt' => isset($submission['submitted_at']) ? (string) $submission['submitted_at'] : null,
                'submittedBy' => isset($submission['submitted_by_name']) ? (string) $submission['submitted_by_name'] : null,
            ],
            'decision' => $routeId === null ? null : $this->decision($tenantId, $routeId, (string) ($submission['state'] ?? ''), ($options['approved'] ?? false) === true, $fields, (string) $lang),
            'verify' => $options['verify'] ?? null,
            'marks' => $style['marks'],
            'names' => fn (string $type, int $id): ?string => $this->name($tenantId, $type, $id),
        ]);
    }

    /** The newest route on the submission. */
    private function routeId(int $tenantId, int $submissionId): ?int
    {
        $routes = (new RouteRepository($this->db))->listForSubject(FormSubmissionRouteSubject::TYPE, $submissionId, $tenantId);

        return $routes === [] ? null : (int) $routes[0]['id'];
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array{status: string, decidedAt: ?string, approvals: list<array{name: string, role: ?string, at: string}>, notCounted: list<array{name: string, question: ?string}>, conditions?: string}
     */
    private function decision(int $tenantId, int $routeId, string $state, bool $approved, array $fields, string $lang): array
    {
        $labels = new FormPrintLabels($lang);
        $questions = [];
        foreach ($fields as $field) {
            $questions[(string) $field['field_key']] = $labels->pick($field['label'] ?? null);
        }
        $status = $approved ? 'approved' : match ($state) {
            'completed' => 'approved',
            'not approved' => 'rejected',
            'sent back' => 'revisions',
            default => 'waiting',
        };
        $approvals = [];
        $last = null;
        foreach ((new RouteApprovals($this->db))->forRoute($tenantId, $routeId) as $a) {
            $approvals[] = ['name' => $a['name'] !== '' ? $a['name'] : '—', 'role' => $a['step'], 'at' => $a['at']];
            $last = $a['at'];
        }
        $values = (new RouteStepValueRepository($this->db))->valuesForRoute($tenantId, $routeId);
        $decision = [
            'status' => $status,
            'decidedAt' => $status === 'approved' || $status === 'rejected' ? ($last ?? gmdate('Y-m-d H:i:s')) : null,
            'approvals' => $approvals,
            'notCounted' => array_map(
                static fn (array $p): array => ['name' => $p['name'], 'question' => $questions[$p['key']] ?? null],
                (new RouteLeftOut($this->db))->forRoute($tenantId, $routeId),
            ),
        ];
        foreach (self::CONDITIONS_KEYS as $key) {
            if (trim((string) ($values[$key] ?? '')) !== '') {
                $decision['conditions'] = trim((string) $values[$key]);
                break;
            }
        }

        return $decision;
    }

    /**
     * The office's answers, the latest for each question, with who gave it.
     *
     * @return array<string, array{value: mixed, by: ?string, at: ?string}>
     */
    private function office(int $tenantId, int $submissionId): array
    {
        $out = [];
        foreach ((new FormSubmissionReviewRepository($this->db))->listForSubmission($tenantId, $submissionId) as $review) {
            $data = is_array($review['data']) ? $review['data'] : [];
            foreach ($data as $key => $value) {
                if (!isset($out[(string) $key])) {
                    $by = $review['recorded_by_profile_id'] !== null ? $this->name($tenantId, 'profile', (int) $review['recorded_by_profile_id']) : null;
                    $out[(string) $key] = ['value' => $value, 'by' => $by, 'at' => (string) $review['recorded_at']];
                }
            }
        }

        return $out;
    }

    private function name(int $tenantId, string $type, int $id): ?string
    {
        $sql = $type === 'profile'
            ? 'SELECT display_name FROM profiles WHERE id = :id'
            : 'SELECT name FROM organizational_units WHERE id = :id AND tenant_id = :tenant_id';
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($type === 'profile' ? [':id' => $id] : [':id' => $id, ':tenant_id' => $tenantId]);
            $name = $stmt->fetchColumn();
        } catch (\PDOException) {
            return null;
        }

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** The submitter's language when it is Arabic, else English. */
    private function language(int $tenantId, mixed $profileId): string
    {
        if (!is_int($profileId) && !(is_string($profileId) && ctype_digit($profileId))) {
            return 'en';
        }
        try {
            $stmt = $this->db->prepare('SELECT language_code FROM profiles WHERE id = :id');
            $stmt->execute([':id' => (int) $profileId]);
            $code = $stmt->fetchColumn();
        } catch (\PDOException) {
            return 'en';
        }

        return is_string($code) && str_starts_with($code, 'ar') ? 'ar' : 'en';
    }

    /**
     * The workspace's name and address in Arabic, for the bilingual letterhead.
     *
     * @return array{0: ?string, 1: list<string>}
     */
    private function arabic(int $tenantId): array
    {
        $effective = $this->settings?->effective($tenantId) ?? [];
        $name = trim((string) ($effective[SettingsRegistry::COMPANY_NAME_AR] ?? ''));
        $address = trim((string) ($effective[SettingsRegistry::COMPANY_ADDRESS_AR] ?? ''));

        return [$name !== '' ? $name : null, $address !== '' ? [preg_replace('/\s*\R\s*/u', '، ', $address) ?? $address] : []];
    }
}
