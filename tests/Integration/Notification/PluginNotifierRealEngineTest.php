<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Mail\EmailGate;
use Whity\Core\Notification\CoreTransports;
use Whity\Core\Notification\HostPluginNotifier;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Notification\NotificationPreferenceResolver;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Notification\TenantNotificationSettingsRepository;
use Whity\Core\Plugin\PluginIdentity;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
use Whity\Core\Sandbox\SideEffectBlockedException;
use Whity\Core\Sandbox\SideEffectGuard;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Sdk\Notification\Notice;
use Whity\Sdk\Notification\Notifier;
use Whity\Sdk\Notification\PluginNotificationsInterface;
use Whity\Sdk\PluginInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The plugin Notifier (WHIT-1030), against the migration-built schema: bound to
 * the current workspace, reaching only its active members, respecting opt-outs,
 * and unable to pose as a core notice.
 */
final class PluginNotifierRealEngineTest extends TestCase
{
    private const WORKSPACE = 1;
    private const OTHER_WORKSPACE = 2;
    private const ROLE = 11;
    private const OTHER_ROLE = 12;
    private const SALES = 21;
    private const SALES_NORTH = 22;
    private const OTHER_UNIT = 23;

    private const ALICE = 101;
    private const BASSAM = 102;
    private const DEPARTED = 103;
    private const STRANGER = 104;

