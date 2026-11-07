<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;
use Whity\Core\Db\DbBool;
use Whity\Core\Document\Routing\DocumentRouteSubject;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteFinalisationRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepKind;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Where a submission is, as the person who SENT it sees it (WHIT-920, #1724, A9).
 *
 * The applicant sees the route's STAGES and what became of each, and the notes
 * written for them: a send-back's request, a rejection's reason, a note marked
 * for the author. They do not see internal notes, and by default they do not
 * see who the reviewers are: a stage reads "Head of department: approved", not
 * a name. `forms.show_reviewer_names` lets a workspace show the names.
 *
 * Built from the newest route on the submission (or, for a submission sent
 * before migration 233 whose route stayed on its document, that route). A read,
 * and nothing here decides anything: the trail and the open items are the
 * record.
 *
 * WHIT-921: a route that ends with a final document shows it as its last
 * stage. While the PDF is being made (or waits for the render service) it reads
 * "Final document waiting"; once it exists, `final_document.can_open` says
 * whether the applicant may open it, which the form decides.
 *
 * Stateless apart from injected handles — worker-safe.
 */
final class SubmissionProgress
{
    public const STAGE_DONE = 'done';
    public const STAGE_CURRENT = 'current';
    public const STAGE_WAITING = 'waiting';
    public const STAGE_SENT_BACK = 'sent_back';
    public const STAGE_NOT_APPROVED = 'not_approved';

    public function __construct(
        private readonly PDO $db,
        private readonly RouteRepository $routes,
        private readonly RouteStepRepository $steps,
        private readonly RouteEventRepository $events,
        private readonly RouteRecipientRepository $recipients,
        private readonly SettingsService $settings,
        // WHIT-921: what the route's finalise step did, if it has one.
        private readonly ?RouteFinalisationRepository $finalisations = null,
    ) {
    }

    /**
     * @param array<string, mixed> $submission A row from {@see FormSubmissionRepository::find()}.
     *
     * @return array{state: string, stages: list<array<string, mixed>>, notes: list<array<string, mixed>>,
     *               can_resubmit: bool, resume_from: ?string, shows_reviewers: bool,
     *               final_document: array{status: string, can_open: bool}|null}
     */
    public function forSubmission(int $tenantId, array $submission, ?int $viewerProfileId): array
    {
        $state = (string) ($submission['state'] ?? 'not routed');
        $showNames = $this->showsReviewerNames($tenantId);
        $route = $this->routeFor($tenantId, $submission);

        if ($route === null) {
            return [
                'state' => $state,
                'stages' => [],
                'notes' => [],
                'can_resubmit' => false,
                'resume_from' => null,
                'shows_reviewers' => $showNames,
                'final_document' => null,
            ];
        }

        $routeId = (int) $route['id'];
        $steps = $this->steps->listForRoute($routeId, $tenantId);
        $trail = $this->events->listForRoute($routeId, $tenantId);
        $items = $this->recipients->listForRoute($routeId, $tenantId);

        // The latest send-back, if the route is waiting for the author now.
        $openAuthorItem = null;
        $reached = [];
        $openAt = [];
        foreach ($items as $item) {
            if ($item['role'] === RouteRecipientRepository::ROLE_AUTHOR) {
                if ($item['closed_by_event_id'] === null) {
                    $openAuthorItem = $item;
                }
                continue;
            }
            $reached[(int) $item['step_id']] = true;
            if ($item['closed_by_event_id'] === null) {
                $openAt[(int) $item['step_id']] = true;
            }
        }

        $sentBackFrom = null;
        $resumeFrom = null;
        if ($openAuthorItem !== null) {
            foreach ($trail as $event) {
                if ((int) $event['id'] === (int) $openAuthorItem['created_by_event_id']) {
                    $sentBackFrom = $event['step_id'];
                    $resumeFrom = $event['resume_from'];
                }
            }
        }

        // What each stage concluded: the newest verdict given there, and who acted.
        $outcomeAt = [];
        $actorsAt = [];
        $endedBy = null;
        foreach ($trail as $event) {
            $stepId = $event['step_id'];
            if ($stepId === null || DbBool::of($event['withdrawal'] ?? false)) {
                continue;
            }
            $acted = in_array($event['action'], [
                RouteAction::ACKNOWLEDGED,
                RouteAction::FORWARDED,
                RouteAction::RETURNED,
                RouteAction::SENT_BACK,
            ], true);
            if (!$acted) {
                continue;
            }
            if ($event['verdict'] !== null) {
                $outcomeAt[$stepId] = (string) $event['verdict'];
                if ($event['verdict'] === RouteVerdict::REJECTED) {
                    $endedBy = $stepId;
                } elseif ($endedBy === $stepId) {
                    $endedBy = null;
                }
            }
            if ($event['actor_profile_id'] !== null) {
                $actorsAt[$stepId][(int) $event['actor_profile_id']] = true;
            }
        }
        $notApproved = $state === 'not approved' ? $endedBy : null;

        $actorIds = [];
        foreach ($trail as $event) {
            if ($event['actor_profile_id'] !== null) {
                $actorIds[] = (int) $event['actor_profile_id'];
            }
        }
        $names = $showNames ? $this->names($tenantId, $actorIds) : [];

        $finalisation = $this->finalisations?->findForRoute($tenantId, $routeId);
        $finalDocument = null;
        if ($finalisation !== null && $finalisation['step_id'] !== null) {
            $finalDocument = [
                // `waiting` while it is being made or the render service is down;
                // `ready` once the PDF exists; `failed` when it could not be made.
                'status' => match ($finalisation['status']) {
                    RouteFinalisationRepository::DONE => 'ready',
                    RouteFinalisationRepository::FAILED => 'failed',
                    default => 'waiting',
                },
                'can_open' => $finalisation['status'] === RouteFinalisationRepository::DONE
                    && $this->applicantGetsFinalDocument($tenantId, (int) $submission['form_id']),
            ];
        }

        $stages = [];
        foreach ($steps as $step) {
            $stepId = (int) $step['id'];
            if (($step['kind'] ?? null) === RouteStepKind::FINALISE) {
                // Nobody acts here: its status is what the engine has done.
                $stages[] = [
                    'position' => (int) $step['position'],
                    'label' => is_string($step['label'] ?? null) && $step['label'] !== '' ? (string) $step['label'] : null,
                    'kind' => RouteStepKind::FINALISE,
                    'status' => match ($finalDocument['status'] ?? null) {
                        'ready' => self::STAGE_DONE,
                        'waiting', 'failed' => self::STAGE_CURRENT,
                        default => self::STAGE_WAITING,
                    },
                    'outcome' => null,
                ];
                continue;
            }
            $status = match (true) {
                $stepId === $notApproved => self::STAGE_NOT_APPROVED,
                $openAuthorItem !== null && $stepId === $sentBackFrom => self::STAGE_SENT_BACK,
                isset($openAt[$stepId]) => self::STAGE_CURRENT,
                isset($reached[$stepId]) => self::STAGE_DONE,
                default => self::STAGE_WAITING,
            };
            $stage = [
                'position' => (int) $step['position'],
                // Null when the route's author gave the stage no name; a client
                // then says "Stage 2".
                'label' => is_string($step['label'] ?? null) && $step['label'] !== '' ? (string) $step['label'] : null,
                'status' => $status,
                'outcome' => $outcomeAt[$stepId] ?? null,
            ];
            if ($showNames) {
                $stage['reviewers'] = array_values(array_filter(array_map(
                    static fn (int $id): ?string => $names[$id] ?? null,
                    array_keys($actorsAt[$stepId] ?? []),
                )));
            }
            $stages[] = $stage;
        }

        $labels = [];
        foreach ($steps as $step) {
            $labels[(int) $step['id']] = ['label' => $step['label'] ?? null, 'position' => (int) $step['position']];
        }

        $notes = [];
        foreach ($trail as $event) {
            if (!DbBool::of($event['to_author'] ?? false) || $event['note'] === null) {
                continue;
            }
            $stepId = $event['step_id'];
            $note = [
                'kind' => match (true) {
                    $event['action'] === RouteAction::SENT_BACK => 'sent_back',
                    $event['verdict'] === RouteVerdict::REJECTED => 'not_approved',
                    default => 'note',
                },
                'note' => (string) $event['note'],
                'stage' => $stepId !== null && isset($labels[$stepId]) ? $labels[$stepId]['label'] : null,
                'stage_position' => $stepId !== null && isset($labels[$stepId]) ? $labels[$stepId]['position'] : null,
                'at' => (string) $event['occurred_at'],
            ];
            if ($showNames) {
                $note['from'] = $event['actor_profile_id'] !== null ? ($names[(int) $event['actor_profile_id']] ?? null) : null;
            }
            $notes[] = $note;
        }

        return [
            'state' => $state,
            'stages' => $stages,
            'notes' => $notes,
            // Only a route with no document: one re-pointed from a document by
            // migration 233 goes on treating it as a document (WHIT-920).
            'can_resubmit' => $openAuthorItem !== null
                && $route['document_id'] === null
                && $viewerProfileId !== null
                && (int) $openAuthorItem['profile_id'] === $viewerProfileId,
            'resume_from' => $openAuthorItem !== null ? $resumeFrom : null,
            'shows_reviewers' => $showNames,
            'final_document' => $finalDocument,
        ];
    }

    /** WHIT-921: whether the form lets the person who sent it open its final document. */
    private function applicantGetsFinalDocument(int $tenantId, int $formId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT final_document_to_applicant FROM forms WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $formId, ':tenant_id' => $tenantId]);
        $value = $stmt->fetchColumn();

        return $value !== false && DbBool::of($value);
    }

    public function showsReviewerNames(int $tenantId): bool
    {
        $value = $this->settings->effective($tenantId)[SettingsRegistry::FORMS_SHOW_REVIEWER_NAMES] ?? null;
        if (!is_string($value)) {
            $value = SettingsRegistry::defaults()[SettingsRegistry::FORMS_SHOW_REVIEWER_NAMES] ?? 'false';
        }

        return $value === 'true';
    }

    /**
     * The newest route on the submission, or on its document for one routed
     * before migration 233 whose route was not re-pointed.
     *
     * @param array<string, mixed> $submission
     * @return array<string, mixed>|null
     */
    private function routeFor(int $tenantId, array $submission): ?array
    {
        $routes = $this->routes->listForSubject(FormSubmissionRouteSubject::TYPE, (int) $submission['id'], $tenantId);
        if ($routes === [] && is_int($submission['document_id'] ?? null)) {
            $routes = $this->routes->listForSubject(DocumentRouteSubject::TYPE, (int) $submission['document_id'], $tenantId);
        }

        return $routes[0] ?? null;
    }

    /**
     * Display names of the people who acted, limited to this workspace's members.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function names(int $tenantId, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            'SELECT p.id, p.display_name FROM profiles p
              WHERE p.id IN (' . $placeholders . ')
                AND EXISTS (SELECT 1 FROM memberships m WHERE m.profile_id = p.id AND m.tenant_id = ?)'
        );
        $stmt->execute([...$ids, $tenantId]);

        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string) ($row['display_name'] ?? ''));
            if ($name !== '') {
                $names[(int) $row['id']] = $name;
            }
        }

        return $names;
    }
}
