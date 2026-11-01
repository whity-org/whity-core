<?php

declare(strict_types=1);

namespace Tests\Ops;

use PHPUnit\Framework\TestCase;

/**
 * Compose services must call commands their entry point actually has (WHIT-839).
 *
 * public/index.php and bin/whity-cli know different commands. A service calling
 * the wrong one prints "Unknown command" and restarts forever, which nothing else
 * notices: the staging relay did this, so async events were never delivered.
 */
final class ComposeWorkerCommandsTest extends TestCase
{
    private const COMPOSE_FILES = ['docker-compose.yml', 'docker-compose.staging.yml', 'docker/production/docker-compose.yml'];

    private static function read(string $relative): string
    {
        $text = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        self::assertIsString($text, $relative);

        return $text;
    }

    /** @return list<string> */
    private static function indexCommands(): array
    {
        preg_match_all('/\$command === \'([^\']+)\'/', self::read('public/index.php'), $matches);

        return $matches[1];
    }

    /** @return list<string> */
    private static function cliCommands(): array
    {
        preg_match_all('/^\s*\'([a-z0-9-]+:[a-z0-9:-]+|[a-z0-9-]+)\' => \'Whity\\\\/m', self::read('src/Cli/CliRunner.php'), $matches);

        return $matches[1];
    }

    public function testEveryComposeCommandExistsOnItsEntryPoint(): void
    {
        $index = self::indexCommands();
        $cli = self::cliCommands();
        self::assertContains('queue:work', $index, 'The public/index.php command scan matched nothing useful.');
        self::assertContains('events:relay', $cli, 'The CliRunner command scan matched nothing useful.');

        foreach (self::COMPOSE_FILES as $file) {
            $found = preg_match_all(
                '#php (public/index\.php|bin/whity-cli) ([a-z0-9:-]+)#',
                self::read($file),
                $calls,
                PREG_SET_ORDER
            );
            self::assertGreaterThan(0, $found, "No commands found in {$file}; the scan no longer matches it.");

            foreach ($calls as [, $entry, $command]) {
                $known = $entry === 'public/index.php' ? $index : $cli;
                self::assertContains($command, $known, "{$file} calls `{$entry} {$command}`, which that entry point does not have.");
            }
        }
    }

    public function testEveryQueueWorkerRunsTheRelayToo(): void
    {
        foreach (self::COMPOSE_FILES as $file) {
            $text = self::read($file);
            self::assertStringContainsString('queue:work', $text, $file);
            self::assertStringContainsString('events:relay', $text, "{$file} runs queue:work with no relay, so async events are never delivered.");
        }
    }
}
