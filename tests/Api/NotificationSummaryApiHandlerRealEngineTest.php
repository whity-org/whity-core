<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecipientProfiles;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\NotificationSummaryApiHandler;
use Whity\Auth\TokenValidator;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Notification\Push\PushSubscriptionRepository;
use Whity\Core\Request;

/**
 * Real-engine tests for {@see NotificationSummaryApiHandler} (WHIT-1081): the
 * caller's notifications in each workspace they belong to, read-only.
 *
 * Proves the caller sees one row per ACTIVE membership and nothing of anyone
 * else's, that push devices and email/push state come from that workspace's
 * own rows, and that it fails closed without a session.
 */
final class NotificationSummaryApiHandlerRealEngineTest extends TestCase
{
    private const CALLER = 101;
    private const SOMEONE_ELSE = 202;

    private PDO $pdo;
    private NotificationPreferenceRepository $preferences;
    private PushSubscriptionRepository $push;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        RecipientProfiles::seed($this->pdo);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Clinic', 'clinic'), (3, 'Old Shop', 'old-shop')");
        $this->pdo->exec("INSERT INTO roles (id, name, tenant_id) VALUES (50, 'member', NULL)");
        $membership = $this->pdo->prepare('INSERT INTO memberships (profile_id, tenant_id, role_id, status) VALUES (?, ?, 50, ?)');
        $membership->execute([self::CALLER, 1, 'active']);
        $membership->execute([self::CALLER, 2, 'active']);
        $membership->execute([self::CALLER, 3, 'suspended']);
        $membership->execute([self::SOMEONE_ELSE, 1, 'active']);

        $this->preferences = new NotificationPreferenceRepository($this->pdo);
        $this->push = new PushSubscriptionRepository($this->pdo);
    }

    /** @param array<string, mixed>|null $claims */
    private function handler(?array $claims): NotificationSummaryApiHandler
    {
        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateAccessToken')->willReturn($claims);

        return new NotificationSummaryApiHandler($tokens, $this->pdo, $this->preferences, $this->push);
    }

    /** @return list<array<string, mixed>> */
    private function summary(int $activeTenant = 1): array
    {
        $response = $this->handler(['profile_id' => self::CALLER, 'active_tenant_id' => $activeTenant])
            ->summary(new Request('GET', '/api/me/notification-summary'));
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);

        return $body['data'];
    }

    public function testOneRowPerActiveWorkspaceWithItsOwnState(): void
    {
        // Bakery: all email off. Clinic: chat on push off, and one phone with push.
        $this->preferences->set(1, self::CALLER, '*', 'email', false);
        $this->preferences->set(2, self::CALLER, 'chat.message', 'push', false);
        $this->push->upsert(2, self::CALLER, 'https://fcm.googleapis.com/fcm/send/abc', 'p256', 'auth', 'Chrome on Android');
        // Someone else's choices and devices in the caller's workspace must not count.
        $this->preferences->set(1, self::SOMEONE_ELSE, '*', 'push', false);
        $this->push->upsert(1, self::SOMEONE_ELSE, 'https://fcm.googleapis.com/fcm/send/xyz', 'p256', 'auth', 'Firefox');

        $rows = $this->summary();

        self::assertSame([1, 2], array_column($rows, 'tenant_id'), 'active memberships only, not the suspended one');
        self::assertSame(
            ['tenant_id' => 1, 'current' => true, 'push_devices' => 0, 'email' => 'off', 'push' => 'on'],
            $rows[0],
        );
        self::assertSame(
            ['tenant_id' => 2, 'current' => false, 'push_devices' => 1, 'email' => 'on', 'push' => 'some'],
            $rows[1],
        );
    }

    public function testCurrentFollowsTheActiveWorkspace(): void
    {
        $rows = $this->summary(2);
        self::assertSame([false, true], array_column($rows, 'current'));
    }

    public function testChannelStateReadsExactTypesOverTheWildcard(): void
    {
        self::assertSame('on', NotificationSummaryApiHandler::channelState([], 'email'));
        self::assertSame('off', NotificationSummaryApiHandler::channelState([['type' => '*', 'channel' => 'email', 'enabled' => false]], 'email'));
        self::assertSame('some', NotificationSummaryApiHandler::channelState([
            ['type' => '*', 'channel' => 'email', 'enabled' => false],
            ['type' => 'doc.waiting', 'channel' => 'email', 'enabled' => true],
        ], 'email'));
        self::assertSame('some', NotificationSummaryApiHandler::channelState([['type' => 'doc.sent', 'channel' => 'push', 'enabled' => false]], 'push'));
        // Another channel's rows do not count.
        self::assertSame('on', NotificationSummaryApiHandler::channelState([['type' => '*', 'channel' => 'push', 'enabled' => false]], 'email'));
    }

    public function testRequiresASession(): void
    {
        self::assertSame(401, $this->handler(null)->summary(new Request('GET', '/api/me/notification-summary'))->getStatusCode());
    }
}
