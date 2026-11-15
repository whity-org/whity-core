<?php

declare(strict_types=1);

namespace Tests\Integration\Notification\Push;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecipientProfiles;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Mail\EmailGate;
use Whity\Core\Notification\CoreTransports;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Notification\Push\Base64Url;
use Whity\Core\Notification\Push\PushBranding;
use Whity\Core\Notification\Push\PushDigestJob;
use Whity\Core\Notification\Push\PushSubscriptionRepository;
use Whity\Core\Notification\Push\PushTransport;
use Whity\Core\Notification\Push\VapidKeys;
use Whity\Core\Notification\TenantNotificationSettingsRepository;
use Whity\Core\Queue\CoreJobs;
use Whity\Core\Queue\JobRegistry;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\JobRunner;
use Whity\Core\Queue\QueueService;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;

/**
 * The `push` channel end to end on a real database (WHIT-1074): dispatch, the
 * queue, the worker's delivery job, encryption, the VAPID signature, and a
 * push service that decrypts what it gets the way a browser would. Who gets a
 * push follows the email gate; a burst is held to a digest; gone devices go.
 */
final class PushChannelRealEngineTest extends TestCase
{
    private const KEY = 'push_test_key_0123456789abcdef0123456789';
    private const TENANT = 1;
    private const AMRO = 101;
    private const AWSS = 202;

    private PDO $pdo;
    private FakePushService $service;
    private NotificationDispatcher $dispatcher;
    private JobRunner $runner;
    private PushTransport $transport;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        RecipientProfiles::seed($this->pdo);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");

        $keys = new VapidKeys(new GlobalSettingsRepository($this->pdo), new EncryptedSecretStore(['v1' => self::KEY], 'v1'));
        $this->service = new FakePushService($keys->publicKey());
        $queue = new QueueService(new JobRepository($this->pdo));
        $this->transport = new PushTransport(
            $this->pdo,
            new PushSubscriptionRepository($this->pdo),
            static fn (): VapidKeys => $keys,
            $this->service,
            new DatabaseSharedStore($this->pdo),
            $queue,
            'https://app.example.test',
            null,
            PushBranding::fromPdo($this->pdo),
            fn (): int => $this->now,
        );

