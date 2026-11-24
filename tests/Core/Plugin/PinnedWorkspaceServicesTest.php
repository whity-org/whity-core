<?php

declare(strict_types=1);

namespace Tests\Core\Plugin;

use PHPUnit\Framework\TestCase;
use Whity\Core\Plugin\HostPluginContext;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Calendar\Bookings;
use Whity\Sdk\Calendar\TimeOff;
use Whity\Sdk\Contacts\Contact;
use Whity\Sdk\Contacts\Contacts;
use Whity\Sdk\Context\PluginScope;
use Whity\Sdk\Context\ServiceUnavailable;
use Whity\Sdk\Delegation\Away;
use Whity\Sdk\PluginInterface;

/**
 * `$ctx->contacts()`, `timeOff()`, `away()` and `bookings()` (SDK 1.161, D2):
 * each call reaches the explicit service with the workspace it runs in, never
 * one the plugin names, and refuses to run without one.
 */
final class PinnedWorkspaceServicesTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    /** @var list<array{string, int}> service method => workspace it was given */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->saved = $GLOBALS['whity_services'] ?? [];
        TenantContext::reset();
        $this->bindRecorders();
    }

    protected function tearDown(): void
    {
        $GLOBALS['whity_services'] = $this->saved;
        TenantContext::reset();
    }

    public function testEachServiceGetsTheWorkspaceTheCallRunsIn(): void
    {
        $scope = $this->scope();

        self::inWorkspace(7);
        $scope->contacts()->get(3);
        $scope->contacts()->findByField('keyhub.code', 'DK-1');
        $scope->contacts()->list(['is_supplier' => true]);
        $scope->timeOff()->forPerson(4, '2026-11-24T00:00:00Z', '2026-11-25T00:00:00Z');
        $scope->timeOff()->byExternalRef('keyhub.leave:1');
        $scope->away()->at(4, '2026-11-24T09:00:00Z');
        $scope->bookings()->release(9, 'not paid');
        self::inWorkspace(8);
        $scope->timeOff()->create(4, '2026-11-24T00:00:00Z', '2026-11-25T00:00:00Z', 'Leave', 5, 'keyhub.leave:2');
        $scope->timeOff()->cancel(11, 5);
        $scope->bookings()->confirmPaid(9, 'pay_1');

        self::assertSame([
            ['contacts.get', 7], ['contacts.findByField', 7], ['contacts.list', 7],
            ['timeOff.forPerson', 7], ['timeOff.byExternalRef', 7], ['away.at', 7], ['bookings.release', 7],
            ['timeOff.create', 8], ['timeOff.cancel', 8], ['bookings.confirmPaid', 8],
        ], $this->calls);
    }

    public function testWithoutAWorkspaceNothingIsCalled(): void
    {
        $scope = $this->scope();
        foreach ([
            static fn () => $scope->contacts()->get(1),
            static fn () => $scope->timeOff()->byExternalRef('x'),
            static fn () => $scope->away()->at(1, '2026-11-24T09:00:00Z'),
            static fn () => $scope->bookings()->release(1, 'x'),
        ] as $call) {
            try {
                $call();
                self::fail('a call without a workspace ran');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('needs a workspace', $e->getMessage());
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testAServiceThisHostDoesntBindIsUnavailable(): void
    {
        unset($GLOBALS['whity_services'][Away::class]);

        $this->expectException(ServiceUnavailable::class);
        $this->expectExceptionMessage(Away::class);
        $this->scope()->away();
    }

    private function scope(): PluginScope
    {
        return (new HostPluginContext())->for(new class () implements PluginInterface {
            public function getName(): string
            {
                return 'KeyHub';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getRoutes(): array
            {
                return [];
            }

            public function getPermissions(): array
            {
                return [];
            }

            public function getHooks(): array
            {
                return [];
            }

            public function getMigrations(): array
            {
                return [];
            }
        });
    }

    private function bindRecorders(): void
    {
        $record = function (string $call, int $workspace): void {
            $this->calls[] = [$call, $workspace];
        };
        $contact = new Contact(3, 'Example Bakery', true, false);

        \Whity\register_service(Contacts::class, new class ($record, $contact) implements Contacts {
            public function __construct(private readonly \Closure $record, private readonly Contact $contact)
            {
            }

            public function list(int $tenantId, array $filter = [], int $limit = 50, int $offset = 0): array
            {
                ($this->record)('contacts.list', $tenantId);
                return [];
            }

            public function get(int $tenantId, int $contactId): ?Contact
            {
                ($this->record)('contacts.get', $tenantId);
                return null;
            }

            public function findByField(int $tenantId, string $fieldKey, string $value): ?Contact
            {
                ($this->record)('contacts.findByField', $tenantId);
                return null;
            }

            public function create(int $tenantId, array $data, int $actingProfileId): Contact
            {
                ($this->record)('contacts.create', $tenantId);
                return $this->contact;
            }

            public function update(int $tenantId, int $contactId, array $changes, int $actingProfileId): Contact
            {
                ($this->record)('contacts.update', $tenantId);
                return $this->contact;
            }
        });

        \Whity\register_service(TimeOff::class, new class ($record) implements TimeOff {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function create(int $tenantId, int $profileId, string $startUtc, string $endUtc, string $reason, int $actingProfileId, ?string $externalRef = null): int
            {
                ($this->record)('timeOff.create', $tenantId);
                return 1;
            }

            public function cancel(int $tenantId, int $timeOffId, int $actingProfileId): bool
            {
                ($this->record)('timeOff.cancel', $tenantId);
                return true;
            }

            public function forPerson(int $tenantId, int $profileId, string $fromUtc, string $toUtc): array
            {
                ($this->record)('timeOff.forPerson', $tenantId);
                return [];
            }

            public function byExternalRef(int $tenantId, string $externalRef): ?array
            {
                ($this->record)('timeOff.byExternalRef', $tenantId);
                return null;
            }
        });

        \Whity\register_service(Away::class, new class ($record) implements Away {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function at(int $tenantId, int $profileId, string $atUtc): ?array
            {
                ($this->record)('away.at', $tenantId);
                return null;
            }
        });

        \Whity\register_service(Bookings::class, new class ($record) implements Bookings {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function confirmPaid(int $tenantId, int $bookingId, string $paymentRef): bool
            {
                ($this->record)('bookings.confirmPaid', $tenantId);
                return true;
            }

            public function release(int $tenantId, int $bookingId, string $reason): bool
            {
                ($this->record)('bookings.release', $tenantId);
                return true;
            }
        });
    }

    private static function inWorkspace(int $workspace): void
    {
        TenantContext::reset();
        TenantContext::setTenantId($workspace);
    }
}
