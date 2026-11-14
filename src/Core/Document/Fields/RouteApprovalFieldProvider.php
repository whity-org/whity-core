<?php

declare(strict_types=1);

namespace Whity\Core\Document\Fields;

use PDO;
use Whity\Core\Db\DbBool;
use Whity\Core\Document\Routing\RouteApprovals;

/**
 * Who approved, offered to every template (WHIT-1018): each approver's name,
 * the date, the stage and an approval mark, for every approval on the route
 * (`route.approvals.*.name`) or for one stage's
 * (`route.step.<n>.approvals.*.name`). Put these in a table row and the row
 * prints once per approval; see {@see RouteApprovals}.
 *
 * The picker offers one group for all approvals and one for each decision
 * stage of the workspace's route designs, named after the stage. Like
 * {@see RouteValueFieldProvider}, it resolves to nothing here: the final
 * document stage fills it from the route, and a preview shows the sample.
 *
 * @i18n-keys documents
 *   fields.group.approvals = Approvals
 *   fields.group.stageApprovals = Approvals at {stage}
 *   fields.approvals.name = Approver's name (a row each)
 *   fields.approvals.date = Date approved (a row each)
 *   fields.approvals.step = Stage (a row each)
 *   fields.approvals.mark = Approval mark (a row each)
 *   fields.approvals.count = Number of approvals
 *   fields.approvals.stage = Stage {number}
 */
final class RouteApprovalFieldProvider implements DocumentFieldProvider
{
    private FieldLabels $labels;

    public function __construct(private readonly PDO $db, ?FieldLabels $labels = null)
    {
        $this->labels = $labels ?? new FieldLabels();
    }

    public function recordType(): string
    {
        return '*';
    }

    public function recordLabel(): string
    {
        return $this->labels->get('fields.group.approvals', 'Approvals');
    }

    public function groups(int $tenantId): array
    {
        $groups = [['group' => $this->recordLabel(), 'items' => $this->items(RouteApprovals::prefix())]];

        foreach ($this->decisionStages($tenantId) as $position => $label) {
            $stage = $label ?? str_replace('{number}', (string) $position, $this->labels->get('fields.approvals.stage', 'Stage {number}'));
            $groups[] = [
                'group' => str_replace('{stage}', $stage, $this->labels->get('fields.group.stageApprovals', 'Approvals at {stage}')),
                'items' => $this->items(RouteApprovals::prefix($position)),
            ];
        }

        return $groups;
    }

    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): array
    {
        return ['label' => '', 'values' => []];
    }

    public function records(int $tenantId, int $callerId, callable $hasPermission, string $query, int $limit): array
    {
        return [];
    }

    /** @return list<array{path: string, label: string, sample: string}> */
    private function items(string $prefix): array
    {
        return [
            ['path' => $prefix . '.*.name', 'label' => $this->labels->get('fields.approvals.name', "Approver's name (a row each)"), 'sample' => 'Awss Haddad'],
            ['path' => $prefix . '.*.date', 'label' => $this->labels->get('fields.approvals.date', 'Date approved (a row each)'), 'sample' => '2026-11-14'],
            ['path' => $prefix . '.*.step', 'label' => $this->labels->get('fields.approvals.step', 'Stage (a row each)'), 'sample' => 'Ethics committee'],
            ['path' => $prefix . '.*.mark', 'label' => $this->labels->get('fields.approvals.mark', 'Approval mark (a row each)'), 'sample' => 'Approved 2026-11-14'],
            ['path' => $prefix . '.count', 'label' => $this->labels->get('fields.approvals.count', 'Number of approvals'), 'sample' => '5'],
        ];
    }

    /**
     * The decision stages of the workspace's route designs, by the position
     * they take on a route (designs are numbered 1..N in their own order when
     * a route is made from them), named after the first design's label.
     *
     * @return array<int, ?string>
     */
    private function decisionStages(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT template_id, position, label, decision FROM document_route_template_steps
              WHERE tenant_id = :tenant_id
              ORDER BY template_id ASC, position ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        $stages = [];
        $index = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $template = (int) $row['template_id'];
            $index[$template] = ($index[$template] ?? 0) + 1;
            if (!DbBool::of($row['decision'])) {
                continue;
            }
            $position = $index[$template];
            if (!array_key_exists($position, $stages) || $stages[$position] === null) {
                $stages[$position] = $row['label'] !== null && trim((string) $row['label']) !== '' ? (string) $row['label'] : null;
            }
        }
        ksort($stages);

        return $stages;
    }
}