        $transports = CoreTransports::make();
        $transports->register($this->transport);
        $gate = new EmailGate(new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo)), $this->pdo);
        $this->dispatcher = new NotificationDispatcher(new NotificationRepository($this->pdo), $transports, $queue, null, null, null, null, $gate);

        $registry = new JobRegistry();
        CoreJobs::register($registry, $this->pdo, $transports);
        $this->runner = new JobRunner(new JobRepository($this->pdo), $registry);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function device(int $profileId, string $id): FakeBrowser
    {
        $browser = new FakeBrowser('https://fcm.googleapis.com/fcm/send/' . $id);
        (new PushSubscriptionRepository($this->pdo))->upsert(self::TENANT, $profileId, $browser->endpoint, $browser->p256dh(), Base64Url::encode($browser->auth), 'Chrome');
        $this->service->browsers[$browser->endpoint] = $browser;

        return $browser;
    }

    /** @param array<string, mixed> $data */
    private function notify(int $profileId, string $type = 'document.routing.awaiting', string $subject = 'Leave request is waiting for you', array $data = ['document_id' => 42]): int
    {
        return $this->dispatcher->dispatch(self::TENANT, $profileId, $type, ['subject' => $subject, 'body' => 'Open it to approve or decline.', 'data' => $data]);
    }

    private function drain(): void
    {
        $ran = 0;
        while ($this->runner->processNext('default')) {
            if (++$ran > 50) {
                self::fail('queue did not drain');
            }
        }
    }

    /** @return array<string, string> */
    private function statuses(int $notificationId): array
    {
        $out = [];
        foreach ((new NotificationRepository($this->pdo))->listDeliveries(self::TENANT, $notificationId) as $d) {
            $out[$d['channel']] = $d['status'] . ($d['provider_id'] !== null ? ':' . $d['provider_id'] : '');
        }

        return $out;
    }

    public function testAPersonWithADeviceGetsTheNotificationEncryptedAndSigned(): void
    {
        $phone = $this->device(self::AMRO, 'phone');
        $id = $this->notify(self::AMRO);
        $this->drain();

        self::assertSame('sent:push:sent', $this->statuses($id)['push']);
        self::assertCount(1, $phone->received);
        $push = $phone->received[0];
        self::assertSame('Example Bakery · Leave request is waiting for you', $push['title']);
        self::assertSame('Open it to approve or decline.', $push['body']);
        self::assertSame('/admin/document-library/42', $push['url']);
        self::assertSame('n' . $id, $push['tag']);
        self::assertSame('ltr', $push['dir']);
        self::assertSame('/push-icon/icon?l=E', $push['icon'], 'no logo: its initial, drawn');
        self::assertSame('/push-icon/badge?l=E', $push['badge']);
        self::assertSame('https://fcm.googleapis.com', $this->service->audiences[0]);
        self::assertSame('aes128gcm', $this->service->headers[0]['Content-Encoding']);
    }

    public function testAWorkspaceWithItsOwnNameAndLogoShowsThem(): void
    {
        $this->pdo->exec("INSERT INTO tenant_settings (tenant_id, setting_key, value) VALUES
            (1, 'site_name', 'AAU IRB'),
            (1, 'branding_logo_square', 'tenants/1/branding/logo-square.png'),
            (1, 'branding_main_color', '#1d6f42')");
        $phone = $this->device(self::AMRO, 'phone');
        $this->notify(self::AMRO, 'document.routing.awaiting', 'Application waiting for pre-check');
        $this->drain();

        $push = $phone->received[0];
        self::assertSame('AAU IRB · Application waiting for pre-check', $push['title']);
        self::assertSame('/api/v1/branding/asset/1/logo-square.png', $push['icon']);
        self::assertSame('/push-icon/badge?l=A', $push['badge'], 'a silhouette, never the colour logo');
    }

    public function testWithoutALogoTheIconIsItsInitialOnItsColour(): void
    {
        self::assertSame('/push-icon/icon?l=E&c=b74c05', PushBranding::drawnIcon('Example Bakery', '#B74C05'));
        self::assertSame('/push-icon/icon?l=E', PushBranding::drawnIcon('Example Bakery', null));
        self::assertSame('/push-icon/icon', PushBranding::drawnIcon('Whity', '#0961bb'));
        self::assertSame('/push-icon/icon', PushBranding::drawnIcon('مخبز', null), 'a letter the icon font cannot draw');
        self::assertSame('/push-icon/badge', PushBranding::badge('مخبز'));
    }

    public function testThePushIsInThePersonsLanguage(): void
    {
        $this->pdo->exec("UPDATE profiles SET language_code = 'ar' WHERE id = " . self::AMRO);
        $phone = $this->device(self::AMRO, 'phone');
        $this->notify(self::AMRO, 'automation.notice', 'Order shipped', ['subjectAr' => 'تم شحن الطلب', 'bodyAr' => 'افتحه للتفاصيل']);
        $this->drain();

        self::assertSame('Example Bakery · تم شحن الطلب', $phone->received[0]['title']);
        self::assertSame('افتحه للتفاصيل', $phone->received[0]['body']);
        self::assertSame('rtl', $phone->received[0]['dir']);
        self::assertSame('ar', $phone->received[0]['lang']);
    }

    public function testEveryDeviceOfThePersonGetsItAndNoOneElse(): void
    {
        $phone = $this->device(self::AMRO, 'phone');
        $laptop = $this->device(self::AMRO, 'laptop');
        $theirs = $this->device(self::AWSS, 'awss');
        $this->notify(self::AMRO);
        $this->drain();

        self::assertCount(1, $phone->received);
        self::assertCount(1, $laptop->received);
        self::assertSame([], $theirs->received);
    }

    public function testNoDeviceMeansNoPushRow(): void
    {
        $id = $this->notify(self::AMRO);
        $this->drain();

        self::assertArrayNotHasKey('push', $this->statuses($id));
        self::assertSame([], $this->service->headers);
    }

    public function testTheWorkspaceSwitchStopsPushButNotSecurity(): void
    {
        $phone = $this->device(self::AMRO, 'phone');
        (new TenantNotificationSettingsRepository($this->pdo))->upsertConfig(self::TENANT, 'push', ['enabled' => false]);

        $id = $this->notify(self::AMRO);
        $this->notify(self::AMRO, 'security.sign_in.approval', 'Approve this sign-in?', []);
        $this->drain();

        self::assertArrayNotHasKey('push', $this->statuses($id));
        self::assertCount(1, $phone->received);
        self::assertSame('Example Bakery · Approve this sign-in?', $phone->received[0]['title']);
    }

    public function testAPersonWhoTurnedAKindOffOnPushGetsNone(): void
    {
        $phone = $this->device(self::AMRO, 'phone');
        $prefs = new NotificationPreferenceRepository($this->pdo);
        $prefs->set(self::TENANT, self::AMRO, 'document.routing.awaiting', 'push', false);

        $off = $this->notify(self::AMRO);
        $this->notify(self::AMRO, 'document.comment.mention', 'Awss mentioned you');
        $this->drain();

        self::assertArrayNotHasKey('push', $this->statuses($off));
        self::assertCount(1, $phone->received);
        self::assertStringContainsString('mentioned you', $phone->received[0]['title']);

        $prefs->set(self::TENANT, self::AMRO, '*', 'push', false);
        $this->notify(self::AMRO, 'document.comment.mention', 'Again');
        $this->drain();
        self::assertCount(1, $phone->received, 'all push off');
    }

    public function testAGoneDeviceIsRemoved(): void
    {
        $phone = $this->device(self::AMRO, 'phone');
        $phone->status = 410;
        $id = $this->notify(self::AMRO);
        $this->drain();

        self::assertSame('sent:push:no-device', $this->statuses($id)['push']);
        self::assertSame([], (new PushSubscriptionRepository($this->pdo))->listForProfile(self::TENANT, self::AMRO));
    }

    public function testAnUnreachableServiceIsRetriedAndKeepsTheDevice(): void
    {
        $phone = $this->device(self::AMRO, 'phone');
        $phone->status = 503;
        $id = $this->notify(self::AMRO);
        $this->drain();

        self::assertSame('failed', $this->statuses($id)['push']);
        $retry = $this->pdo->query("SELECT COUNT(*) FROM jobs WHERE name = 'core.notifications.deliver'");
        self::assertNotFalse($retry);
        self::assertSame(1, (int) $retry->fetchColumn(), 'the queue will try again');
        $row = (new PushSubscriptionRepository($this->pdo))->listForProfile(self::TENANT, self::AMRO)[0];
        self::assertSame(1, $row['failures']);
    }

    public function testABurstIsHeldToOneDigest(): void
    {
        $phone = $this->device(self::AMRO, 'phone');
        for ($i = 1; $i <= PushTransport::PER_MINUTE + 3; $i++) {
            $this->notify(self::AMRO, 'document.routing.awaiting', 'Request ' . $i);
        }
        $this->drain();

        self::assertCount(PushTransport::PER_MINUTE, $phone->received);
        $digests = $this->pdo->query("SELECT payload FROM jobs WHERE name = '" . PushDigestJob::NAME . "'");
        self::assertNotFalse($digests);
        $rows = $digests->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(1, $rows, 'one digest for the window, however many were held');

        // A security notification is never held.
        $this->notify(self::AMRO, 'security.sign_in.approval', 'Approve this sign-in?', []);
        $this->drain();
        self::assertCount(PushTransport::PER_MINUTE + 1, $phone->received);

        $result = (new PushDigestJob(fn (): PushTransport => $this->transport))->handle(json_decode((string) $rows[0], true));
        self::assertSame('sent', $result['status']);
        $last = end($phone->received);
        self::assertIsArray($last);
        self::assertSame('Example Bakery · 3 more things are waiting for you', $last['title']);
        self::assertSame('/admin/inbox', $last['url']);

        // Nothing more held: a second run sends nothing.
        (new PushDigestJob(fn (): PushTransport => $this->transport))->handle(['tenant_id' => self::TENANT, 'profile_id' => self::AMRO]);
        self::assertCount(PushTransport::PER_MINUTE + 2, $phone->received);
    }

    public function testTheNextMinuteSendsAgain(): void
    {
        $phone = $this->device(self::AMRO, 'phone');
        for ($i = 0; $i < PushTransport::PER_MINUTE + 1; $i++) {
            $this->notify(self::AMRO);
        }
        $this->drain();
        $this->now += 60;
        $this->notify(self::AMRO);
        $this->drain();

        self::assertCount(PushTransport::PER_MINUTE + 1, $phone->received);
    }
}
