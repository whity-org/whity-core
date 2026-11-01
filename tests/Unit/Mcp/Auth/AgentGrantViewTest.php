<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Auth;

use PHPUnit\Framework\TestCase;
use Whity\Mcp\Auth\AgentGrantView;
use Whity\Mcp\Auth\AgentScopeGrant;

/**
 * What a grant means, permission by permission (WHIT-832): which modes a person
 * may set, why the others are withheld, and what the agent can then do.
 */
final class AgentGrantViewTest extends TestCase
{
    private const OPERATIONS = [
        ['permission' => 'forms:read',     'readOnly' => true,  'agentPolicy' => 'allow'],
        ['permission' => 'forms:manage',   'readOnly' => false, 'agentPolicy' => 'allow'],
        ['permission' => 'forms:manage',   'readOnly' => true,  'agentPolicy' => 'allow'],
        ['permission' => 'invoices:send',  'readOnly' => false, 'agentPolicy' => 'allow'],
        ['permission' => 'payroll:run',    'readOnly' => false, 'agentPolicy' => 'refuse'],
        ['permission' => 'users:manage',   'readOnly' => false, 'agentPolicy' => 'allow'],
    ];
    private const HELD = ['forms:read', 'forms:manage', 'invoices:send', 'payroll:run'];

    public function testTheDefaultIsReadsAllowedWritesAskFirst(): void
    {
        $rows = $this->rows(['tools:call']);

        self::assertNull($rows['forms:manage']['mode']);
        self::assertSame(['read' => 'allow', 'write' => 'ask'], $rows['forms:manage']['effective']);
        self::assertSame(['read' => null, 'write' => 'ask'], $rows['invoices:send']['effective']);
    }

    public function testAllowIsWithheldWhereEveryOperationChangesData(): void
    {
        $rows = $this->rows(['tools:call']);

        // No undo yet: a write is always Ask first, so Allowed would mean nothing.
        self::assertSame(['off', 'ask'], $rows['invoices:send']['modes']);
        self::assertSame(AgentGrantView::ALWAYS_ASKS, $rows['invoices:send']['withheld']['allow']['reason']);
        // A permission with reads behind it can be Allowed: the reads run.
        self::assertSame(['off', 'ask', 'allow'], $rows['forms:manage']['modes']);
    }

    public function testAPermissionClosedToAgentsCanOnlyBeOff(): void
    {
        $rows = $this->rows(['tools:call']);

        self::assertSame(['off'], $rows['payroll:run']['modes']);
        self::assertSame(AgentGrantView::REFUSED, $rows['payroll:run']['withheld']['ask']['reason']);
        self::assertSame(['read' => null, 'write' => 'off'], $rows['payroll:run']['effective']);
    }

    public function testAPermissionThePersonLacksIsNotOffered(): void
    {
        self::assertArrayNotHasKey('users:manage', $this->rows(['tools:call']));

        $choices = (new AgentGrantView(self::OPERATIONS))->choices('users:manage', ['tools:call'], self::HELD);
        self::assertSame(['off'], $choices['modes']);
        self::assertSame(AgentGrantView::NOT_HELD, $choices['withheld']['allow']['reason']);
    }

    public function testAStoredModeForAPermissionNoLongerHeldStaysVisibleToClear(): void
    {
        $rows = $this->rows(['tools:call', 'grant:users:manage=ask']);

        self::assertSame('ask', $rows['users:manage']['mode']);
        self::assertSame(['off'], $rows['users:manage']['modes']);
    }

    public function testACredentialNarrowedToSomePermissionsOffersOnlyThose(): void
    {
        $rows = $this->rows(['tools:call', 'forms:*']);

        self::assertSame(['forms:manage', 'forms:read'], array_keys($rows));
        $choices = (new AgentGrantView(self::OPERATIONS))->choices('invoices:send', ['tools:call', 'forms:*'], self::HELD);
        self::assertSame(AgentGrantView::OUTSIDE, $choices['withheld']['ask']['reason']);
    }

    public function testOffTurnsEverythingOff(): void
    {
        $rows = $this->rows(['tools:call', 'grant:forms:manage=off']);

        self::assertSame('off', $rows['forms:manage']['mode']);
        self::assertSame(['read' => 'off', 'write' => 'off'], $rows['forms:manage']['effective']);
    }

    public function testAWriteThatCanBeUndoneCanBeAllowedAndSaysForHowLong(): void
    {
        $view = new AgentGrantView([
            ['permission' => 'tags:manage', 'readOnly' => false, 'agentPolicy' => 'allow', 'undoWindowSeconds' => 1800],
            ['permission' => 'tags:manage', 'readOnly' => false, 'agentPolicy' => 'allow', 'undoWindowSeconds' => null],
        ]);

        $row = $view->rows(['tools:call', 'grant:tags:manage=allow'], ['tags:manage'])[0];

        self::assertSame(['off', 'ask', 'allow'], $row['modes']);
        self::assertSame(['read' => null, 'write' => 'allow'], $row['effective']);
        self::assertSame(1800, $row['undoWindowSeconds']);
        self::assertNull($this->rows(['tools:call'])['invoices:send']['undoWindowSeconds']);
    }

    public function testWithModeReplacesOnlyThatPermissionsEntry(): void
    {
        $scope = ['tools:call', 'forms:*', 'grant:forms:read=off', 'grant:forms:manage=ask'];

        self::assertSame(
            ['tools:call', 'forms:*', 'grant:forms:manage=ask', 'grant:forms:read=allow'],
            AgentScopeGrant::withMode($scope, 'forms:read', 'allow'),
        );
        self::assertSame(['tools:call', 'forms:*', 'grant:forms:manage=ask'], AgentScopeGrant::withMode($scope, 'forms:read', null));
    }

    /**
     * @param list<string> $scope
     * @return array<string, array<string, mixed>>
     */
    private function rows(array $scope): array
    {
        $rows = (new AgentGrantView(self::OPERATIONS))->rows($scope, self::HELD);
        return array_combine(array_column($rows, 'permission'), $rows);
    }
}
