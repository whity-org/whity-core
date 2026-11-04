<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DelegationsApiHandler;
use Whity\Api\NavigationApiHandler;
use Whity\Api\OusApiHandler;
use Whity\Api\RegistrationsApiHandler;
use Whity\Api\RolesApiHandler;
use Whity\Api\UsersApiHandler;
use Whity\Api\UsersBulkApiHandler;
use Whity\Auth\BackupCodesService;
use Whity\Auth\RoleChecker;
use Whity\Core\Delegation\DelegationRepository;
use Whity\Core\Delegation\DelegationService;
use Whity\Core\Document\Routing\DocumentRoutingInboxSource;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\PasswordResetService;
use Whity\Core\Identity\TwoFactorRecoveryService;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * The People server work for design 2.9 (S3), on a real engine: every new query is
 * read from at least two tenants, with the system tenant where it behaves differently.
 *
 * The fixture is Example Bakery (tenant 1) and a second workspace (tenant 2). All
 * invented. Run with PHPUNIT_PG_DSN set, this is real PostgreSQL.
 */
final class PeopleServerRealEngineTest extends TestCase
{
    private const SYSTEM = 0;
    private const BAKERY = 1;
    private const OTHER = 2;

    private PDO $pdo;
    private Database $db;
    private HookManager $hooks;

