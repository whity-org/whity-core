<?php

declare(strict_types=1);

namespace Whity\Core\Form\Print;

use PDO;
use Whity\Core\Document\Print\SourceDocumentPrinter;
use Whity\Core\Document\Routing\RouteApprovals;
use Whity\Core\Document\Routing\RouteLeftOut;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepInputs;
use Whity\Core\Document\Routing\RouteStepKind;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteStepValueRepository;
use Whity\Core\Document\Theme\FormPrintStyle;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
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
     *   certificate?: bool,
     * } $options `answers` are the approved version's when a route finishes;
     *            `theme` the tokens to use instead of the workspace's (a preview
     *            of unsaved settings); `certificate` prints the approval
     *            certificate in front when the route approved it (a final
     *            document).
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
            'office' => $routeId === null ? [] : $this->office($tenantId, $routeId, (string) $lang),
            'submission' => [
                'reference' => isset($submission['reference']) ? (string) $submission['reference'] : null,
                'submittedAt' => isset($submission['submitted_at']) ? (string) $submission['submitted_at'] : null,
                'submittedBy' => isset($submission['submitted_by_name']) ? (string) $submission['submitted_by_name'] : null,
            ],
            'decision' => $routeId === null ? null : $this->decision($tenantId, $routeId, (string) ($submission['state'] ?? ''), ($options['approved'] ?? false) === true, $fields, (string) $lang),
            'verify' => $options['verify'] ?? null,
            'marks' => $style['marks'],
            'names' => fn (string $type, int $id): ?string => $this->name($tenantId, $type, $id),
            'timezone' => $this->timezone($tenantId),
            'certificate' => ($options['certificate'] ?? false) === true && $routeId !== null ? $this->certificate($tenantId, $routeId) : null,
        ]);
    }

    /**
     * The certificate's options, from the route's final document stage
     * (`rule_config.certificate`); every option has a default, so a route
     * whose stage sets none still prints one. Options that no longer pass
     * the check are dropped rather than costing the document.
     *
     * @return array<string, mixed>
     */
    private function certificate(int $tenantId, int $routeId): array
    {
        foreach ((new RouteStepRepository($this->db))->listForRoute($routeId, $tenantId) as $step) {
            if (($step['kind'] ?? null) !== RouteStepKind::FINALISE) {
                continue;
            }
            $config = is_array($step['rule_config'] ?? null) ? $step['rule_config'] : [];
            try {
                return ApprovalCertificate::config($config['certificate'] ?? null);
            } catch (\InvalidArgumentException) {
                return [];
            }
        }

        return [];
    }

    /** The workspace's time zone: printed moments are in its time (setting `timezone`). */
    private function timezone(int $tenantId): ?string
    {
        $zone = $this->settings?->effective($tenantId)[SettingsRegistry::TIMEZONE] ?? null;

        return is_string($zone) && $zone !== '' ? $zone : null;
    }

    /** The newest route on the submission. */
    private function routeId(int $tenantId, int $submissionId): ?int
    {
        $routes = (new RouteRepository($this->db))->listForSubject(FormSubmissionRouteSubject::TYPE, $submissionId, $tenantId);

        return $routes === [] ? null : (int) $routes[0]['id'];
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array{status: string, decidedAt: ?string, approvals: list<array{name: string, role: ?string, at: string, outcome?: string}>, notCounted: list<array{name: string, question: ?string}>, conditions?: string, word?: string}
     */
    private function decision(int $tenantId, int $routeId, string $state, bool $approved, array $fields, string $lang): array
    {
        $labels = new FormPrintLabels($lang);
        $questions = [];
        foreach ($fields as $field) {
            $questions[(string) $field['field_key']] = $labels->pick($field['label'] ?? null);
        }
        // Merge map A3: a person the office named at a step (the supervisor).
        foreach ($this->stepInputs($tenantId, $routeId) as $key => $input) {
            $questions[$key] ??= $labels->pick(array_filter(['en' => (string) $input['label'], 'ar' => (string) ($input['label_ar'] ?? '')], static fn (string $l): bool => $l !== ''));
        }
        $status = $approved ? 'approved' : match ($state) {
            'completed' => 'approved',
            'not approved' => 'rejected',
            'sent back' => 'revisions',
            default => 'waiting',
        };
        $values = (new RouteStepValueRepository($this->db))->valuesForRoute($tenantId, $routeId);
        // What each stage decided, when it picked a decision or a
        // recommendation from a list ("Recommend approval"): the
        // certificate's approvals print it.
        $outcomes = [];
        foreach ((new RouteStepRepository($this->db))->listForRoute($routeId, $tenantId) as $step) {
            foreach (RouteStepInputs::decode($step['inputs'] ?? []) as $input) {
                $chosen = $values[$input['key']] ?? null;
                if ($input['type'] === RouteStepInputs::TYPE_CHOICE && (self::isDecisionKey($input['key']) || str_ends_with($input['key'], 'recommendation')) && is_string($chosen) && $chosen !== '') {
                    $outcomes[(int) $step['position']] = RouteStepInputs::display($input, $chosen, $lang);
                }
            }
        }
        $approvals = [];
        $last = null;
        foreach ((new RouteApprovals($this->db))->forRoute($tenantId, $routeId) as $a) {
            $approvals[] = ['name' => $a['name'] !== '' ? $a['name'] : '—', 'role' => $a['step'], 'at' => $a['at']]
                + (isset($outcomes[$a['position']]) ? ['outcome' => $outcomes[$a['position']]] : []);
            $last = $a['at'];
        }
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
        // WHIT-1164: a stage's decision picked from a list (WHIT-1163), such as
        // "Conditionally approved", is the word the printout carries. Only once
        // the route has decided: a choice made before then is not the outcome.
        if (in_array($status, ['approved', 'rejected'], true)) {
            foreach ($this->stepInputs($tenantId, $routeId) as $key => $input) {
                $chosen = $values[$key] ?? null;
                if (($input['type'] ?? null) !== RouteStepInputs::TYPE_CHOICE || !self::isDecisionKey($key) || !is_string($chosen) || $chosen === '') {
                    continue;
                }
                $decision['word'] = RouteStepInputs::display($input, $chosen, $lang);
                $decision['status'] = self::statusOf($chosen, $status);
            }
        }

        return $decision;
    }

    /**
     * "For official use" (merge map A3): what the office entered at the route's
     * steps, the latest for each value, with who entered it and when. A person
     * prints as their name. The decision's conditions print with the decision.
     *
     * @return list<array{label: array<string, string>, value: string, by: ?string, at: ?string, stage: string}>
     */
    private function office(int $tenantId, int $routeId, string $lang = 'en'): array
    {
        $labels = $this->stepInputs($tenantId, $routeId);
        $stages = [];
        foreach ((new RouteStepRepository($this->db))->listForRoute($routeId, $tenantId) as $step) {
            $stages[(int) $step['id']] = ['label' => trim((string) ($step['label'] ?? '')), 'position' => (int) $step['position']];
        }
        $out = [];
        foreach ((new RouteStepValueRepository($this->db))->currentForRoute($tenantId, $routeId) as $key => $entry) {
            if (in_array($key, self::CONDITIONS_KEYS, true) || trim($entry['value']) === '') {
                continue;
            }
            $input = $labels[$key] ?? null;
            $value = $entry['value'];
            if (($input['type'] ?? RouteStepInputs::TYPE_TEXT) === RouteStepInputs::TYPE_PERSON && ctype_digit($value)) {
                $value = $this->name($tenantId, 'profile', (int) $value) ?? '—';
            } elseif ($input !== null) {
                $value = RouteStepInputs::display($input, $value, $lang);
            }
            $out[] = [
                'label' => array_filter(['en' => (string) ($input['label'] ?? $key), 'ar' => (string) ($input['label_ar'] ?? '')], static fn (string $l): bool => $l !== ''),
                'value' => $value,
                'by' => $entry['entered_by'] !== null ? $this->name($tenantId, 'profile', $entry['entered_by']) : null,
                'at' => $entry['entered_at'],
                'stage' => $stages[$entry['step_id']]['label'] ?? '',
                'position' => $stages[$entry['step_id']]['position'] ?? 0,
            ];
        }
        // In the order the stages come, so the faculty prints before the dean.
        usort($out, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return array_map(static function (array $e): array {
            unset($e['position']);

            return $e;
        }, $out);
    }

    /** A stage value that records the decision: `decision`, or a key ending `_decision`. */
    public static function isDecisionKey(string $key): bool
    {
        return $key === 'decision' || str_ends_with($key, '_decision');
    }

    /**
     * The printed status of a decision picked from a list, read from the
     * option's value: approved, conditionally approved, revisions required or
     * not approved. Anything else keeps the route's own outcome.
     */
    public static function statusOf(string $value, string $fallback): string
    {
        return match (true) {
            str_contains($value, 'condition') => 'conditional',
            str_contains($value, 'revision') => 'revisions',
            str_starts_with($value, 'not_') || str_contains($value, 'reject') => 'rejected',
            $value === 'approved' || str_starts_with($value, 'approve') => 'approved',
            default => $fallback,
        };
    }

    /**
     * The values the route's steps ask for, by key.
     *
     * @return array<string, array<string, mixed>>
     */
    private function stepInputs(int $tenantId, int $routeId): array
    {
        $out = [];
        foreach ((new RouteStepRepository($this->db))->listForRoute($routeId, $tenantId) as $step) {
            foreach (RouteStepInputs::decode($step['inputs'] ?? []) as $input) {
                $out[$input['key']] = $input;
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
