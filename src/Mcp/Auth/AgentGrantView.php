<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth;

use Whity\Mcp\Tools\ToolSurface;

/**
 * What an agent's grant means, permission by permission (WHIT-832; MCP-TOOL-
 * SURFACE §5.11; register Scope grant).
 *
 * For each permission the agent could use it says the mode the person chose
 * (or none, for the default), which modes they may choose and why the others
 * are withheld, and what the agent can actually do with the operations behind
 * it: the grant lowered by each operation's agent policy and by having no undo.
 * The screen shows this, and the PATCH accepts only a mode listed as possible,
 * so a grant never goes above the granting person.
 *
 * Pure: the operations and the person's permissions come in, rows go out.
 */
final class AgentGrantView
{
    /** Why a mode is withheld. */
    public const NOT_HELD    = 'not_held';
    public const OUTSIDE     = 'outside_credential';
    public const ALWAYS_ASKS = 'always_asks';
    public const REFUSED     = 'refused_for_agents';

    private const MESSAGES = [
        self::NOT_HELD    => 'You don\'t have this permission, so an agent acting for you can\'t either.',
        self::OUTSIDE     => 'This credential is limited to other permissions.',
        self::ALWAYS_ASKS => 'Everything this permission allows changes data that can\'t be undone yet, so the agent always asks first.',
        self::REFUSED     => 'Nothing this permission allows is open to agents.',
    ];

    /**
     * @param list<array{permission: string, readOnly: bool, agentPolicy: string}> $operations
     *        every operation an agent could be offered, with its gate
     */
    public function __construct(private readonly array $operations)
    {
    }

    /**
     * @param list<string> $scope the credential's scope, grant entries included
     * @param list<string> $held  the granting person's effective permissions
     * @return list<array<string, mixed>>
     */
    public function rows(array $scope, array $held): array
    {
        $stored  = AgentScopeGrant::fromScope($scope)->entries();
        $narrow  = new McpScope($scope);
        $byPermission = [];
        foreach ($this->operations as $operation) {
            $byPermission[$operation['permission']][] = $operation;
        }

        // The permissions the agent could use through this credential, and any
        // it has a stored mode for (shown so a stale entry can be cleared).
        $names = array_keys($stored);
        foreach (array_keys($byPermission) as $permission) {
            if (in_array($permission, $held, true) && $narrow->allowsPermission($permission)) {
                $names[] = (string) $permission;
            }
        }
        $names = array_values(array_unique($names));
        sort($names);

        $rows = [];
        foreach ($names as $permission) {
            $choices = $this->choices($permission, $scope, $held);
            $rows[]  = [
                'permission' => $permission,
                'mode'       => $stored[$permission] ?? null,
                'modes'      => $choices['modes'],
                'withheld'   => $choices['withheld'],
                'effective'  => $this->effective($permission, $scope),
            ];
        }
        return $rows;
    }

    /**
     * The modes the person may choose for one permission, and why any other
     * is withheld. Off is always possible.
     *
     * @param list<string> $scope
     * @param list<string> $held
     * @return array{modes: list<string>, withheld: array<string, array{reason: string, message: string}>}
     */
    public function choices(string $permission, array $scope, array $held): array
    {
        $reason = null;
        if (!in_array($permission, $held, true)) {
            $reason = self::NOT_HELD;
        } elseif (!(new McpScope($scope))->allowsPermission($permission)) {
            $reason = self::OUTSIDE;
        }
        if ($reason !== null) {
            return ['modes' => [AgentScopeGrant::OFF], 'withheld' => [
                AgentScopeGrant::ASK   => self::why($reason),
                AgentScopeGrant::ALLOW => self::why($reason),
            ]];
        }

        // What each mode would let the agent do here, after the ceilings.
        $reach = static fn (string $mode, array $operations): array => array_map(
            static fn (array $op): string => AgentScopeGrant::fromScope([AgentScopeGrant::PREFIX . "{$permission}={$mode}"])
                ->effectiveMode($permission, $op['readOnly'], $op['agentPolicy'], false),
            $operations,
        );
        $operations = array_values(array_filter($this->operations, static fn (array $op): bool => $op['permission'] === $permission));

        $modes    = [AgentScopeGrant::OFF];
        $withheld = [];
        if ($operations !== [] && !in_array(AgentScopeGrant::ASK, $reach(AgentScopeGrant::ASK, $operations), true)
            && !in_array(AgentScopeGrant::ALLOW, $reach(AgentScopeGrant::ALLOW, $operations), true)) {
            $withheld[AgentScopeGrant::ASK]   = self::why(self::REFUSED);
            $withheld[AgentScopeGrant::ALLOW] = self::why(self::REFUSED);
            return ['modes' => $modes, 'withheld' => $withheld];
        }
        $modes[] = AgentScopeGrant::ASK;
        if ($operations === [] || in_array(AgentScopeGrant::ALLOW, $reach(AgentScopeGrant::ALLOW, $operations), true)) {
            $modes[] = AgentScopeGrant::ALLOW;
        } else {
            $withheld[AgentScopeGrant::ALLOW] = self::why(self::ALWAYS_ASKS);
        }
        return ['modes' => $modes, 'withheld' => $withheld];
    }

    /**
     * What the agent can do now with this permission's reads and writes: the
     * most an operation of each kind allows, or null when it has none.
     *
     * @param list<string> $scope
     * @return array{read: ?string, write: ?string}
     */
    private function effective(string $permission, array $scope): array
    {
        $grant = AgentScopeGrant::fromScope($scope);
        $best  = ['read' => null, 'write' => null];
        foreach ($this->operations as $op) {
            if ($op['permission'] !== $permission) {
                continue;
            }
            $kind = $op['readOnly'] ? 'read' : 'write';
            $mode = $grant->effectiveMode($permission, $op['readOnly'], $op['agentPolicy'], false);
            if ($best[$kind] === null || self::rank($mode) > self::rank($best[$kind])) {
                $best[$kind] = $mode;
            }
        }
        return $best;
    }

    private static function rank(string $mode): int
    {
        return (int) array_search($mode, AgentScopeGrant::MODES, true);
    }

    /** @return array{reason: string, message: string} */
    private static function why(string $reason): array
    {
        return ['reason' => $reason, 'message' => self::MESSAGES[$reason]];
    }

    /**
     * The operations an agent could be offered, from tool objects and their
     * access map entries: each gated one with its read-only hint and agent
     * policy. Ungated and role-only operations have no permission to grant.
     *
     * @param iterable<array<string, mixed>> $tools
     * @param array<string, array<string, mixed>> $access tool name => access entry
     * @return list<array{permission: string, readOnly: bool, agentPolicy: string}>
     */
    public static function operationsFrom(iterable $tools, array $access): array
    {
        $operations = [];
        foreach ($tools as $tool) {
            $name       = (string) ($tool['name'] ?? '');
            $permission = $access[$name]['requiredPermission'] ?? null;
            if (!is_string($permission) || $permission === '') {
                continue;
            }
            $meta         = is_array($tool['_meta'] ?? null) ? $tool['_meta'] : [];
            $annotations  = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];
            $operations[] = [
                'permission'  => $permission,
                'readOnly'    => ($annotations['readOnlyHint'] ?? false) === true,
                'agentPolicy' => is_string($meta[ToolSurface::META_AGENT_POLICY] ?? null) ? $meta[ToolSurface::META_AGENT_POLICY] : ToolSurface::AGENT_ALLOW,
            ];
        }
        return $operations;
    }
}
