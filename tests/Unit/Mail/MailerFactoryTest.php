<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Whity\Core\Mail\LogMailer;
use Whity\Core\Mail\MailerFactory;
use Whity\Core\Mail\NullMailer;

/**
 * Unit tests for the WC-235 mailer seam: config-driven transport selection and
 * the two shipped transports (no-op default, PSR-3 log).
 */
final class MailerFactoryTest extends TestCase
{
    private function spyLogger(): SpyLogger
    {
        return new SpyLogger();
    }

    public function testNullMailerSendIsANoOpAndNeverThrows(): void
    {
        (new NullMailer())->send('to@acme.test', 'Subject', 'Body');
        $this->addToAssertionCount(1);
    }

    public function testLogMailerWritesTheMessageToTheLogger(): void
    {
        $logger = $this->spyLogger();
        (new LogMailer($logger))->send('to@acme.test', 'Verify your email address', 'Open https://app.test/verify-email?token=abc');

        self::assertCount(1, $logger->records);
        self::assertSame('to@acme.test', $logger->records[0]['context']['to']);
        self::assertSame('Verify your email address', $logger->records[0]['context']['subject']);
        self::assertStringContainsString('token=abc', $logger->records[0]['context']['body']);
    }
}

/**
 * In-memory PSR-3 logger double capturing every record for assertions.
 */
final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
