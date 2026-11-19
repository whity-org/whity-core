<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Core\Identity\FederatedIdentityLinker;
use Whity\Core\Identity\FederatedProviderContext;
use Whity\Core\Identity\InvitationService;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Identity\ProfileProvisioner;
use Whity\Core\Identity\TenantEmailDomainsRepository;
use Whity\Core\Identity\UnconfirmedEmailClaimException;
use Whity\Sdk\Auth\ExternalIdentity;

/**
 * #2245: an address somebody only CLAIMED (added to their own account, never
 * confirmed) must never link another person's membership, invitation or
 * sign-in to them, and must not block the real owner either.
 *
 * The attack: the squatter adds victim@example.test to their own account
 * (POST /api/me/emails, unconfirmed). Before the fix, an admin creating that
 * address as a user, an invitation to it, or the victim's first sign-in all
 * resolved the address to the squatter's profile, or refused the victim.
 */
final class UnconfirmedEmailClaimRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const ROLE_ADMIN = 1;
    private const VICTIM = 'victim@example.test';

    private PDO $pdo;
    private int $squatterId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'System'), (1, 'tenant-a'), (2, 'tenant-b')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin'), (2, 'user')");

        // The squatter: a member of A who added the victim's address, unconfirmed.
        $this->squatterId = $this->seedProfile('squatter@example.test');
        (new MembershipRepository($this->pdo))->insert($this->squatterId, self::TENANT_A, 2);
        $this->addEmail($this->squatterId, self::VICTIM, false, false);

        SchemaFromMigrations::syncSequences($this->pdo);
    }

    public function testAnAdminAddingTheAddressCreatesTheRealOwnerNotTheSquatter(): void
    {
        $provisioner = new ProfileProvisioner($this->pdo);

        self::assertNull($provisioner->verifiedOwner(self::VICTIM));

        $profileId = $provisioner->findOrCreate(self::VICTIM, password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]));

        self::assertNotSame($this->squatterId, $profileId);
        self::assertSame($profileId, $provisioner->verifiedOwner(self::VICTIM));
        self::assertSame(0, $this->emailCountFor($this->squatterId, self::VICTIM), 'the unconfirmed claim is released');
    }

    public function testAnUnconfirmedSignInAddressOfAnotherAccountIsRefusedNotTaken(): void
    {
        $other = $this->seedProfile('registered-unconfirmed@example.test', false);

        $this->expectException(UnconfirmedEmailClaimException::class);
        try {
            (new ProfileProvisioner($this->pdo))->findOrCreate('registered-unconfirmed@example.test', 'hash');
        } finally {
            self::assertSame(1, $this->emailCountFor($other, 'registered-unconfirmed@example.test'), 'a sign-in address is never deleted');
        }
    }

    public function testAcceptingAnInvitationNeverJoinsTheSquatter(): void
    {
        $invitations = new InvitationService($this->pdo, new ProfileProvisioner($this->pdo));
        $invite = $invitations->invite(self::TENANT_B, self::VICTIM, self::ROLE_ADMIN);
        self::assertSame(InvitationService::INVITE_CREATED, $invite['result']);

        // The preview asks for a password: the address has no confirmed owner.
        $preview = $invitations->preview($invite['token']);
        self::assertIsArray($preview);
        self::assertTrue($preview['requires_password']);

        $accepted = $invitations->accept($invite['token'], password_hash('a-long-password', PASSWORD_BCRYPT, ['cost' => 4]));
        self::assertSame(InvitationService::ACCEPT_JOINED, $accepted['result']);
        self::assertNotSame($this->squatterId, $accepted['profile_id']);
        self::assertSame(0, $this->membershipCount($this->squatterId, self::TENANT_B), 'the squatter must not join B');
    }

    public function testTheOperatorProviderSignsTheRealOwnerInDespiteTheClaim(): void
    {
        $result = $this->linker()->resolveForLogin(
            new ExternalIdentity('https://id.example.test', 'victim-subject', self::VICTIM, true, 'Victim'),
            new FederatedProviderContext(0, 'whity-id', 0),
        );

        self::assertSame('provisioned', $result['status']);
        self::assertNotSame($this->squatterId, $result['profile_id'] ?? null);
        self::assertSame(0, $this->emailCountFor($this->squatterId, self::VICTIM));
    }

    private function linker(): FederatedIdentityLinker
    {
        return new FederatedIdentityLinker(
            $this->pdo,
            new ExternalIdentityRepository($this->pdo),
            new ProfileEmailRepository($this->pdo),
            new MembershipRepository($this->pdo),
            new TenantEmailDomainsRepository($this->pdo),
        );
    }

    private function seedProfile(string $email, bool $verified = true): int
    {
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Someone', 'x', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $id = (int) $this->pdo->lastInsertId();
        $this->addEmail($id, $email, $verified, true);

        return $id;
    }

    private function addEmail(int $profileId, string $email, bool $verified, bool $primary): void
    {
        (new ProfileEmailRepository($this->pdo))->insert($profileId, $email, $verified, $primary);
    }

    private function emailCountFor(int $profileId, string $email): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM profile_emails WHERE profile_id = ? AND email = ?');
        $stmt->execute([$profileId, $email]);

        return (int) $stmt->fetchColumn();
    }

    private function membershipCount(int $profileId, int $tenantId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM memberships WHERE profile_id = ? AND tenant_id = ?');
        $stmt->execute([$profileId, $tenantId]);

        return (int) $stmt->fetchColumn();
    }
}