    private PDO $pdo;
    private ?int $tenant = self::WORKSPACE;
    private NotificationPreferenceRepository $preferences;
    private string $workspaceLanguage = 'en';

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Cafe', 'example-cafe') ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO roles (id, tenant_id, name) VALUES (11, 1, 'Baker'), (12, 2, 'Barista')");
        $this->pdo->exec("INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug) VALUES (21, 1, NULL, 'Sales', 'sales'), (22, 1, 21, 'North', 'north'), (23, 2, NULL, 'Elsewhere', 'elsewhere')");
        foreach ([self::ALICE, self::BASSAM, self::DEPARTED, self::STRANGER] as $id) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash) VALUES ({$id}, 'Person {$id}', 'x')");
        }
        $this->pdo->exec("UPDATE profiles SET language_code = 'ar' WHERE id = " . self::BASSAM);
        $this->member(self::ALICE, self::WORKSPACE, self::ROLE, self::SALES, 'active');
        $this->member(self::BASSAM, self::WORKSPACE, self::ROLE, self::SALES_NORTH, 'active');
        $this->member(self::DEPARTED, self::WORKSPACE, self::ROLE, self::SALES, 'suspended');
        $this->member(self::STRANGER, self::OTHER_WORKSPACE, self::OTHER_ROLE, self::OTHER_UNIT, 'active');

        $this->preferences = new NotificationPreferenceRepository($this->pdo);
    }

    public function testAPersonInTheWorkspaceIsNotifiedInTheirLanguageUnderTheNamespacedType(): void
    {
        self::assertSame(1, $this->notifier()->toPerson('order.shipped', self::BASSAM, self::notice()));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('notifier_acme:order.shipped', $rows[0]['type']);
        self::assertSame(self::WORKSPACE, (int) $rows[0]['tenant_id']);
        self::assertSame(self::BASSAM, (int) $rows[0]['recipient_profile_id']);
        self::assertSame('تم الشحن', $rows[0]['subject']);
    }

    public function testARoleReachesItsActiveMembersOnly(): void
    {
        self::assertSame(2, $this->notifier()->toRole('order.shipped', self::ROLE, self::notice()));

        self::assertSame([self::ALICE, self::BASSAM], $this->recipients());
    }

    public function testAUnitReachesItsMembersAndTheUnitsBelow(): void
    {
        self::assertSame(2, $this->notifier()->toUnit('order.shipped', self::SALES, self::notice()));
        self::assertSame([self::ALICE, self::BASSAM], $this->recipients());
    }

    public function testNobodyOutsideTheWorkspaceCanBeReached(): void
    {
        $notifier = $this->notifier();

        self::assertSame(0, $notifier->toPerson('order.shipped', self::STRANGER, self::notice()));
        self::assertSame(0, $notifier->toRole('order.shipped', self::OTHER_ROLE, self::notice()));
        self::assertSame(0, $notifier->toUnit('order.shipped', self::OTHER_UNIT, self::notice()));
        self::assertSame(0, $notifier->toPerson('order.shipped', self::DEPARTED, self::notice()));
        self::assertSame([], $this->rows());
    }

    public function testTheWorkspaceIsTheCurrentOneAtSendTime(): void
    {
        $notifier = $this->notifier();
        $this->tenant = self::OTHER_WORKSPACE;

        self::assertSame(1, $notifier->toPerson('order.shipped', self::STRANGER, self::notice()));
        self::assertSame(self::OTHER_WORKSPACE, (int) $this->rows()[0]['tenant_id']);
    }

    #[DataProvider('noWorkspace')]
    public function testSendingWithNoWorkspaceIsRefused(?int $tenant): void
    {
        $this->tenant = $tenant;

        $this->expectException(\RuntimeException::class);
        $this->notifier()->toPerson('order.shipped', self::ALICE, self::notice());
    }

    /** @return iterable<string, array{?int}> */
    public static function noWorkspace(): iterable
    {
        yield 'none' => [null];
        yield 'the system tenant' => [0];
    }

    #[DataProvider('undeclaredTypes')]
    public function testOnlyTheDeclaredTypesCanBeSent(string $type): void
    {
        try {
            $this->notifier()->toPerson($type, self::ALICE, self::notice());
            self::fail('An undeclared type was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->rows());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function undeclaredTypes(): iterable
    {
        yield 'never declared' => ['order.lost'];
        yield 'a security notice' => ['security.breach'];
        yield 'a billing notice' => ['billing.overdue'];
        yield 'another plugin' => ['other:order.shipped'];
    }

    public function testADeclaredTypeNamedLikeASecurityNoticeStillRespectsOptOuts(): void
    {
        $this->preferences->set(self::WORKSPACE, self::ALICE, 'notifier_acme:security.alert', 'in_app', false);

        $this->notifier()->toPerson('security.alert', self::ALICE, self::notice());

        self::assertFalse((new NotificationPreferenceResolver($this->preferences))->isTransactional('notifier_acme:security.alert'));
        self::assertSame(0, $this->deliveries());
    }

    public function testAnOptOutStopsTheDeliveryAndANeighbourStillGetsIt(): void
    {
        $this->preferences->set(self::WORKSPACE, self::ALICE, 'notifier_acme:order.shipped', 'in_app', false);

        $this->notifier()->toRole('order.shipped', self::ROLE, self::notice());

        self::assertSame(1, $this->deliveries());
    }

    public function testAStubCarryingALoadedPluginsNameIsRefused(): void
    {
        $this->notifier();

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->for(self::plugin('notifier-acme'));
    }

    public function testANoticeMissingALanguageIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // @phpstan-ignore argument.type (the host must refuse what the PHPDoc shape would catch)
        $this->notifier()->toPerson('order.shipped', self::ALICE, new Notice(['en' => 'Shipped'], ['en' => 'Sent', 'ar' => 'أرسل']));
    }

    public function testAProposalPreviewSendsNothing(): void
    {
        $notifier = $this->notifier();

        $this->expectException(SideEffectBlockedException::class);
        SideEffectGuard::blocking('a proposal preview', fn () => $notifier->toPerson('order.shipped', self::ALICE, self::notice()));
    }

    /**
     * @param array<mixed> $types
     */
    #[DataProvider('malformedDeclarations')]
    public function testAMalformedDeclarationIsRefusedWhole(array $types): void
    {
        $plugin = self::plugin('notifier-bad', $types);
        PluginIdentity::bind($plugin, 'notifier-bad');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->for($plugin);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function malformedDeclarations(): iterable
    {
        $label = ['label' => ['en' => 'A', 'ar' => 'ا']];
        yield 'a colon in the key' => [['core:x' => $label]];
        yield 'no arabic' => [['fine' => ['label' => ['en' => 'A']]]];
        yield 'one good, one bad' => [['fine' => $label, 'Bad Key' => $label]];
        yield 'emailDefault not a boolean' => [['fine' => $label + ['emailDefault' => 'yes']]];
    }

    // ---- #2209: plugin notices by email, per each person's preferences ----

    public function testATypeThatDefaultsToEmailReachesTheInboxAndEmail(): void
    {
        self::assertSame(1, $this->notifier()->toPerson('task.assigned', self::ALICE, self::notice()));

        self::assertSame(['email', 'in_app'], $this->channels(self::ALICE));
    }

    public function testATypeWithoutEmailDefaultStaysInTheInbox(): void
    {
        $this->notifier()->toPerson('order.shipped', self::ALICE, self::notice());

        self::assertSame(['in_app'], $this->channels(self::ALICE));
    }

    public function testTurningEmailOffForTheTypeWinsOverThePluginDefault(): void
    {
        $this->preferences->set(self::WORKSPACE, self::ALICE, 'notifier_acme:task.assigned', 'email', false);

        $this->notifier()->toRole('task.assigned', self::ROLE, self::notice());

        self::assertSame(['in_app'], $this->channels(self::ALICE));
        self::assertSame(['email', 'in_app'], $this->channels(self::BASSAM));
    }

    public function testTurningEmailOnForATypeWithoutTheDefaultSendsIt(): void
    {
        $this->preferences->set(self::WORKSPACE, self::ALICE, 'notifier_acme:order.shipped', 'email', true);

        $this->notifier()->toRole('order.shipped', self::ROLE, self::notice());

        self::assertSame(['email', 'in_app'], $this->channels(self::ALICE));
        self::assertSame(['in_app'], $this->channels(self::BASSAM));
    }

    public function testTurningEveryEmailOffStopsAPluginsDefault(): void
    {
        $this->preferences->set(self::WORKSPACE, self::ALICE, '*', 'email', false);

        $this->notifier()->toPerson('task.assigned', self::ALICE, self::notice());

        self::assertSame(['in_app'], $this->channels(self::ALICE));
    }

    public function testAWorkspaceWithEmailOffStillDeliversInTheInbox(): void
    {
        (new TenantNotificationSettingsRepository($this->pdo))->upsertConfig(self::WORKSPACE, 'email', ['enabled' => false]);

        self::assertSame(1, $this->notifier()->toPerson('task.assigned', self::ALICE, self::notice()));

        self::assertSame(['in_app'], $this->channels(self::ALICE));
    }

    public function testTheEmailCarriesTheNoticeTextInThePersonsLanguageAndNamesOnlyTheProfile(): void
    {
        $this->notifier()->toPerson('task.assigned', self::BASSAM, self::notice());

        $email = $this->emailJob();
        self::assertSame('تم الشحن', $email['subject']);
        self::assertSame('في الطريق.', $email['body']);
        self::assertSame('ar', $email['locale']);
        // The plugin names a profile; the transport finds the address.
        self::assertSame((string) self::BASSAM, $email['recipient']);
    }

    public function testAPersonWithoutALanguageGetsTheWorkspaces(): void
    {
        $this->workspaceLanguage = 'ar';

        $this->notifier()->toPerson('task.assigned', self::ALICE, self::notice());

        self::assertSame('تم الشحن', $this->rows()[0]['subject']);
        self::assertSame('ar', $this->emailJob()['locale']);
    }

    public function testAnInactiveMemberGetsNoEmailEither(): void
    {
        self::assertSame(0, $this->notifier()->toPerson('task.assigned', self::DEPARTED, self::notice()));
        self::assertSame(0, $this->deliveries());
    }

    /** @return list<string> */
    private function channels(int $profile): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.channel FROM notification_deliveries d
               JOIN notifications n ON n.id = d.notification_id
              WHERE n.recipient_profile_id = :profile
              ORDER BY d.channel'
        );
        $stmt->execute([':profile' => $profile]);

        /** @var list<string> */
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<string, mixed> the queued email delivery's payload */
    private function emailJob(): array
    {
        foreach (self::statement($this->pdo->query('SELECT payload FROM jobs'))->fetchAll(PDO::FETCH_COLUMN) as $payload) {
            $job = json_decode((string) $payload, true);
            if (is_array($job) && ($job['channel'] ?? null) === 'email') {
                /** @var array<string, mixed> */
                return $job;
            }
        }
        self::fail('No email delivery was queued.');
    }

    private function member(int $profile, int $tenant, int $role, int $unit, string $status): void
    {
        $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status) VALUES ({$profile}, {$tenant}, {$role}, {$unit}, '{$status}')");
    }

    private function notifier(): Notifier
    {
        $plugin = self::plugin('notifier-acme');
        PluginIdentity::bind($plugin, 'notifier-acme');

        return $this->service()->for($plugin);
    }

    private function service(): HostPluginNotifier
    {
        $dispatcher = new NotificationDispatcher(
            new NotificationRepository($this->pdo),
            CoreTransports::make(),
            new QueueService(new JobRepository($this->pdo)),
            null,
            null,
            new NotificationPreferenceResolver($this->preferences),
            null,
            new EmailGate(new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo)), $this->pdo),
        );

        return new HostPluginNotifier(
            $this->pdo,
            $dispatcher,
            null,
            fn (): ?int => $this->tenant,
            null,
            fn (int $tenantId): string => $this->workspaceLanguage,
        );
    }

    private static function notice(): Notice
    {
        return new Notice(['en' => 'Shipped', 'ar' => 'تم الشحن'], ['en' => 'On its way.', 'ar' => 'في الطريق.'], ['order' => 7]);
    }

    /** @param array<mixed>|null $types */
    private static function plugin(string $name, ?array $types = null): PluginInterface&PluginNotificationsInterface
    {
        $types ??= [
            'order.shipped' => ['label' => ['en' => 'Order shipped', 'ar' => 'تم شحن الطلب']],
            'security.alert' => ['label' => ['en' => 'Lookalike', 'ar' => 'مشابه']],
            'task.assigned' => ['label' => ['en' => 'A task was given to you', 'ar' => 'أُسندت إليك مهمة'], 'emailDefault' => true],
        ];

        return new class ($name, $types) implements PluginInterface, PluginNotificationsInterface {
            /** @param array<mixed> $types */
            public function __construct(private string $name, private array $types)
            {
            }

            public function getName(): string
            {
                return $this->name;
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

            public function getNotificationTypes(): array
            {
                return $this->types;
            }
        };
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        /** @var list<array<string, mixed>> */
        return self::statement($this->pdo->query('SELECT * FROM notifications ORDER BY recipient_profile_id'))->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<int> */
    private function recipients(): array
    {
        return array_map(static fn (array $row): int => (int) $row['recipient_profile_id'], $this->rows());
    }

    private function deliveries(): int
    {
        return (int) self::statement($this->pdo->query('SELECT COUNT(*) FROM notification_deliveries'))->fetchColumn();
    }

    private static function statement(\PDOStatement|false $statement): \PDOStatement
    {
        self::assertNotFalse($statement);

        return $statement;
    }
}
