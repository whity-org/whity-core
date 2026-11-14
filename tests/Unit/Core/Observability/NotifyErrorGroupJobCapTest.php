<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Observability;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Notification\CoreTransports;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Observability\ErrorGroupRepository;
use Whity\Core\Observability\Jobs\NotifyErrorGroupJob;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
use Whity\Core\Store\DatabaseSharedStore;

/**
 * Error alerts are emailed at most EMAILS_PER_HOUR times an hour; the rest
 * still reach the operators' in-app inbox.
 */
final class NotifyErrorGroupJobCapTest extends TestCase
{
    public function testABurstOfNewErrorsEmailsOnlyUpToTheHourlyCap(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $operators = (int) $this->scalar($pdo, "SELECT COUNT(*) FROM memberships WHERE tenant_id = 0 AND status = 'active'");
        self::assertGreaterThan(0, $operators, 'the schema seeds an operator');

        $groups = new ErrorGroupRepository($pdo);
        $job = new NotifyErrorGroupJob(
            $pdo,
            $groups,
            new NotificationDispatcher(new NotificationRepository($pdo), CoreTransports::make(), new QueueService(new JobRepository($pdo))),
            null,
            null,
            new DatabaseSharedStore($pdo),
        );

        $burst = NotifyErrorGroupJob::EMAILS_PER_HOUR + 3;
        for ($i = 0; $i < $burst; $i++) {
            $group = $groups->record('fp-' . $i, 'RuntimeException', 'Broken ' . $i, null, null, null, [], 'production');
            $job->handle(['group_id' => $group['id'], 'reason' => 'new']);
        }

        self::assertSame(NotifyErrorGroupJob::EMAILS_PER_HOUR * $operators, $this->deliveries($pdo, 'email'));
        self::assertSame($burst * $operators, $this->deliveries($pdo, 'in_app'), 'every alert still reaches the inbox');

        $last = $this->scalar($pdo, "SELECT n.body FROM notifications n JOIN notification_deliveries d ON d.notification_id = n.id
                                      WHERE d.channel = 'email' ORDER BY n.id DESC LIMIT 1");
        self::assertStringContainsString('This is the last error alert emailed this hour.', (string) $last);
    }

    private function scalar(PDO $pdo, string $sql): mixed
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        return $stmt->fetchColumn();
    }

    private function deliveries(PDO $pdo, string $channel): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notification_deliveries WHERE channel = :c');
        $stmt->execute([':c' => $channel]);

        return (int) $stmt->fetchColumn();
    }
}
