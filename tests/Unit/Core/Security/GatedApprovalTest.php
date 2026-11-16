<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Security;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Signing\SigningChallengeRepository;
use Whity\Core\Security\GatedApproval;
use Whity\Core\Security\IdentityCheck;

/**
 * One contract for gated approvals (AG.1): what is approved is part of the
 * check, so a code for one approval never approves another.
 */
final class GatedApprovalTest extends TestCase
{
    public function testThePurposeNamesTheKindAndTheSubject(): void
    {
        self::assertSame('password-reset:12', GatedApproval::purpose(GatedApproval::PASSWORD_RESET, '12'));
        self::assertSame('2fa-recovery:request:12', GatedApproval::purpose(GatedApproval::TWO_FACTOR_RECOVERY, 'request:12'));
        self::assertSame('agent-proposal:12', GatedApproval::purpose(GatedApproval::AGENT_PROPOSAL, '12'));
    }

    public function testTheSameIdOfAnotherKindIsAnotherPurpose(): void
    {
        $kinds = [GatedApproval::PASSWORD_RESET, GatedApproval::TWO_FACTOR_RECOVERY, GatedApproval::AGENT_PROPOSAL];
        $purposes = array_map(static fn (string $kind): string => GatedApproval::purpose($kind, '7'), $kinds);

        self::assertCount(3, array_unique($purposes));
    }

    public function testAnUnknownKindOrAnEmptySubjectIsRefused(): void
    {
        foreach ([['plugin-install', '1'], [GatedApproval::PASSWORD_RESET, '']] as [$kind, $subject]) {
            try {
                GatedApproval::purpose($kind, $subject);
                self::fail("{$kind}:{$subject} must be refused");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testStartAndConfirmCarryTheBoundPurpose(): void
    {
        $identity = new class extends IdentityCheck {
            /** @var list<string> */
            public array $seen = [];

            public function __construct()
            {
                parent::__construct(new PDO('sqlite::memory:'), new SigningChallengeRepository(new PDO('sqlite::memory:')));
            }

            /** @return array{method: string, email_hint: ?string} */
            public function start(int $tenantId, int $profileId, string $purpose, string $what): array
            {
                $this->seen[] = 'start ' . $purpose . ' / ' . $what;

                return ['method' => self::TOTP, 'email_hint' => null];
            }

            public function verify(int $tenantId, int $profileId, string $purpose, ?string $code): ?string
            {
                $this->seen[] = 'verify ' . $purpose;

                return $purpose === 'password-reset:5' && $code === '123456' ? self::TOTP : null;
            }
        };
        $gate = new GatedApproval($identity);

        self::assertSame(['method' => IdentityCheck::TOTP, 'email_hint' => null], $gate->start(1, 2, GatedApproval::PASSWORD_RESET, '5'));
        self::assertSame(IdentityCheck::TOTP, $gate->confirm(1, 2, GatedApproval::PASSWORD_RESET, '5', '123456'));
        self::assertNull($gate->confirm(1, 2, GatedApproval::AGENT_PROPOSAL, '5', '123456'));
        self::assertSame([
            'start password-reset:5 / approving a password reset',
            'verify password-reset:5',
            'verify agent-proposal:5',
        ], $identity->seen);
    }
}
