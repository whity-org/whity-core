<?php

declare(strict_types=1);

namespace Tests\Unit\Core\RateLimit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Whity\Core\RateLimit\RateLimited;

/**
 * Every over-the-limit answer is {@see RateLimited} (merge map plumbing,
 * WHIT-1158). This scans the handlers, auth and HTTP code for a hand-written
 * 429 and fails on any that is not on the list below.
 *
 * The list is short on purpose. The document render limit is a plan meter, not
 * a rate limit, so it keeps its own answer. Anything else that wants a 429
 * should count against a rule in {@see \Whity\Core\RateLimit\RateLimits} and
 * answer with RateLimited.
 */
final class RateLimitedGuardTest extends TestCase
{
    private const SCANNED = ['src/Api', 'src/Auth', 'src/Http'];

    /**
     * File (relative, forward slashes) => text the allowed line contains.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'src/Api/DocumentPublishingApiHandler.php' => 'The document render limit has been reached',
        'src/Api/DocumentsApiHandler.php' => 'The document render limit has been reached',
        'src/Api/FormPrintoutsApiHandler.php' => 'The document render limit has been reached',
        'src/Core/RateLimit/RateLimited.php' => 'Response::error(self::sentence($wait), 429',
        // The identity check can't start for two reasons the gate doesn't tell
        // apart (too many codes, or no email address), and the person needs both
        // named; "try again in 15 minutes" would be wrong for the second.
        'src/Api/GatedApprovalHttp.php' => 'Too many codes were sent, or your account has no email address',
    ];

    public function testNoHandWritten429(): void
    {
        $root = dirname(__DIR__, 4);
        $found = [];

        foreach (self::SCANNED as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $lines = file($file->getPathname()) ?: [];
                foreach ($lines as $number => $line) {
                    if (!str_contains($line, ', 429)')) {
                        continue;
                    }
                    if (isset(self::ALLOWED[$relative]) && str_contains($line, self::ALLOWED[$relative])) {
                        continue;
                    }
                    $found[] = $relative . ':' . ($number + 1) . ': ' . trim($line);
                }
            }
        }

        self::assertSame([], $found, "Answer over a limit with RateLimited::response() or a RateLimits rule, not a hand-written 429:\n" . implode("\n", $found));
    }

    public function testTheGuardStillSeesTheAllowedLines(): void
    {
        $root = dirname(__DIR__, 4);

        foreach (self::ALLOWED as $file => $text) {
            $source = (string) file_get_contents($root . '/' . $file);
            self::assertStringContainsString($text, $source, "{$file} no longer has its allowed 429; take it off the list");
        }
        self::assertTrue(class_exists(RateLimited::class));
    }
}
