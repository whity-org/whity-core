<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\OwnershipException;
use Whity\Core\Tenant\WorkspaceCreator;
use Whity\Core\Tenant\WorkspaceOwnership;
use Whity\Sdk\Hooks\Events;

/**
 * Handing a workspace over and adding co-owners (WHIT-1170), on the real
 * schema: offers, acceptance under the lock, leaving, expiry, and isolation.
 */
final class WorkspaceOwnershipRealEngineTest extends TestCase
{
    private PDO $pdo;
    private WorkspaceOwnership $ownership;
    private WorkspaceCreator $creator;
    private HookManager $hooks;
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $settings->setGlobal(SettingsRegistry::WORKSPACES_SELF_CREATE_ENABLED, 'true');
        $this->creator = new WorkspaceCreator($this->pdo, $settings);
        $this->hooks = new HookManager();
        foreach ([Events::WORKSPACE_OWNERSHIP_TRANSFERRED, Events::WORKSPACE_OWNER_ADDED, Events::WORKSPACE_OWNER_REMOVED] as $event) {
            $this->hooks->listen($event, function (array $payload) use ($event): array {
                $this->events[] = [$event, $payload];
                return $payload;
            });
        }
        $this->ownership = new WorkspaceOwnership($this->pdo, null, $this->hooks);
        RoleChecker::clearCache();
    }

    public function testATransferHappensOnlyWhenAccepted(): void
    {
        [$tenantId, $amro] = $this->workspace('Amro Saleh');
        $awss = $this->memberOf($tenantId, 'Awss Haddad');

        $offer = $this->ownership->offer($tenantId, $amro, $awss, WorkspaceOwnership::KIND_TRANSFER);

        self::assertSame('open', $offer['status']);
        self::assertTrue($this->ownership->owners()->isOwner($tenantId, $amro));
        self::assertFalse($this->ownership->owners()->isOwner($tenantId, $awss));

        $accepted = $this->ownership->accept($offer['id'], $awss);

        self::assertSame('accepted', $accepted['status']);
        self::assertTrue($this->ownership->owners()->isOwner($tenantId, $awss));
        self::assertFalse($this->ownership->owners()->isOwner($tenantId, $amro));
        // The old owner stays an admin.
        self::assertTrue($this->ownership->isStaff($tenantId, $amro));
        self::assertSame(
            [Events::WORKSPACE_OWNER_ADDED, Events::WORKSPACE_OWNER_REMOVED, Events::WORKSPACE_OWNERSHIP_TRANSFERRED],
            array_column($this->events, 0)
        );
        self::assertSame(
            ['tenant_id' => $tenantId, 'from_profile_id' => $amro, 'to_profile_id' => $awss, 'offer_id' => $offer['id'], 'from_left' => false],
            $this->events[2][1]
        );
    }

    public function testTheGiverCanChooseToLeave(): void
    {
        [$tenantId, $amro] = $this->workspace('Amro Saleh');
        $awss = $this->memberOf($tenantId, 'Awss Haddad');

        $offer = $this->ownership->offer($tenantId, $amro, $awss, WorkspaceOwnership::KIND_TRANSFER, true);
        $this->ownership->accept($offer['id'], $awss);

        self::assertFalse($this->ownership->isStaff($tenantId, $amro));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM memberships WHERE tenant_id = ? AND profile_id = ?', [$tenantId, $amro]));
    }

    public function testACoOwnerJoinsTheOwners(): void
    {
        [$tenantId, $amro] = $this->workspace('Amro Saleh');
        $awss = $this->memberOf($tenantId, 'Awss Haddad');

        $offer = $this->ownership->offer($tenantId, $amro, $awss, WorkspaceOwnership::KIND_CO_OWNER, true);
        $this->ownership->accept($offer['id'], $awss);

        self::assertSame([$amro, $awss], array_column($this->ownership->owners()->list($tenantId), 'profile_id'));
        // "Leave" means nothing for a co-owner offer.
        self::assertTrue($this->ownership->isStaff($tenantId, $amro));
    }

    public function testWhoMayBeOffered(): void
    {
        [$tenantId, $amro] = $this->workspace('Amro Saleh');
        $awss = $this->memberOf($tenantId, 'Awss Haddad');
        $customer = $this->memberOf($tenantId, 'A Customer', 'customer');
        $outsider = $this->profile('Ibrahim Mansour');

        $this->assertRefused(OwnershipException::SELF, fn () => $this->ownership->offer($tenantId, $amro, $amro, WorkspaceOwnership::KIND_TRANSFER));
        $this->assertRefused(OwnershipException::NOT_MEMBER, fn () => $this->ownership->offer($tenantId, $amro, $customer, WorkspaceOwnership::KIND_TRANSFER));
        $this->assertRefused(OwnershipException::NOT_MEMBER, fn () => $this->ownership->offer($tenantId, $amro, $outsider, WorkspaceOwnership::KIND_TRANSFER));
        $this->assertRefused(OwnershipException::NOT_OWNER, fn () => $this->ownership->offer($tenantId, $awss, $amro, WorkspaceOwnership::KIND_TRANSFER));

        $this->ownership->offer($tenantId, $amro, $awss, WorkspaceOwnership::KIND_CO_OWNER);
        $this->assertRefused(OwnershipException::PENDING, fn () => $this->ownership->offer($tenantId, $amro, $awss, WorkspaceOwnership::KIND_TRANSFER));
    }

    public function testOnlyThePersonOfferedCanAcceptOrDecline(): void
    {
        [$tenantId, $amro] = $this->workspace('Amro Saleh');
        $awss = $this->memberOf($tenantId, 'Awss Haddad');
        $ahmad = $this->memberOf($tenantId, 'Ahmad Khalil');
        $offer = $this->ownership->offer($tenantId, $amro, $awss, WorkspaceOwnership::KIND_TRANSFER);

        $this->assertRefused(OwnershipException::NOT_FOUND, fn () => $this->ownership->accept($offer['id'], $ahmad));
        $this->assertRefused(OwnershipException::NOT_FOUND, fn () => $this->ownership->decline($offer['id'], $amro));

        self::assertTrue($this->ownership->decline($offer['id'], $awss));
        $this->assertRefused(OwnershipException::NOT_OPEN, fn () => $this->ownership->accept($offer['id'], $awss));
        self::assertTrue($this->ownership->owners()->isOwner($tenantId, $amro));
    }

    public function testAWithdrawnOfferCannotBeAccepted(): void
    {
        [$tenantId, $amro] = $this->workspace('Amro Saleh');
        $awss = $this->memberOf($tenantId, 'Awss Haddad');
        $offer = $this->ownership->offer($tenantId, $amro, $awss, WorkspaceOwnership::KIND_TRANSFER);

        $this->assertRefused(OwnershipException::NOT_OWNER, fn () => $this->ownership->cancel($tenantId, $offer['id'], $awss));
        self::assertTrue($this->ownership->cancel($tenantId, $offer['id'], $amro));
        $this->assertRefused(OwnershipException::NOT_OPEN, fn () => $this->ownership->accept($offer['id'], $awss));
    }

    public function testAnExpiredOfferCannotBeAccepted(): void
    {
        [$tenantId, $amro] = $this->workspace('Amro Saleh');
        $awss = $this->memberOf($tenantId, 'Awss Haddad');
        $offer = $this->ownership->offer($tenantId, $amro, $awss, WorkspaceOwnership::KIND_TRANSFER);
        $this->pdo->prepare('UPDATE workspace_ownership_offers SET expires_at = :past WHERE id = :id')
            ->execute([':past' => '2020-01-01 00:00:00', ':id' => $offer['id']]);

        $this->assertRefused(OwnershipException::EXPIRED, fn () => $this->ownership->accept($offer['id'], $awss));
        self::assertSame('expired', $this->ownership->find($offer['id'])['status'] ?? null);
        self::assertSame([], $this->ownership->openForPerson($awss));
        self::assertFalse($this->ownership->owners()->isOwner($tenantId, $awss));
    }

    public function testAnOfferFromSomeoneNoLongerOwnerIsVoid(): void
    {
        [$tenantId, $amro] = $this->workspace('Amro Saleh');
        $awss = $this->memberOf($tenantId, 'Awss Haddad');
        $ahmad = $this->memberOf($tenantId, 'Ahmad Khalil');
        $toAwss = $this->ownership->offer($tenantId, $amro, $awss, WorkspaceOwnership::KIND_TRANSFER);
        $toAhmad = $this->ownership->offer($tenantId, $amro, $ahmad, WorkspaceOwnership::KIND_TRANSFER);

        $this->ownership->accept($toAwss['id'], $awss);

        $this->assertRefused(OwnershipException::NOT_OPEN, fn () => $this->ownership->accept($toAhmad['id'], $ahmad));
        self::assertSame('void', $this->ownership->find($toAhmad['id'])['status'] ?? null);
        self::assertFalse($this->ownership->owners()->isOwner($tenantId, $ahmad));
    }

    public function testOffersStayInTheirWorkspace(): void
    {
        [$tenantA, $amro] = $this->workspace('Amro Saleh');
        [$tenantB, $emad] = $this->workspace('Emad Nasser');
        $awss = $this->memberOf($tenantA, 'Awss Haddad');
        $offer = $this->ownership->offer($tenantA, $amro, $awss, WorkspaceOwnership::KIND_CO_OWNER);

        self::assertSame([], $this->ownership->openForTenant($tenantB));
        self::assertNull($this->ownership->findInTenant($tenantB, $offer['id']));
        // An owner of another workspace finds nothing to withdraw there.
        self::assertFalse($this->ownership->cancel($tenantB, $offer['id'], $emad));
        self::assertSame('open', $this->ownership->find($offer['id'])['status'] ?? null);
        self::assertSame([$offer['id']], array_column($this->ownership->openForPerson($awss), 'id'));
    }

    /** @return array{0: int, 1: int} tenant id and its owner */
    private function workspace(string $ownerName): array
    {
        $owner = $this->profile($ownerName);
        $tenantId = (int) $this->creator->createFor($owner, $ownerName . '’s workspace')['tenant_id'];
        return [$tenantId, $owner];
    }

    private function memberOf(int $tenantId, string $name, string $kind = 'member'): int
    {
        $profileId = $this->profile($name);
        $roleId = (int) $this->scalar("SELECT id FROM roles WHERE name = 'admin' AND tenant_id IS NULL", []);
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, is_primary, status, kind, created_at)
             VALUES (:p, :t, :r, NULL, true, 'active', :k, NOW())"
        )->execute([':p' => $profileId, ':t' => $tenantId, ':r' => $roleId, ':k' => $kind]);
        return $profileId;
    }

    private function profile(string $name): int
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled, two_factor_secret,
                 two_factor_backup_codes_version, token_epoch, status, created_at, updated_at)
             VALUES (:n, '', false, NULL, 0, 0, 'active', NOW(), NOW())"
        )->execute([':n' => $name]);
        $id = (int) $this->pdo->lastInsertId();
        (new ProfileEmailRepository($this->pdo))->insert($id, strtolower(str_replace(' ', '.', $name)) . '.' . $id . '@example.test', true, true);
        return $id;
    }

    /** @param list<int|string> $args */
    private function countRows(string $sql, array $args): int
    {
        return (int) $this->scalar($sql, $args);
    }

    /** @param list<int|string> $args */
    private function scalar(string $sql, array $args): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchColumn();
    }

    private function assertRefused(string $reason, callable $act): void
    {
        try {
            $act();
        } catch (OwnershipException $e) {
            self::assertSame($reason, $e->reason);
            return;
        }
        self::fail('Expected the ownership change to be refused with ' . $reason);
    }
}
