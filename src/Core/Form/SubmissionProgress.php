<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;
use Whity\Core\Db\DbBool;
use Whity\Core\Document\Routing\DocumentRouteSubject;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
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
 * before migration 227 whose route stayed on its document, that route). A read,
 * and nothing here decides anything: the trail and the open items are the
 * record.
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
    ) {
    }

    /**
     * @param array<string, mixed> $submission A row from {@see FormSubmissionRepository::find()}.
     *
     * @return array{state: string, stages: list<array<string, mixed>>, notes: list<array<string, mixed>>,
     *               can_resubmit: bool, resume_from: ?string, shows_reviewers: bool}
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

        $stages = [];
        foreach ($steps as $step) {
            $stepId = (int) $step['id'];
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
            'can_resubmit' => $openAuthorItem !== null
                && $viewerProfileId !== null
                && (int) $openAuthorItem['profile_id'] === $viewerProfileId,
            'resume_from' => $openAuthorItem !== null ? $resumeFrom : null,
            'shows_reviewers' => $showNames,
        ];
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
     * before migration 227 whose route was not re-pointed.
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