    /** @var list<array<string, mixed>> */
    private array $updates = [];

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $_GET = [];
        $this->pdo = SchemaFromMigrations::make(true);
        $this->db = Database::withFactory(fn (): PDO => $this->pdo);
        $this->db->setMaxLifetimeSeconds(86400);
        $this->db->setPingIntervalSeconds(86400);
        $this->db->forceConnect();
        foreach ([[self::SYSTEM, 'system'], [self::BAKERY, 'bakery'], [self::OTHER, 'other']] as [$id, $name]) {
            $this->pdo->prepare('INSERT INTO tenants (id, name) VALUES (?, ?) ON CONFLICT DO NOTHING')->execute([$id, $name]);
        }
        $this->updates = [];
        $this->hooks = new HookManager();
        $this->hooks->listen('user.updated', function (array $data): array {
            $this->updates[] = $data;

            return $data;
        });
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $_GET = [];
    }

    // -- users list ---------------------------------------------------------

    public function testListShowsActivityFromThisWorkspaceOnly(): void
    {
        $role = $this->role('staff', self::BAKERY);
        $rana = $this->person('rana@example.com', 'Rana Haddad');
        $this->member($rana, self::BAKERY, $role);
        $this->member($rana, self::OTHER, $this->role('staff', self::OTHER));
        $this->session($rana, self::OTHER, '2026-11-01 10:00:00');

        $row = $this->usersList(self::BAKERY)['data'][0];
        self::assertNull($row['lastActiveAt'], 'A session in another workspace is not activity here.');

        $this->session($rana, self::BAKERY, '2026-10-20 09:00:00');
        self::assertStringStartsWith('2026-10-20 09:00:00', (string) $this->usersList(self::BAKERY)['data'][0]['lastActiveAt']);
    }

    public function testListNamesPeopleAndFallsBackToTheEmail(): void
    {
        $role = $this->role('staff', self::BAKERY);
        $named = $this->person('rana@example.com', 'Rana Haddad');
        $unnamed = $this->person('omar@example.com', '');
        $this->member($named, self::BAKERY, $role);
        $this->member($unnamed, self::BAKERY, $role);

        $byId = array_column($this->usersList(self::BAKERY)['data'], null, 'id');
        self::assertSame('Rana Haddad', $byId[$named]['name']);
        self::assertSame('Rana Haddad', $byId[$named]['displayName']);
        self::assertSame('omar', $byId[$unnamed]['name']);
        self::assertSame('', $byId[$unnamed]['displayName']);
        self::assertSame($role, $byId[$named]['roleId']);
    }

    public function testSearchFindsTheDisplayName(): void
    {
        $role = $this->role('staff', self::BAKERY);
        $this->member($this->person('rana@example.com', 'Rana Haddad'), self::BAKERY, $role);
        $this->member($this->person('omar@example.com', ''), self::BAKERY, $role);

        $body = $this->usersList(self::BAKERY, '?q=haddad');
        self::assertCount(1, $body['data']);
        self::assertSame(1, $body['pagination']['total']);
    }

    public function testFiltersNarrowTheCountAndThePage(): void
    {
        $staff = $this->role('staff', self::BAKERY);
        $bakers = $this->role('bakers', self::BAKERY);
        $unit = $this->unit(self::BAKERY, 'Sweifieh');
        $a = $this->person('a@example.com', 'A');
        $b = $this->person('b@example.com', 'B');
        $c = $this->person('c@example.com', 'C');
        $this->member($a, self::BAKERY, $staff, $unit);
        $this->member($b, self::BAKERY, $bakers);
        $this->member($c, self::BAKERY, $bakers);
        $this->pdo->prepare('UPDATE profiles SET two_factor_enabled = TRUE WHERE id = ?')->execute([$a]);
        $this->pdo->prepare("UPDATE profiles SET status = 'inactive' WHERE id = ?")->execute([$c]);

        foreach ([
            ['?role_id=' . $staff, [$a]],
            ['?role_id=' . $staff . ',' . $bakers, [$a, $b, $c]],
            ['?ou_id=' . $unit, [$a]],
            ['?two_step=on', [$a]],
            ['?two_step=off', [$b, $c]],
            ['?account_status=inactive', [$c]],
            ['?account_status=active&role_id=' . $bakers, [$b]],
        ] as [$query, $expected]) {
            $body = $this->usersList(self::BAKERY, $query);
            $ids = array_column($body['data'], 'id');
            sort($ids);
            self::assertSame($expected, $ids, $query);
            self::assertSame(count($expected), $body['pagination']['total'], $query);
        }
    }

    public function testAFilterOnAnotherWorkspacesIdsFindsNobody(): void
    {
        $this->member($this->person('a@example.com', 'A'), self::BAKERY, $this->role('staff', self::BAKERY));
        $otherRole = $this->role('secret', self::OTHER);
        $this->member($this->person('b@example.com', 'B'), self::OTHER, $otherRole);
        $otherUnit = $this->unit(self::OTHER, 'Elsewhere');

        self::assertSame([], $this->usersList(self::BAKERY, '?role_id=' . $otherRole)['data']);
        self::assertSame([], $this->usersList(self::BAKERY, '?ou_id=' . $otherUnit)['data']);
    }

    public function testMalformedFiltersAreRefused(): void
    {
        foreach (['?role_id=1;drop', '?ou_id=x', '?two_step=maybe', '?account_status=gone'] as $query) {
            $this->actAs(self::BAKERY);
            $response = $this->usersHandler()->list(new Request('GET', '/api/users' . $query));
            self::assertSame(422, $response->getStatusCode(), $query);
        }
    }

    public function testTwoStepRequirementFollowsThePolicies(): void
    {
        $role = $this->role('staff', self::BAKERY);
        $parent = $this->unit(self::BAKERY, 'Head office');
        $child = $this->unit(self::BAKERY, 'Sweifieh', $parent);
        $inUnit = $this->person('unit@example.com', 'In unit');
        $named = $this->person('named@example.com', 'Named');
        $neither = $this->person('none@example.com', 'Neither');
        $this->member($inUnit, self::BAKERY, $role, $child);
        $this->member($named, self::BAKERY, $role);
        $this->member($neither, self::BAKERY, $role);
        $this->member($neither, self::OTHER, $this->role('staff', self::OTHER));
        $this->policy(self::BAKERY, 'ou', $parent);
        $this->policy(self::BAKERY, 'user', $named);
        $this->policy(self::OTHER, 'tenant', null);

        $byId = array_column($this->usersList(self::BAKERY)['data'], null, 'id');
        self::assertTrue($byId[$inUnit]['twoStep']['required'], 'A policy on an ancestor unit applies.');
        self::assertFalse($byId[$inUnit]['twoStep']['requiredDirectly']);
        self::assertTrue($byId[$named]['twoStep']['requiredDirectly']);
        self::assertFalse($byId[$neither]['twoStep']['required'], "Another workspace's policy does not apply here.");

        $this->policy(self::BAKERY, 'tenant', null);
        $byId = array_column($this->usersList(self::BAKERY)['data'], null, 'id');
        self::assertTrue($byId[$neither]['twoStep']['required']);
    }

    // -- display name -------------------------------------------------------

    public function testAWorkspaceCanNameItsOwnPerson(): void
    {
        $id = $this->person('a@example.com', 'Old');
        $this->member($id, self::BAKERY, $this->role('staff', self::BAKERY));

        $response = $this->patchUser($id, self::BAKERY, ['displayName' => '  Rana Haddad ']);

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame('Rana Haddad', $this->displayName($id));
        self::assertSame('Old', $this->updates[0]['previous_display_name']);
    }

    public function testAWorkspaceCannotRenameSomebodyForAnotherWorkspace(): void
    {
        $id = $this->person('a@example.com', 'Old');
        $this->member($id, self::BAKERY, $this->role('staff', self::BAKERY));
        $this->member($id, self::OTHER, $this->role('staff', self::OTHER));

        self::assertSame(409, $this->patchUser($id, self::BAKERY, ['displayName' => 'New'])->getStatusCode());
        self::assertSame('Old', $this->displayName($id));

        self::assertSame(200, $this->patchUser($id, self::SYSTEM, ['displayName' => 'New'])->getStatusCode());
        self::assertSame('New', $this->displayName($id));
    }

    public function testAnEmptyDisplayNameIsRefused(): void
    {
        $id = $this->person('a@example.com', 'Old');
        $this->member($id, self::BAKERY, $this->role('staff', self::BAKERY));

        self::assertSame(422, $this->patchUser($id, self::BAKERY, ['displayName' => '   '])->getStatusCode());
    }

    // -- bulk role change ---------------------------------------------------

    public function testBulkChangesEveryoneAndWritesOneAuditRowEach(): void
    {
        $manager = $this->role('manager', self::BAKERY, ['users:write']);
        $staff = $this->role('staff', self::BAKERY);
        $bakers = $this->role('bakers', self::BAKERY);
        $actor = $this->person('admin@example.com', 'Admin');
        $this->member($actor, self::BAKERY, $manager);
        $a = $this->person('a@example.com', 'A');
        $b = $this->person('b@example.com', 'B');
        $this->member($a, self::BAKERY, $staff);
        $this->member($b, self::BAKERY, $bakers);

        $response = $this->bulkRole($actor, self::BAKERY, $bakers, [['profile_id' => $a], ['profile_id' => $b]]);

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $data = json_decode($response->getBody(), true)['data'];
        self::assertSame([['profile_id' => $a, 'tenant_id' => self::BAKERY]], $data['changed']);
        self::assertSame([['profile_id' => $b, 'tenant_id' => self::BAKERY]], $data['unchanged']);
        self::assertSame($bakers, $this->roleOf($a, self::BAKERY));
        self::assertCount(1, $this->updates);
        self::assertSame($staff, $this->updates[0]['previous_role_id']);
        self::assertSame($bakers, $this->updates[0]['role_id']);
    }

    public function testATenantCallerCannotReachAnotherWorkspace(): void
    {
        $manager = $this->role('manager', self::BAKERY, ['users:write']);
        $staff = $this->role('staff', self::BAKERY);
        $actor = $this->person('admin@example.com', 'Admin');
        $this->member($actor, self::BAKERY, $manager);
        $otherRole = $this->role('staff', self::OTHER);
        $foreign = $this->person('b@example.com', 'B');
        $this->member($foreign, self::OTHER, $otherRole);

        foreach ([[['profile_id' => $foreign]], [['profile_id' => $foreign, 'tenant_id' => self::OTHER]]] as $targets) {
            $response = $this->bulkRole($actor, self::BAKERY, $staff, $targets);
            self::assertSame(422, $response->getStatusCode(), $response->getBody());
            $refused = json_decode($response->getBody(), true)['details']['refused'];
            self::assertSame('not_found', $refused[0]['reason'], 'Reads the same as a person who does not exist.');
        }
        self::assertSame($otherRole, $this->roleOf($foreign, self::OTHER));
    }

    public function testAnotherWorkspacesRoleCannotBeAssignedByAnyone(): void
    {
        $sys = $this->role('operators', self::SYSTEM, ['users:write', 'secret:thing']);
        $operator = $this->person('op@example.com', 'Operator');
        $this->member($operator, self::SYSTEM, $sys);
        $manager = $this->role('manager', self::BAKERY, ['users:write']);
        $actor = $this->person('admin@example.com', 'Admin');
        $this->member($actor, self::BAKERY, $manager);
        $staff = $this->role('staff', self::BAKERY);
        $target = $this->person('a@example.com', 'A');
        $this->member($target, self::BAKERY, $staff);
        $private = $this->role('private', self::OTHER);

        self::assertSame(404, $this->bulkRole($actor, self::BAKERY, $private, [['profile_id' => $target]])->getStatusCode());

        // The system tenant may reach everyone, and still may not plant tenant 2's
        // private role into tenant 1, nor its own role into a customer's workspace.
        self::assertSame(404, $this->bulkRole($operator, self::SYSTEM, $private, [['profile_id' => $target, 'tenant_id' => self::BAKERY]])->getStatusCode());
        self::assertSame(404, $this->bulkRole($operator, self::SYSTEM, $sys, [['profile_id' => $target, 'tenant_id' => self::BAKERY]])->getStatusCode());
        self::assertSame($staff, $this->roleOf($target, self::BAKERY));
    }

    public function testASystemCallerMustNameTheTenantAndOnlyThatOneChanges(): void
    {
        $sys = $this->role('operators', self::SYSTEM, ['users:write']);
        $operator = $this->person('op@example.com', 'Operator');
        $this->member($operator, self::SYSTEM, $sys);
        $bakeryRole = $this->role('staff', self::BAKERY);
        $otherRole = $this->role('staff', self::OTHER);
        $bakers = $this->role('bakers', self::BAKERY);
        $target = $this->person('both@example.com', 'In both');
        $this->member($target, self::BAKERY, $bakeryRole);
        $this->member($target, self::OTHER, $otherRole);

        self::assertSame(422, $this->bulkRole($operator, self::SYSTEM, $bakers, [['profile_id' => $target]])->getStatusCode());

        $response = $this->bulkRole($operator, self::SYSTEM, $bakers, [['profile_id' => $target, 'tenant_id' => self::BAKERY]]);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame($bakers, $this->roleOf($target, self::BAKERY));
        self::assertSame($otherRole, $this->roleOf($target, self::OTHER), 'The same person in another workspace keeps their role.');
    }

    public function testAGlobalRoleIsAllowedOnlyWithinTheCallersOwnAccess(): void
    {
        $manager = $this->role('manager', self::BAKERY, ['users:write']);
        $actor = $this->person('admin@example.com', 'Admin');
        $this->member($actor, self::BAKERY, $manager);
        $staff = $this->role('staff', self::BAKERY);
        $target = $this->person('a@example.com', 'A');
        $this->member($target, self::BAKERY, $staff);

        $modest = $this->role('member', null);
        self::assertSame(200, $this->bulkRole($actor, self::BAKERY, $modest, [['profile_id' => $target]])->getStatusCode());

        $mighty = $this->role('mighty', null, ['users:write', 'roles:manage']);
        $response = $this->bulkRole($actor, self::BAKERY, $mighty, [['profile_id' => $target]]);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame($modest, $this->roleOf($target, self::BAKERY));
    }

    public function testAnInheritedPermissionCountsAgainstTheCeiling(): void
    {
        $manager = $this->role('manager', self::BAKERY, ['users:write']);
        $actor = $this->person('admin@example.com', 'Admin');
        $this->member($actor, self::BAKERY, $manager);
        $staff = $this->role('staff', self::BAKERY);
        $target = $this->person('a@example.com', 'A');
        $this->member($target, self::BAKERY, $staff);
        $parent = $this->role('parent', self::BAKERY, ['roles:manage']);
        $child = $this->role('child', self::BAKERY);
        $this->pdo->prepare('UPDATE roles SET parent_id = ? WHERE id = ?')->execute([$parent, $child]);

        self::assertSame(403, $this->bulkRole($actor, self::BAKERY, $child, [['profile_id' => $target]])->getStatusCode());
    }

    public function testNobodyCanDemoteSomebodyAboveThem(): void
    {
        $manager = $this->role('manager', self::BAKERY, ['users:write']);
        $owner = $this->role('owner', self::BAKERY, ['users:write', 'roles:manage']);
        $staff = $this->role('staff', self::BAKERY);
        $actor = $this->person('admin@example.com', 'Admin');
        $this->member($actor, self::BAKERY, $manager);
        $boss = $this->person('boss@example.com', 'Boss');
        $this->member($boss, self::BAKERY, $owner);

        $response = $this->bulkRole($actor, self::BAKERY, $staff, [['profile_id' => $boss]]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('outranks_you', json_decode($response->getBody(), true)['details']['refused'][0]['reason']);
        self::assertSame($owner, $this->roleOf($boss, self::BAKERY));
    }

    public function testIncludingYourselfRefusesTheWholeChange(): void
    {
        $manager = $this->role('manager', self::BAKERY, ['users:write']);
        $staff = $this->role('staff', self::BAKERY);
        $bakers = $this->role('bakers', self::BAKERY);
        $actor = $this->person('admin@example.com', 'Admin');
        $this->member($actor, self::BAKERY, $manager);
        $target = $this->person('a@example.com', 'A');
        $this->member($target, self::BAKERY, $staff);

        $response = $this->bulkRole($actor, self::BAKERY, $bakers, [['profile_id' => $target], ['profile_id' => $actor]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame($staff, $this->roleOf($target, self::BAKERY), 'All or nothing.');
        self::assertSame($manager, $this->roleOf($actor, self::BAKERY));
        self::assertSame([], $this->updates);
    }

    public function testAWorkspaceCannotLoseItsLastPeopleManager(): void
    {
        $sys = $this->role('operators', self::SYSTEM, ['users:write']);
        $operator = $this->person('op@example.com', 'Operator');
        $this->member($operator, self::SYSTEM, $sys);
        $manager = $this->role('manager', self::BAKERY, ['users:write']);
        $staff = $this->role('staff', self::BAKERY);
        $only = $this->person('only@example.com', 'Only');
        $this->member($only, self::BAKERY, $manager);

        $response = $this->bulkRole($operator, self::SYSTEM, $staff, [['profile_id' => $only, 'tenant_id' => self::BAKERY]]);

        self::assertSame(409, $response->getStatusCode(), $response->getBody());
        self::assertSame($manager, $this->roleOf($only, self::BAKERY), 'Rolled back.');
        self::assertSame([], $this->updates);

        // With a second manager the same change is fine: the test is the capability.
        $second = $this->person('second@example.com', 'Second');
        $this->member($second, self::BAKERY, $this->role('renamed-admin', self::BAKERY, ['users:write']));
        self::assertSame(200, $this->bulkRole($operator, self::SYSTEM, $staff, [['profile_id' => $only, 'tenant_id' => self::BAKERY]])->getStatusCode());
    }

    public function testASecondaryMembershipIsLeftAlone(): void
    {
        $manager = $this->role('manager', self::BAKERY, ['users:write']);
        $staff = $this->role('staff', self::BAKERY);
        $bakers = $this->role('bakers', self::BAKERY);
        $driver = $this->role('driver', self::BAKERY);
        $actor = $this->person('admin@example.com', 'Admin');
        $this->member($actor, self::BAKERY, $manager);
        $target = $this->person('a@example.com', 'A');
        $this->member($target, self::BAKERY, $staff);
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, is_primary, created_at)
             VALUES (?, ?, ?, 'active', FALSE, NOW())"
        )->execute([$target, self::BAKERY, $driver]);

        self::assertSame(200, $this->bulkRole($actor, self::BAKERY, $bakers, [['profile_id' => $target]])->getStatusCode());

        $stmt = $this->pdo->prepare('SELECT role_id FROM memberships WHERE profile_id = ? AND is_primary = FALSE');
        $stmt->execute([$target]);
        self::assertSame($driver, (int) $stmt->fetchColumn());
    }

    public function testTheTargetListIsBounded(): void
    {
        $manager = $this->role('manager', self::BAKERY, ['users:write']);
        $actor = $this->person('admin@example.com', 'Admin');
        $this->member($actor, self::BAKERY, $manager);
        $targets = array_map(static fn (int $i): array => ['profile_id' => $i], range(1, 201));

        self::assertSame(422, $this->bulkRole($actor, self::BAKERY, $manager, $targets)->getStatusCode());
    }

    // -- require two-step ---------------------------------------------------

    public function testRequiringTwoStepWritesAPolicyInTheTargetsWorkspace(): void
    {
        $role = $this->role('staff', self::BAKERY);
        $a = $this->person('a@example.com', 'A');
        $this->member($a, self::BAKERY, $role);

        $this->actAs(self::SYSTEM);
        $response = $this->bulkHandler()->requireTwoStep($this->post(1, self::SYSTEM, ['targets' => [['profile_id' => $a, 'tenant_id' => self::BAKERY]]]));
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame(1, $this->policyCount(self::BAKERY, $a));
        self::assertSame(0, $this->policyCount(self::SYSTEM, $a));

        // Asking again changes nothing.
        $this->actAs(self::BAKERY);
        $again = $this->bulkHandler()->requireTwoStep($this->post(1, self::BAKERY, ['targets' => [['profile_id' => $a]]]));
        self::assertSame(200, $again->getStatusCode());
        self::assertSame([], json_decode($again->getBody(), true)['data']['changed']);
        self::assertSame(1, $this->policyCount(self::BAKERY, $a));
    }

    public function testATenantCallerCannotRequireTwoStepOfAnotherWorkspacesPerson(): void
    {
        $foreign = $this->person('b@example.com', 'B');
        $this->member($foreign, self::OTHER, $this->role('staff', self::OTHER));

        $this->actAs(self::BAKERY);
        $handler = $this->bulkHandler();

        self::assertSame(422, $handler->requireTwoStep($this->post(1, self::BAKERY, ['targets' => [['profile_id' => $foreign]]]))->getStatusCode());
        self::assertSame(422, $handler->setRequirement($this->post(1, self::BAKERY, ['tenant_id' => self::OTHER]), ['id' => (string) $foreign])->getStatusCode());
        self::assertSame(404, $handler->clearRequirement($this->post(1, self::BAKERY, []), ['id' => (string) $foreign])->getStatusCode());
        self::assertSame(0, $this->policyCount(self::OTHER, $foreign));
        self::assertSame(0, $this->policyCount(self::BAKERY, $foreign));
    }

    public function testClearingRemovesOnlyThePolicyNamingThatPerson(): void
    {
        $a = $this->person('a@example.com', 'A');
        $this->member($a, self::BAKERY, $this->role('staff', self::BAKERY));
        $this->policy(self::BAKERY, 'tenant', null);

        $this->actAs(self::BAKERY);
        $handler = $this->bulkHandler();
        self::assertSame(200, $handler->setRequirement($this->post(1, self::BAKERY, []), ['id' => (string) $a])->getStatusCode());
        self::assertSame(1, $this->policyCount(self::BAKERY, $a));
        self::assertSame(200, $handler->clearRequirement($this->post(1, self::BAKERY, []), ['id' => (string) $a])->getStatusCode());
        self::assertSame(0, $this->policyCount(self::BAKERY, $a));

        $row = $this->usersList(self::BAKERY)['data'][0];
        self::assertTrue($row['twoStep']['required'], 'The workspace-wide policy still applies.');
        self::assertFalse($row['twoStep']['requiredDirectly']);
    }

    // -- roles, units -------------------------------------------------------

    public function testPeopleCountOnAGlobalRoleIsThisWorkspacesOnly(): void
    {
        $global = $this->role('shared', null);
        $this->member($this->person('a@example.com', 'A'), self::BAKERY, $global);
        $this->member($this->person('b@example.com', 'B'), self::OTHER, $global);
        $this->member($this->person('c@example.com', 'C'), self::OTHER, $global);
        $invited = $this->person('d@example.com', 'D');
        $this->member($invited, self::BAKERY, $global, null, 'invited');

        self::assertSame(1, $this->roleRow(self::BAKERY, $global)['peopleCount']);
        self::assertSame(2, $this->roleRow(self::OTHER, $global)['peopleCount']);
        self::assertSame(3, $this->roleRow(self::SYSTEM, $global)['peopleCount']);

        self::assertTrue($this->roleRow(self::BAKERY, $global)['builtIn']);
        $own = $this->role('own', self::BAKERY);
        self::assertFalse($this->roleRow(self::BAKERY, $own)['builtIn']);

        $this->actAs(self::BAKERY);
        $record = json_decode((new RolesApiHandler($this->pdo, $this->hooks))->get(new Request('GET', '/api/roles/' . $global), ['id' => (string) $global])->getBody(), true)['data'];
        self::assertSame(1, $record['peopleCount']);
        self::assertTrue($record['builtIn']);
    }

    public function testUnitMemberCountIsActivePeopleInThatUnit(): void
    {
        $role = $this->role('staff', self::BAKERY);
        $unit = $this->unit(self::BAKERY, 'Sweifieh');
        $empty = $this->unit(self::BAKERY, 'Empty');
        $this->member($this->person('a@example.com', 'A'), self::BAKERY, $role, $unit);
        $this->member($this->person('b@example.com', 'B'), self::BAKERY, $role, $unit, 'invited');

        $this->actAs(self::BAKERY);
        $rows = array_column(json_decode((new OusApiHandler($this->pdo, $this->hooks))->list(new Request('GET', '/api/ous'))->getBody(), true)['data'], null, 'id');
        self::assertSame(1, $rows[$unit]['member_count']);
        self::assertSame(0, $rows[$empty]['member_count']);

        $this->actAs(self::OTHER);
        $none = json_decode((new OusApiHandler($this->pdo, $this->hooks))->list(new Request('GET', '/api/ous'))->getBody(), true)['data'];
        self::assertSame([], $none);
    }

    // -- delegations --------------------------------------------------------

    public function testAnExpiredDelegationGrantsNothingAndLeavesTheLiveList(): void
    {
        [$grantor, $grantee] = $this->delegationPeople();
        $this->grantRole('staff-b', 'users:read', self::BAKERY);

        $live = $this->delegationRow($grantor, $grantee, 'users:read', '+1 day');
        $checker = $this->checker();
        self::assertContains('users:read', $checker->getEffectivePermissionsForProfile($grantee, self::BAKERY));

        $this->pdo->prepare('UPDATE permission_delegations SET expires_at = ? WHERE id = ?')
            ->execute([gmdate('Y-m-d H:i:s', time() - 60), $live]);
        RoleChecker::clearCache();
        self::assertNotContains('users:read', $this->checker()->getEffectivePermissionsForProfile($grantee, self::BAKERY));

        $this->actAs(self::BAKERY);
        self::assertSame([], $this->delegationsList()['data']);
        $all = $this->delegationsList('?includeRevoked=1')['data'];
        self::assertSame('expired', $all[0]['status']);
    }

    public function testCreatingWithAnEndDate(): void
    {
        [$grantor, $grantee] = $this->delegationPeople();
        $this->actAs(self::BAKERY);

        $past = $this->delegationsHandler()->create($this->post($grantor, self::BAKERY, [
            'granteeType' => 'user', 'granteeId' => $grantee, 'permissions' => ['users:read'], 'expiresAt' => '2020-01-01T00:00:00Z',
        ]));
        self::assertSame(422, $past->getStatusCode());
        $garbage = $this->delegationsHandler()->create($this->post($grantor, self::BAKERY, [
            'granteeType' => 'user', 'granteeId' => $grantee, 'permissions' => ['users:read'], 'expiresAt' => 'soon',
        ]));
        self::assertSame(422, $garbage->getStatusCode());

        $at = gmdate('Y-m-d\TH:i:s\Z', time() + 86400);
        $response = $this->delegationsHandler()->create($this->post($grantor, self::BAKERY, [
            'granteeType' => 'user', 'granteeId' => $grantee, 'permissions' => ['users:read'], 'expiresAt' => $at,
        ]));
        self::assertSame(201, $response->getStatusCode(), $response->getBody());

        $row = $this->delegationsList()['data'][0];
        self::assertSame('active', $row['status']);
        self::assertSame(gmdate('Y-m-d H:i:s', (int) strtotime($at)), substr((string) $row['expiresAt'], 0, 19));
    }

    public function testDelegationsCarryNamesFromTheirOwnWorkspaceOnly(): void
    {
        [$grantor, $grantee] = $this->delegationPeople();
        $unit = $this->unit(self::BAKERY, 'Sweifieh');
        $role = $this->role('bakers', self::BAKERY);
        $this->delegationRow($grantor, $grantee, 'users:read', null, $unit);
        $this->delegationRow($grantor, $role, 'roles:read', null, null, 'role');

        // Somebody with no membership here, and a role only another workspace can see.
        $stranger = $this->person('stranger@example.com', 'Stranger');
        $privateRole = $this->role('private', self::OTHER);
        $this->delegationRow($grantor, $stranger, 'users:write');
        $this->delegationRow($grantor, $privateRole, 'users:delete', null, null, 'role');

        $this->actAs(self::BAKERY);
        $rows = array_column($this->delegationsList()['data'], null, 'permission');

        self::assertSame('Grantor', $rows['users:read']['grantorName']);
        self::assertSame('Grantee', $rows['users:read']['granteeName']);
        self::assertSame('Sweifieh', $rows['users:read']['ouName']);
        self::assertSame('bakers', $rows['roles:read']['granteeName']);
        self::assertNull($rows['users:write']['granteeName'], 'A person elsewhere is not named here.');
        self::assertNull($rows['users:delete']['granteeName'], "Another workspace's role is not named here.");
    }

    public function testRevokeThenUndoPutsItBack(): void
    {
        [$grantor, $grantee] = $this->delegationPeople();
        $id = $this->delegationRow($grantor, $grantee, 'users:read');

        $this->actAs(self::BAKERY);
        self::assertSame(200, $this->delegationsHandler()->revoke($this->post($grantor, self::BAKERY, []), ['id' => (string) $id])->getStatusCode());
        RoleChecker::clearCache();
        self::assertNotContains('users:read', $this->checker()->getEffectivePermissionsForProfile($grantee, self::BAKERY));

        self::assertSame(200, $this->delegationsHandler()->restore($this->post($grantor, self::BAKERY, []), ['id' => (string) $id])->getStatusCode());
        RoleChecker::clearCache();
        self::assertContains('users:read', $this->checker()->getEffectivePermissionsForProfile($grantee, self::BAKERY));

        self::assertSame(404, $this->delegationsHandler()->restore($this->post($grantor, self::BAKERY, []), ['id' => (string) $id])->getStatusCode(), 'A live row has nothing to undo.');
    }

    public function testOnlyTheRevokerCanUndoAndOnlyForAShortTime(): void
    {
        [$grantor, $grantee] = $this->delegationPeople();
        $colleague = $this->person('colleague@example.com', 'Colleague');
        $this->member($colleague, self::BAKERY, $this->role('admins', self::BAKERY, ['delegation:manage']));
        $id = $this->delegationRow($grantor, $grantee, 'users:read');

        $this->actAs(self::BAKERY);
        $this->delegationsHandler()->revoke($this->post($grantor, self::BAKERY, []), ['id' => (string) $id]);

        self::assertSame(409, $this->delegationsHandler()->restore($this->post($colleague, self::BAKERY, []), ['id' => (string) $id])->getStatusCode());

        $this->pdo->prepare('UPDATE permission_delegations SET revoked_at = ? WHERE id = ?')
            ->execute([gmdate('Y-m-d H:i:s', time() - DelegationService::RESTORE_WINDOW_SECONDS - 60), $id]);
        self::assertSame(409, $this->delegationsHandler()->restore($this->post($grantor, self::BAKERY, []), ['id' => (string) $id])->getStatusCode());
    }

    public function testUndoCannotBringBackWhatTheGrantorNoLongerHolds(): void
    {
        [$grantor, $grantee] = $this->delegationPeople();
        $id = $this->delegationRow($grantor, $grantee, 'users:read');

        $this->actAs(self::BAKERY);
        $this->delegationsHandler()->revoke($this->post($grantor, self::BAKERY, []), ['id' => (string) $id]);
        $this->pdo->prepare('UPDATE memberships SET role_id = ? WHERE profile_id = ?')
            ->execute([$this->role('nothing', self::BAKERY), $grantor]);
        RoleChecker::clearCache();

        self::assertSame(409, $this->delegationsHandler()->restore($this->post($grantor, self::BAKERY, []), ['id' => (string) $id])->getStatusCode());
    }

    public function testUndoCannotPassAnEndDateOrReachAnotherWorkspace(): void
    {
        [$grantor, $grantee] = $this->delegationPeople();
        $id = $this->delegationRow($grantor, $grantee, 'users:read', '+1 day');

        $this->actAs(self::BAKERY);
        $this->delegationsHandler()->revoke($this->post($grantor, self::BAKERY, []), ['id' => (string) $id]);

        $this->actAs(self::OTHER);
        self::assertSame(404, $this->delegationsHandler()->restore($this->post($grantor, self::OTHER, []), ['id' => (string) $id])->getStatusCode());

        $this->actAs(self::BAKERY);
        $this->pdo->prepare('UPDATE permission_delegations SET expires_at = ? WHERE id = ?')
            ->execute([gmdate('Y-m-d H:i:s', time() - 60), $id]);
        self::assertSame(409, $this->delegationsHandler()->restore($this->post($grantor, self::BAKERY, []), ['id' => (string) $id])->getStatusCode());
    }

    // -- inbox --------------------------------------------------------------

    public function testAnInboxItemNamesItsSenderIfTheyBelongHere(): void
    {
        $role = $this->role('staff', self::BAKERY);
        $me = $this->person('me@example.com', 'Me');
        $this->member($me, self::BAKERY, $role);
        $colleague = $this->person('dana@example.com', 'Dana Yousef');
        $this->member($colleague, self::BAKERY, $role);
        $stranger = $this->person('stranger@example.com', 'Stranger');

        $this->inboxItem(101, self::BAKERY, $me, $colleague);
        $this->inboxItem(102, self::BAKERY, $me, $stranger);
        $this->inboxItem(103, self::BAKERY, $me, null);

        $source = new DocumentRoutingInboxSource(new RouteRecipientRepository($this->pdo));
        // Newest first, which is the order the source returns.
        $items = array_map(static fn ($item): array => $item->toArray(), $source->list(self::BAKERY, $me, true, 10, 0));
        self::assertSame(['103', '102', '101'], array_column($items, 'resource_id'));

        self::assertSame(['kind' => 'person', 'profile_id' => $colleague, 'name' => 'Dana Yousef'], $items[2]['sender']);
        self::assertNull($items[1]['sender'], 'Somebody with no membership here is not named.');
        self::assertNull($items[0]['sender']);
        self::assertNull($items[2]['meta']['closed_by']);
    }

    public function testAClosedInboxItemSaysHowItWasDecided(): void
    {
        $role = $this->role('staff', self::BAKERY);
        $me = $this->person('me@example.com', 'Me');
        $this->member($me, self::BAKERY, $role);
        $this->inboxItem(201, self::BAKERY, $me, null);
        $this->pdo->exec(
            "INSERT INTO document_route_events (id, tenant_id, document_id, route_id, step_id, action, verdict)
             VALUES (202, " . self::BAKERY . ", 201, 201, 201, 'acknowledged', 'rejected')"
        );
        $this->pdo->exec('UPDATE document_route_recipients SET closed_by_event_id = 202 WHERE document_id = 201');

        $source = new DocumentRoutingInboxSource(new RouteRecipientRepository($this->pdo));
        $items = $source->list(self::BAKERY, $me, false, 10, 0);

        self::assertCount(1, $items);
        self::assertSame('rejected', $items[0]->toArray()['meta']['closed_verdict']);
    }

    // -- approval queues ----------------------------------------------------

    public function testPasswordResetQueuePagesWithinTheWorkspaceAndCarriesTheReason(): void
    {
        $role = $this->role('staff', self::BAKERY);
        $service = new PasswordResetService($this->pdo);
        foreach (['a', 'b', 'c'] as $name) {
            $id = $this->person($name . '@example.com', strtoupper($name));
            $this->member($id, self::BAKERY, $role);
            $token = $service->issue($id);
            self::assertNotNull($service->confirm($token, 'A-long-enough-password-1', true, 'Lost my phone ' . $name));
        }
        $foreign = $this->person('z@example.com', 'Z');
        $this->member($foreign, self::OTHER, $this->role('staff', self::OTHER));
        $service->confirm($service->issue($foreign), 'A-long-enough-password-1', true);

        self::assertSame(3, $service->countPendingForTenant(self::BAKERY));
        self::assertSame(1, $service->countPendingForTenant(self::OTHER));
        self::assertSame(4, $service->countPendingForTenant(self::SYSTEM));

        $page = $service->listPendingForTenant(self::BAKERY, 2, 1);
        self::assertSame(['Lost my phone b', 'Lost my phone c'], array_column($page, 'reason'));
        self::assertNull($service->listPendingForTenant(self::OTHER)[0]['reason']);
    }

    public function testTwoStepResetQueuePagesWithinTheWorkspaceAndCarriesTheReason(): void
    {
        $role = $this->role('staff', self::BAKERY);
        $service = new TwoFactorRecoveryService($this->pdo, new PasswordResetService($this->pdo), new BackupCodesService($this->db));
        foreach (['a', 'b'] as $name) {
            $id = $this->person($name . '@example.com', strtoupper($name));
            $this->member($id, self::BAKERY, $role);
            self::assertNotNull($service->confirm($service->issue($id), 'New phone ' . $name));
        }
        $foreign = $this->person('z@example.com', 'Z');
        $this->member($foreign, self::OTHER, $this->role('staff', self::OTHER));
        $service->confirm($service->issue($foreign));

        self::assertSame(2, $service->countPendingForTenant(self::BAKERY));
        self::assertSame(['New phone b'], array_column($service->listPendingForTenant(self::BAKERY, 1, 1), 'reason'));
        self::assertSame(1, $service->countPendingForTenant(self::OTHER));
    }

    public function testRegistrationsPageForTheSystemTenantOnly(): void
    {
        $role = $this->role('owner', null, ['registrations:approve']);
        $operator = $this->person('op@example.com', 'Operator');
        $this->member($operator, self::SYSTEM, $role);
        foreach ([11, 12, 13] as $tenant) {
            $this->pdo->prepare('INSERT INTO tenants (id, name) VALUES (?, ?)')->execute([$tenant, 'pending-' . $tenant]);
            $owner = $this->person('o' . $tenant . '@example.com', 'O' . $tenant);
            $this->member($owner, $tenant, $role, null, 'invited');
        }

        $this->actAs(self::SYSTEM);
        $handler = new RegistrationsApiHandler($this->pdo, $this->checker());
        $request = new Request('GET', '/api/registrations/pending?per_page=2&page=2');
        $request->user = (object) ['profile_id' => $operator, 'tenant_id' => self::SYSTEM];
        $body = json_decode($handler->listPending($request)->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame(3, $body['pagination']['total']);

        $elsewhere = $this->person('t@example.com', 'T');
        $this->member($elsewhere, self::BAKERY, $role);
        $this->actAs(self::BAKERY);
        $tenantRequest = new Request('GET', '/api/registrations/pending');
        $tenantRequest->user = (object) ['profile_id' => $elsewhere, 'tenant_id' => self::BAKERY];
        self::assertSame(403, $handler->listPending($tenantRequest)->getStatusCode());
    }

    // -- navigation ---------------------------------------------------------

    public function testAnAnyOfNavItemShowsForAnyOneOfItsPermissions(): void
    {
        $hooks = new HookManager();
        $hooks->listen('navigation.register', static function (array $data): array {
            $data['items'][] = ['id' => 'approval-gating', 'label' => 'Approval gating', 'href' => '/x', 'requiredAnyPermission' => ['password_resets:approve', 'two_factor_recovery:approve']];

            return $data;
        });
        $one = $this->person('one@example.com', 'One');
        $this->member($one, self::BAKERY, $this->role('renamed', self::BAKERY, ['two_factor_recovery:approve']));
        $none = $this->person('none@example.com', 'None');
        $this->member($none, self::BAKERY, $this->role('plain', self::BAKERY));

        $this->actAs(self::BAKERY);
        $handler = new NavigationApiHandler($hooks, $this->checker());
        foreach ([[$one, 1], [$none, 0]] as [$profile, $expected]) {
            $request = new Request('GET', '/api/navigation');
            $request->user = (object) ['profile_id' => $profile, 'tenant_id' => self::BAKERY];
            self::assertCount($expected, json_decode($handler->list($request)->getBody(), true)['data']);
        }
    }

    // -- gates and migrations -----------------------------------------------

    public function testNewRoutesAreGatedOnTheRightPermission(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        foreach ([
            "'/api/users/bulk/role',             [\$usersBulkHandler, 'changeRole'],     null, null, CorePermissions::USERS_WRITE",
            "'/api/users/bulk/require-two-step', [\$usersBulkHandler, 'requireTwoStep'], null, null, CorePermissions::SECURITY_MANAGE",
            "[\$usersBulkHandler, 'setRequirement'],   null, null, CorePermissions::SECURITY_MANAGE",
            "[\$usersBulkHandler, 'clearRequirement'], null, null, CorePermissions::SECURITY_MANAGE",
            "[\$userGroupsHandler, 'usage'],         null, null, CorePermissions::GROUPS_READ",
            "[\$delegationsHandler, 'restore'], null, null, CorePermissions::DELEGATION_MANAGE",
        ] as $registration) {
            self::assertStringContainsString($registration, $source);
        }
    }

    public function testMigrationsReverseOnPostgres(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            self::markTestSkipped('DROP COLUMN IF EXISTS is PostgreSQL syntax; runs in the real-engine job.');
        }
        $root = dirname(__DIR__, 2) . '/database/migrations/';
        require_once $root . '208_add_delegation_expiry.php';
        require_once $root . '209_add_approval_request_reasons.php';

        foreach ([
            [\Database\Migrations\AddDelegationExpiry::class, 'permission_delegations', ['expires_at', 'revoked_by_profile_id']],
            [\Database\Migrations\AddApprovalRequestReasons::class, 'password_resets', ['reason']],
            [\Database\Migrations\AddApprovalRequestReasons::class, 'two_factor_recovery_requests', ['reason']],
        ] as [$class, $table, $columns]) {
            $class::down($this->db);
            foreach ($columns as $column) {
                self::assertFalse($this->hasColumn($table, $column), $table . '.' . $column . ' after down()');
            }
            $class::up($this->db);
            foreach ($columns as $column) {
                self::assertTrue($this->hasColumn($table, $column), $table . '.' . $column . ' after up()');
            }
        }
    }

    // -- fixtures -----------------------------------------------------------

    private function hasColumn(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?');
        $stmt->execute([$table, $column]);

        return $stmt->fetchColumn() !== false;
    }

    private function actAs(int $tenantId): void
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
    }

    private function checker(): RoleChecker
    {
        $registry = new PermissionRegistry();
        $checker = new RoleChecker($this->db, $registry);
        $service = new DelegationService(new DelegationRepository($this->pdo), $checker, $registry);

        return new RoleChecker($this->db, $registry, null, $service);
    }

    private function usersHandler(): UsersApiHandler
    {
        return new UsersApiHandler($this->pdo, $this->hooks);
    }

    private function bulkHandler(): UsersBulkApiHandler
    {
        return new UsersBulkApiHandler($this->pdo, $this->checker(), $this->hooks);
    }

    private function delegationsHandler(): DelegationsApiHandler
    {
        $registry = new PermissionRegistry();

        return new DelegationsApiHandler(
            $this->pdo,
            new DelegationService(new DelegationRepository($this->pdo), new RoleChecker($this->db, $registry), $registry)
        );
    }

    /**
     * @return array{data: list<array<string, mixed>>, pagination: array<string, int>}
     */
    private function usersList(int $tenantId, string $query = ''): array
    {
        $this->actAs($tenantId);
        $response = $this->usersHandler()->list(new Request('GET', '/api/users' . $query));
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        return json_decode($response->getBody(), true);
    }

    /**
     * @return array{data: list<array<string, mixed>>, pagination: array<string, int>}
     */
    private function delegationsList(string $query = ''): array
    {
        $response = $this->delegationsHandler()->list(new Request('GET', '/api/delegations' . $query));
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        return json_decode($response->getBody(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function roleRow(int $tenantId, int $roleId): array
    {
        $this->actAs($tenantId);
        $rows = json_decode((new RolesApiHandler($this->pdo, $this->hooks))->list(new Request('GET', '/api/roles?per_page=100'))->getBody(), true)['data'];

        return array_column($rows, null, 'id')[$roleId];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(int $profileId, int $tenantId, array $body): Request
    {
        $request = new Request('POST', '/api/x', [], (string) json_encode($body));
        $request->user = (object) ['profile_id' => $profileId, 'tenant_id' => $tenantId];

        return $request;
    }

    /**
     * @param list<array<string, int>> $targets
     */
    private function bulkRole(int $actor, int $tenantId, int $roleId, array $targets): Response
    {
        $this->actAs($tenantId);
        RoleChecker::clearCache();

        return $this->bulkHandler()->changeRole($this->post($actor, $tenantId, ['role_id' => $roleId, 'targets' => $targets]));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patchUser(int $id, int $tenantId, array $body): Response
    {
        $this->actAs($tenantId);

        return $this->usersHandler()->update($this->post(1, $tenantId, $body), ['id' => (string) $id]);
    }

    private function person(string $email, string $name): int
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, 'x', FALSE, 0, 0, NOW(), NOW())"
        )->execute([$name]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO profile_emails (profile_id, email, is_primary, created_at) VALUES (?, ?, TRUE, NOW())')
            ->execute([$id, $email]);

        return $id;
    }

    private function member(int $profileId, int $tenantId, int $roleId, ?int $ouId = null, string $status = 'active'): void
    {
        $this->pdo->prepare(
            'INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$profileId, $tenantId, $roleId, $ouId, $status]);
    }

    /**
     * @param list<string> $permissions
     */
    private function role(string $name, ?int $tenantId, array $permissions = []): int
    {
        $this->pdo->prepare("INSERT INTO roles (name, description, tenant_id, created_at) VALUES (?, '', ?, NOW())")
            ->execute([$name, $tenantId]);
        $id = (int) $this->pdo->lastInsertId();
        foreach ($permissions as $permission) {
            $this->grantTo($id, $permission);
        }

        return $id;
    }

    private function grantTo(int $roleId, string $permission): void
    {
        $this->pdo->prepare('INSERT INTO permissions (name, description, created_at) VALUES (?, NULL, NOW()) ON CONFLICT DO NOTHING')
            ->execute([$permission]);
        $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $stmt->execute([$permission]);
        $this->pdo->prepare('INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW()) ON CONFLICT DO NOTHING')
            ->execute([$roleId, (int) $stmt->fetchColumn()]);
    }

    private function grantRole(string $name, string $permission, int $tenantId): void
    {
        $this->grantTo($this->role($name, $tenantId), $permission);
    }

    private function unit(int $tenantId, string $name, ?int $parentId = null): int
    {
        $this->pdo->prepare('INSERT INTO organizational_units (tenant_id, parent_id, name, slug, created_at) VALUES (?, ?, ?, ?, NOW())')
            ->execute([$tenantId, $parentId, $name, strtolower(str_replace(' ', '-', $name))]);

        return (int) $this->pdo->lastInsertId();
    }

    private function session(int $profileId, int $tenantId, string $lastSeen): void
    {
        $this->pdo->prepare(
            'INSERT INTO sessions (profile_id, tenant_id, refresh_jti, created_at, last_seen_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$profileId, $tenantId, bin2hex(random_bytes(8)), $lastSeen, $lastSeen, '2099-01-01 00:00:00']);
    }

    private function policy(int $tenantId, string $scopeType, ?int $scopeId): void
    {
        $this->pdo->prepare(
            'INSERT INTO two_factor_policies (tenant_id, scope_type, scope_id, grace_period_days, created_at, updated_at) VALUES (?, ?, ?, 0, NOW(), NOW())'
        )->execute([$tenantId, $scopeType, $scopeId]);
    }

    private function policyCount(int $tenantId, int $profileId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM two_factor_policies WHERE tenant_id = ? AND scope_type = 'user' AND scope_id = ?");
        $stmt->execute([$tenantId, $profileId]);

        return (int) $stmt->fetchColumn();
    }

    private function roleOf(int $profileId, int $tenantId): int
    {
        $stmt = $this->pdo->prepare('SELECT role_id FROM memberships WHERE profile_id = ? AND tenant_id = ? AND is_primary');
        $stmt->execute([$profileId, $tenantId]);

        return (int) $stmt->fetchColumn();
    }

    private function displayName(int $profileId): string
    {
        $stmt = $this->pdo->prepare('SELECT display_name FROM profiles WHERE id = ?');
        $stmt->execute([$profileId]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * A grantor holding users:read, roles:read, users:write, users:delete and a grantee.
     *
     * @return array{0: int, 1: int}
     */
    private function delegationPeople(): array
    {
        $grantor = $this->person('grantor@example.com', 'Grantor');
        $this->member($grantor, self::BAKERY, $this->role('leads', self::BAKERY, ['users:read', 'roles:read', 'users:write', 'users:delete', 'delegation:manage']));
        $grantee = $this->person('grantee@example.com', 'Grantee');
        $this->member($grantee, self::BAKERY, $this->role('plain', self::BAKERY));

        return [$grantor, $grantee];
    }

    private function delegationRow(int $grantor, int $granteeId, string $permission, ?string $until = null, ?int $ouId = null, string $type = 'profile'): int
    {
        return (new DelegationRepository($this->pdo))->insert(
            self::BAKERY,
            $grantor,
            $type,
            $granteeId,
            $permission,
            $ouId,
            $until !== null ? gmdate('Y-m-d H:i:s', (int) strtotime($until)) : null
        );
    }

    private function inboxItem(int $id, int $tenantId, int $recipient, ?int $actor): void
    {
        $this->pdo->prepare("INSERT INTO documents (id, tenant_id, template_name, title, created_by) VALUES (?, ?, 'T', ?, ?)")
            ->execute([$id, $tenantId, 'Doc ' . $id, $recipient]);
        $this->pdo->prepare('INSERT INTO document_routes (id, tenant_id, document_id, title) VALUES (?, ?, ?, ?)')
            ->execute([$id, $tenantId, $id, 'R']);
        $this->pdo->prepare("INSERT INTO document_route_steps (id, tenant_id, route_id, position, rule_kind, rule_config) VALUES (?, ?, ?, 1, 'role', '{}')")
            ->execute([$id, $tenantId, $id]);
        $this->pdo->prepare("INSERT INTO document_route_events (id, tenant_id, document_id, route_id, step_id, actor_profile_id, action) VALUES (?, ?, ?, ?, ?, ?, 'issued')")
            ->execute([$id, $tenantId, $id, $id, $id, $actor]);
        $this->pdo->prepare('INSERT INTO document_route_recipients (tenant_id, document_id, route_id, step_id, profile_id, created_by_event_id) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$tenantId, $id, $id, $id, $recipient, $id]);
    }
}
