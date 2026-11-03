<?php

declare(strict_types=1);

namespace Whity\Core\Audit;

use PDO;
use Whity\Core\i18n\ServerLabels;

/**
 * Turns raw audit rows into what a person reads (W.4): names, never ids, and a
 * sentence in the caller's language.
 *
 * Names are looked up in one batch per page. Who may appear is decided per row,
 * by that row's own tenant, because the system tenant reads rows from every
 * workspace: an actor did something in this workspace, so their name stays even
 * after they leave it, but a target is named only if it still belongs to that
 * row's workspace. A plugin can write any target id, so a row must not be able
 * to name somebody from another workspace by guessing theirs.
 *
 * Nothing here runs a permission check: the caller has already decided which
 * rows the reader may see.
 */
final class AuditPresenter
{
    private const SYSTEM_TENANT_ID = 0;

    /** The target types whose row can be looked up, and the word when it can't. */
    private const TARGET_WORDS = [
        'user' => ['audit.target.person', 'a person'],
        'profile' => ['audit.target.person', 'a person'],
        'role' => ['audit.target.role', 'a role'],
        'ou' => ['audit.target.unit', 'a unit'],
        'tenant' => ['audit.target.workspace', 'a workspace'],
        'agent' => ['audit.target.agent', 'an agent'],
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly ?ServerLabels $labels = null,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows Raw audit_log rows.
     * @return list<array{
     *   actor: array{kind: string, name: string, agent: ?string, forPerson: ?string},
     *   target: ?array{type: string, name: string},
     *   sentence: string,
     *   sentenceTemplate: string,
     *   area: string,
     *   how: ?string
     * }> One entry per row, in order.
     */
    public function present(array $rows): array
    {
        $profileIds = [];
        $principals = [];
        foreach ($rows as $row) {
            foreach (['actor_user_id', 'on_behalf_of_profile_id'] as $column) {
                if (isset($row[$column])) {
                    $profileIds[] = (int) $row[$column];
                }
            }
            if (isset($row['agent_principal_id']) && $row['agent_principal_id'] !== '') {
                $principals[] = (string) $row['agent_principal_id'];
            }
        }

        $targets = $this->targetNames($rows);
        foreach ($targets['profiles'] as $profileId) {
            $profileIds[] = $profileId;
        }

        $names = $this->personNames($profileIds);
        $agents = $this->agentNames($principals);

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->presentRow($row, $names, $agents, $targets);
        }

        return $out;
    }

