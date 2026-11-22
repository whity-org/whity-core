<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Log;

use PHPUnit\Framework\TestCase;
use Whity\Core\Log\ErrorLogLogger;
use Whity\Core\Log\ScrubbedErrorLog;

/**
 * What goes to stderr is scrubbed (READINESS CO-1): stderr is shipped to log
 * aggregation, so a secret or a person's address in an exception or a log
 * context must not reach it raw.
 */
final class ErrorLogScrubbingTest extends TestCase
{
    private string $file;
    private string|false $previous;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'errlog');
        $this->previous = ini_set('error_log', $this->file);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previous === false ? '' : $this->previous);
        @unlink($this->file);
    }

    public function testTheLoggerDropsSecretKeysAndRedactsValues(): void
    {
        (new ErrorLogLogger())->warning('Sign-in for {email} failed', [
            'email'        => 'amro@example.com',
            'access_token' => 'abc.def.ghi',
            'reason'       => 'Key (email)=(ahmad@example.com) already exists',
        ]);

        $written = (string) file_get_contents($this->file);
        self::assertStringNotContainsString('amro@example.com', $written);
        self::assertStringNotContainsString('ahmad@example.com', $written);
        self::assertStringNotContainsString('abc.def.ghi', $written);
        self::assertStringContainsString('[redacted', $written);
        self::assertStringContainsString('Sign-in for', $written, 'the line itself still says what happened');
    }

    public function testTheWriterRedactsAnExceptionsValues(): void
    {
        ScrubbedErrorLog::failure('[x] insert failed', new \RuntimeException('duplicate: Key (email)=(emad@example.com); password=hunter22'));

        $written = (string) file_get_contents($this->file);
        self::assertStringContainsString('[x] insert failed: duplicate', $written);
        self::assertStringNotContainsString('emad@example.com', $written);
        self::assertStringNotContainsString('hunter22', $written);
    }
}
