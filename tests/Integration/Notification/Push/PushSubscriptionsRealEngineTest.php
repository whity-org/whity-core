<?php

declare(strict_types=1);

namespace Tests\Integration\Notification\Push;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecipientProfiles;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PushSubscriptionsApiHandler;
use Whity\Auth\TokenValidator;
use Whity\Core\Notification\Push\Base64Url;
use Whity\Core\Notification\Push\PushEndpoint;
use Whity\Core\Notification\Push\PushSubscriptionRepository;
use Whity\Core\Notification\Push\VapidKeys;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;

/**
 * Web push devices and the instance key on a real database (WHIT-1073): the key
 * pair is made once and kept encrypted, and a person only ever sees, adds or
 * removes their own devices in their own workspace.
 */
final class PushSubscriptionsRealEngineTest extends TestCase
{
    private const KEY = 'push_test_key_0123456789abcdef0123456789';
    private const CALLER = 101;
    private const OTHER = 202;

    private PDO $pdo;
    private EncryptedSecretStore $secrets;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        RecipientProfiles::seed($this->pdo);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other', 'other')");
        $this->secrets = new EncryptedSecretStore(['v1' => self::KEY], 'v1');
    }

    private function keys(): VapidKeys
    {
        return new VapidKeys(new GlobalSettingsRepository($this->pdo), $this->secrets);
    }

    public function testThePublicKeyIsReadWithoutDecryptingThePrivateHalf(): void
    {
        // WHIT-1229: decrypting runs the key ring's password derivation (~40 ms)
        // and the device list asks for the public key on every dashboard load.
        // A store that cannot decrypt proves the public key never needs it.
        $public = $this->keys()->pair()['public'];
        $cannotDecrypt = new EncryptedSecretStore(['v1' => str_repeat('x', 64)], 'v1');
        $keys = new VapidKeys(new GlobalSettingsRepository($this->pdo), $cannotDecrypt);

        self::assertSame($public, $keys->publicKey());
    }

    private function handler(int $profileId, int $tenantId = 1): PushSubscriptionsApiHandler
    {
        $tv = $this->createStub(TokenValidator::class);
        $tv->method('validateAccessToken')->willReturn(['profile_id' => $profileId, 'active_tenant_id' => $tenantId]);

        return new PushSubscriptionsApiHandler($tv, new PushSubscriptionRepository($this->pdo), fn (): VapidKeys => $this->keys());
    }

    /** @return array{endpoint: string, keys: array{p256dh: string, auth: string}} */
    private static function browser(string $id = 'abc'): array
    {
        return [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/' . $id,
            'keys' => ['p256dh' => VapidKeys::generate()['public'], 'auth' => Base64Url::encode(random_bytes(16))],
        ];
    }

    /** @param array<string, mixed> $body */
    private static function post(array $body): Request
    {
        return new Request('POST', '/api/me/push-subscriptions', ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/131'], (string) json_encode($body));
    }

    /** @return array<string, mixed> */
    private static function body(Response $response): array
    {
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);

        return $body;
    }

    public function testTheKeyPairIsMadeOnceAndStoredEncrypted(): void
    {
        $first = $this->keys()->pair();
        $again = $this->keys()->pair();

        self::assertSame($first, $again);
        $stored = (string) (new GlobalSettingsRepository($this->pdo))->get(VapidKeys::SETTING_KEY);
        self::assertStringNotContainsString('PRIVATE KEY', $stored, 'the private key is never stored in the clear');
        self::assertStringContainsString($first['public'], $stored);
    }

    public function testASecondWriterKeepsTheFirstPair(): void
    {
        $globals = new GlobalSettingsRepository($this->pdo);
        $winner = $this->keys()->publicKey();
        self::assertFalse($globals->setIfAbsent(VapidKeys::SETTING_KEY, '{"public":"x","private":"y"}'));
        self::assertSame($winner, $this->keys()->publicKey());
    }

    public function testSubscribingStoresTheDeviceAndListsItWithoutItsEndpoint(): void
    {
        $browser = self::browser();
        $response = $this->handler(self::CALLER)->subscribe(self::post($browser));

        self::assertSame(201, $response->getStatusCode());
        $body = self::body($response);
        self::assertSame($this->keys()->publicKey(), $body['public_key']);
        self::assertCount(1, $body['data']);
        $device = $body['data'][0];
        self::assertSame(PushEndpoint::hash($browser['endpoint']), $device['endpoint_hash']);
        self::assertStringContainsString('Android', $device['user_agent']);
        self::assertStringNotContainsString('fcm.googleapis.com', $response->getBody());
    }

    public function testSubscribingTheSameBrowserAgainKeepsOneRow(): void
    {
        $browser = self::browser();
        $this->handler(self::CALLER)->subscribe(self::post($browser));
        $browser['keys']['auth'] = Base64Url::encode(random_bytes(16));
        $body = self::body($this->handler(self::CALLER)->subscribe(self::post($browser)));

        self::assertCount(1, $body['data']);
        $row = (new PushSubscriptionRepository($this->pdo))->listForProfile(1, self::CALLER)[0];
        self::assertSame($browser['keys']['auth'], $row['auth'], 'the newest keys win');
    }

    public function testASharedBrowserMovesToWhoeverTurnedItOnLast(): void
    {
        $browser = self::browser();
        $this->handler(self::CALLER)->subscribe(self::post($browser));
        $this->handler(self::OTHER)->subscribe(self::post($browser));

        $repo = new PushSubscriptionRepository($this->pdo);
        self::assertSame([], $repo->listForProfile(1, self::CALLER));
        self::assertCount(1, $repo->listForProfile(1, self::OTHER));
    }

    public function testTheSameBrowserCanBeOnInTwoWorkspaces(): void
    {
        $browser = self::browser();
        $this->handler(self::CALLER, 1)->subscribe(self::post($browser));
        $this->handler(self::CALLER, 2)->subscribe(self::post($browser));

        $repo = new PushSubscriptionRepository($this->pdo);
        self::assertCount(1, $repo->listForProfile(1, self::CALLER));
        self::assertCount(1, $repo->listForProfile(2, self::CALLER));
    }

    public function testAPersonListsAndRemovesOnlyTheirOwnDevices(): void
    {
        $mine = self::body($this->handler(self::CALLER)->subscribe(self::post(self::browser('mine'))))['id'];
        $theirs = self::body($this->handler(self::OTHER)->subscribe(self::post(self::browser('theirs'))))['id'];

        self::assertCount(1, self::body($this->handler(self::CALLER)->list(new Request('GET', '/api/me/push-subscriptions')))['data']);
        self::assertSame(404, $this->handler(self::CALLER)->remove(new Request('DELETE', '/x'), ['id' => (string) $theirs])->getStatusCode());
        self::assertSame(404, $this->handler(self::CALLER, 2)->remove(new Request('DELETE', '/x'), ['id' => (string) $mine])->getStatusCode(), 'not from another workspace');

        $after = $this->handler(self::CALLER)->remove(new Request('DELETE', '/x'), ['id' => (string) $mine]);
        self::assertSame(200, $after->getStatusCode());
        self::assertSame([], self::body($after)['data']);
        self::assertCount(1, (new PushSubscriptionRepository($this->pdo))->listForProfile(1, self::OTHER));
    }

    public function testABadSubscriptionIsRefusedWithNothingStored(): void
    {
        $browser = self::browser();
        $browser['endpoint'] = 'https://internal.example/hook';
        self::assertSame(422, $this->handler(self::CALLER)->subscribe(self::post($browser))->getStatusCode());
        self::assertSame(422, $this->handler(self::CALLER)->subscribe(new Request('POST', '/x', [], 'nope'))->getStatusCode());
        self::assertSame(422, $this->handler(self::CALLER, 0)->subscribe(self::post(self::browser()))->getStatusCode(), 'no workspace');
        self::assertSame([], (new PushSubscriptionRepository($this->pdo))->listForProfile(1, self::CALLER));
    }

    public function testAPersonKeepsTheirNewestDevicesOnly(): void
    {
        $repo = new PushSubscriptionRepository($this->pdo);
        for ($i = 0; $i < PushSubscriptionRepository::MAX_PER_PERSON + 3; $i++) {
            $b = self::browser('d' . $i);
            $repo->upsert(1, self::CALLER, $b['endpoint'], $b['keys']['p256dh'], $b['keys']['auth'], '');
        }
        self::assertCount(PushSubscriptionRepository::MAX_PER_PERSON, $repo->listForProfile(1, self::CALLER));
    }

    public function testSignedOutIsRefused(): void
    {
        $tv = $this->createStub(TokenValidator::class);
        $tv->method('validateAccessToken')->willReturn(null);
        $handler = new PushSubscriptionsApiHandler($tv, new PushSubscriptionRepository($this->pdo), fn (): VapidKeys => $this->keys());

        self::assertSame(401, $handler->list(new Request('GET', '/x'))->getStatusCode());
        self::assertSame(401, $handler->subscribe(self::post(self::browser()))->getStatusCode());
    }
}