    /**
     * People and agents who appear in the rows a reader can see, for the Who and
     * Agent filters, by name.
     *
     * @param list<int>    $profileIds
     * @param list<string> $principals
     * @return array{people: list<array{id: int, name: string}>, agents: list<array{id: string, name: string}>}
     */
    public function facets(array $profileIds, array $principals): array
    {
        $people = [];
        foreach ($this->personNames($profileIds) as $id => $name) {
            if ($name !== '') {
                $people[] = ['id' => $id, 'name' => $name];
            }
        }
        usort($people, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        $agents = [];
        foreach ($this->agentNames($principals) as $id => $agent) {
            $agents[] = ['id' => $id, 'name' => $agent['name']];
        }
        usort($agents, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return ['people' => $people, 'agents' => $agents];
    }

    /**
     * @param array<string, mixed>                                 $row
     * @param array<int, string>                                   $names
     * @param array<string, array{name: string, tenant_id: int}>   $agents
     * @param array{people: array<string, true>, roles: array<int, array{name: string, tenant_id: ?int}>, units: array<int, array{name: string, tenant_id: int}>, tenants: array<int, string>, profiles: list<int>} $targets
     * @return array{actor: array{kind: string, name: string, agent: ?string, forPerson: ?string}, target: ?array{type: string, name: string}, sentence: string, sentenceTemplate: string, area: string, how: ?string}
     */
    private function presentRow(array $row, array $names, array $agents, array $targets): array
    {
        $tenantId = (int) ($row['tenant_id'] ?? 0);
        $action = (string) ($row['action'] ?? '');
        $metadata = self::metadataOf($row);

        $actor = $this->actorOf($row, $tenantId, $metadata, $names, $agents);
        $target = $this->targetOf($row, $tenantId, $metadata, $names, $targets);

        $known = AuditSentences::template($action);
        $template = $known !== null
            ? $this->label(AuditSentences::key($action), $known)
            : $this->label('audit.fallback', '{actor}: {what}');

        return [
            'actor' => $actor,
            'target' => $target,
            'sentence' => strtr($template, [
                '{actor}' => $actor['name'],
                '{target}' => $target['name'] ?? '',
                '{what}' => AuditSentences::humanize($action),
            ]),
            // Only `{what}` is filled in here. It comes from the action code, and
            // the two names stay placeholders so a client can set them in bold
            // without ever searching for a name inside the sentence.
            'sentenceTemplate' => strtr($template, ['{what}' => AuditSentences::humanize($action)]),
            'area' => AuditSentences::areaOf($action),
            'how' => $this->howOf($row, $metadata),
        ];
    }

    /**
     * @param array<string, mixed>                               $row
     * @param array<string, mixed>                               $metadata
     * @param array<int, string>                                 $names
     * @param array<string, array{name: string, tenant_id: int}> $agents
     * @return array{kind: string, name: string, agent: ?string, forPerson: ?string}
     */
    private function actorOf(array $row, int $tenantId, array $metadata, array $names, array $agents): array
    {
        $principal = isset($row['agent_principal_id']) ? (string) $row['agent_principal_id'] : '';

        if ($principal !== '') {
            $agent = $agents[$principal] ?? null;
            $agentName = $agent !== null && $agent['tenant_id'] === $tenantId
                ? $agent['name']
                : $this->label('audit.actor.agent', 'An agent');
            $personId = (int) ($row['on_behalf_of_profile_id'] ?? $row['actor_user_id'] ?? 0);
            $person = $names[$personId] ?? '';

            return [
                'kind' => Actor::AGENT,
                'name' => $person === ''
                    ? $agentName
                    : strtr($this->label('audit.actor.agentFor', '{agent} for {person}'), ['{agent}' => $agentName, '{person}' => $person]),
                'agent' => $agentName,
                'forPerson' => $person === '' ? null : $person,
            ];
        }

        if (isset($row['actor_user_id'])) {
            $name = $names[(int) $row['actor_user_id']] ?? '';

            return [
                'kind' => Actor::PERSON,
                'name' => $name !== '' ? $name : $this->label('audit.actor.someone', 'Someone'),
                'agent' => null,
                'forPerson' => null,
            ];
        }

        // AuditOrigin's two silences: no actor and an origin is the platform
        // working by itself; no actor and no origin is somebody who never signed
        // in, such as a failed login.
        if (isset($metadata[AuditOrigin::METADATA_KEY]) || ($row['actor_kind'] ?? null) === Actor::SYSTEM) {
            return ['kind' => Actor::SYSTEM, 'name' => $this->label('audit.actor.system', 'Whity'), 'agent' => null, 'forPerson' => null];
        }

        return ['kind' => Actor::PERSON, 'name' => $this->label('audit.actor.someone', 'Someone'), 'agent' => null, 'forPerson' => null];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $metadata
     * @param array<int, string>   $names
     * @param array{people: array<string, true>, roles: array<int, array{name: string, tenant_id: ?int}>, units: array<int, array{name: string, tenant_id: int}>, tenants: array<int, string>, profiles: list<int>} $targets
     * @return array{type: string, name: string}|null
     */
    private function targetOf(array $row, int $tenantId, array $metadata, array $names, array $targets): ?array
    {
        $type = isset($row['target_type']) ? (string) $row['target_type'] : '';
        if ($type === '') {
            return null;
        }
        $id = isset($row['target_id']) ? (int) $row['target_id'] : 0;

        $name = match ($type) {
            'user', 'profile' => isset($targets['people'][$tenantId . ':' . $id]) ? ($names[$id] ?? '') : '',
            'role' => isset($targets['roles'][$id]) && in_array($targets['roles'][$id]['tenant_id'], [null, $tenantId], true)
                ? $targets['roles'][$id]['name']
                : '',
            'ou' => isset($targets['units'][$id]) && $targets['units'][$id]['tenant_id'] === $tenantId
                ? $targets['units'][$id]['name']
                : '',
            'tenant' => $tenantId === self::SYSTEM_TENANT_ID || $id === $tenantId ? ($targets['tenants'][$id] ?? '') : '',
            default => '',
        };

        // A deleted row is gone, but the event recorded what it was called.
        if ($name === '') {
            foreach (['name', 'display_name', 'title'] as $key) {
                if (isset($metadata[$key]) && is_string($metadata[$key]) && $metadata[$key] !== '') {
                    $name = $metadata[$key];
                    break;
                }
            }
        }

        if ($name === '') {
            [$key, $english] = self::TARGET_WORDS[$type] ?? ['audit.target.record', 'a record'];
            $name = $this->label($key, $english);
        }

        return ['type' => $type, 'name' => $name];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $metadata
     */
    private function howOf(array $row, array $metadata): ?string
    {
        if (isset($row['agent_principal_id']) && $row['agent_principal_id'] !== '') {
            return $this->label('audit.how.agent', 'Through a connected agent');
        }

        return match ($metadata[AuditOrigin::METADATA_KEY] ?? null) {
            AuditOrigin::CHANNEL_CLI => $this->label('audit.how.cli', 'From the command line'),
            AuditOrigin::CHANNEL_JOB => $this->label('audit.how.job', 'By a scheduled job'),
            default => null,
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{people: array<string, true>, roles: array<int, array{name: string, tenant_id: ?int}>, units: array<int, array{name: string, tenant_id: int}>, tenants: array<int, string>, profiles: list<int>}
     */
    private function targetNames(array $rows): array
    {
        $ids = ['people' => [], 'role' => [], 'ou' => [], 'tenant' => []];
        $tenantIds = [];
        foreach ($rows as $row) {
            if (!isset($row['target_id'], $row['target_type'])) {
                continue;
            }
            $id = (int) $row['target_id'];
            $tenantIds[] = (int) ($row['tenant_id'] ?? 0);
            match ((string) $row['target_type']) {
                'user', 'profile' => $ids['people'][] = $id,
                'role' => $ids['role'][] = $id,
                'ou' => $ids['ou'][] = $id,
                'tenant' => $ids['tenant'][] = $id,
                default => null,
            };
        }

        $out = ['people' => [], 'roles' => [], 'units' => [], 'tenants' => [], 'profiles' => []];

        if ($ids['people'] !== []) {
            [$in, $params] = self::inList(array_values(array_unique($ids['people'])), 'p');
            [$tenantIn, $tenantParams] = self::inList(array_values(array_unique($tenantIds)), 't');
            $stmt = $this->db->prepare(
                'SELECT profile_id, tenant_id FROM memberships WHERE profile_id IN (' . $in . ') AND tenant_id IN (' . $tenantIn . ')'
            );
            $stmt->execute($params + $tenantParams);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out['people'][(int) $row['tenant_id'] . ':' . (int) $row['profile_id']] = true;
                $out['profiles'][] = (int) $row['profile_id'];
            }
        }

        if ($ids['role'] !== []) {
            [$in, $params] = self::inList(array_values(array_unique($ids['role'])), 'r');
            // @tenant-guard-ignore: each row's tenant_id is compared with the audit row's own tenant in targetOf() before the name is used
            $stmt = $this->db->prepare('SELECT id, name, tenant_id FROM roles WHERE id IN (' . $in . ')');
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out['roles'][(int) $row['id']] = [
                    'name' => (string) $row['name'],
                    'tenant_id' => $row['tenant_id'] === null ? null : (int) $row['tenant_id'],
                ];
            }
        }

        if ($ids['ou'] !== []) {
            [$in, $params] = self::inList(array_values(array_unique($ids['ou'])), 'o');
            // @tenant-guard-ignore: each row's tenant_id is compared with the audit row's own tenant in targetOf() before the name is used
            $stmt = $this->db->prepare('SELECT id, name, tenant_id FROM organizational_units WHERE id IN (' . $in . ')');
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out['units'][(int) $row['id']] = ['name' => (string) $row['name'], 'tenant_id' => (int) $row['tenant_id']];
            }
        }

        if ($ids['tenant'] !== []) {
            [$in, $params] = self::inList(array_values(array_unique($ids['tenant'])), 'w');
            $stmt = $this->db->prepare('SELECT id, name FROM tenants WHERE id IN (' . $in . ')');
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out['tenants'][(int) $row['id']] = (string) $row['name'];
            }
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function personNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        [$in, $params] = self::inList($ids, 'n');
        $stmt = $this->db->prepare('SELECT id, display_name FROM profiles WHERE id IN (' . $in . ')');
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = (string) ($row['display_name'] ?? '');
        }

        return $out;
    }

    /**
     * An agent principal is its credential's id. The row's tenant travels with
     * the name, so a row can't borrow the name of another workspace's agent.
     *
     * @param list<string> $principals
     * @return array<string, array{name: string, tenant_id: int}>
     */
    private function agentNames(array $principals): array
    {
        $principals = array_values(array_unique($principals));
        if ($principals === []) {
            return [];
        }
        [$in, $params] = self::inList($principals, 'a');
        // @tenant-guard-ignore: jti is globally unique and the tenant_id it returns is compared with the audit row's tenant before the name is used
        $stmt = $this->db->prepare('SELECT jti, name, tenant_id FROM mcp_tokens WHERE jti IN (' . $in . ')');
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['jti']] = ['name' => (string) $row['name'], 'tenant_id' => (int) $row['tenant_id']];
        }

        return $out;
    }

    private function label(string $key, string $english): string
    {
        return $this->labels?->label('admin', $key, $english) ?? $english;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function metadataOf(array $row): array
    {
        $raw = $row['metadata'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<int|string> $values
     * @return array{0: string, 1: array<string, int|string>}
     */
    private static function inList(array $values, string $prefix): array
    {
        $names = [];
        $params = [];
        foreach ($values as $i => $value) {
            $names[] = ':' . $prefix . $i;
            $params[':' . $prefix . $i] = $value;
        }

        return [implode(', ', $names), $params];
    }
}
