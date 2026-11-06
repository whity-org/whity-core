<?php

namespace Whity\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Tests\Support\RequiresConfiguredDatabase;
use Whity\Database\Database;
use Whity\Cli\Commands\MigrationsCommand;

/**
 * Tests for MigrationsCommand CLI handler
 *
 * Tests the CLI-based migrations execution (no HTTP/API).
 * Focuses on command structure and error handling rather than database state.
 */
class MigrationsCommandTest extends TestCase
{
    // The probe reads DB_PORT, and fails rather than skips when a database is
    // configured and unreachable — see the trait for why those are two different
    // situations and only one of them deserves a skip (#1013).
    use RequiresConfiguredDatabase;

    /**
     * Test that MigrationsCommand can be instantiated
     */
    public function testCommandCanBeInstantiated(): void
    {
        $command = new MigrationsCommand();
        $this->assertInstanceOf(MigrationsCommand::class, $command);
    }

    /**
     * Test help command displays usage information
     */
    public function testHelpCommandOutput(): void
    {
        // The help action needs no schema: an in-memory connection is enough to
        // get past the connect step and print the usage text.
        $db = Database::withFactory(static fn (): \PDO => new \PDO('sqlite::memory:'), 86400, 86400);
        $command = new MigrationsCommand($db);

        ob_start();
        $exitCode = $command->execute(['help']);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('rollback --plugin <PluginName>', $output);
    }

    /**
     * Test migration status with database available
     *
     * @requires extension pdo_pgsql
     */
    public function testMigrationStatusWithDatabase(): void
    {
        // Skips only when nothing says where a database is; fails loudly when
        // something does and it is not there.
        $this->connectToConfiguredDatabase();

        $command = new MigrationsCommand();

        ob_start();
        $exitCode = $command->execute(['status']);
        $output = ob_get_clean();

        // Should succeed
        $this->assertSame(0, $exitCode);
        // Output should contain migration information
        $this->assertStringContainsString('Migration', $output);
    }

    /**
     * Test migration run with database available
     *
     * @requires extension pdo_pgsql
     */
    public function testMigrationRunWithDatabase(): void
    {
        // Skips only when nothing says where a database is; fails loudly when
        // something does and it is not there.
        $this->connectToConfiguredDatabase();

        $command = new MigrationsCommand();

        ob_start();
        $exitCode = $command->execute(['run']);
        $output = ob_get_clean();

        // Output should mention migrations
        $this->assertStringContainsString('migration', strtolower($output));
    }

    /**
     * Test that command fails gracefully without database
     */
    public function testCommandFailsGracefullyWithoutDatabase(): void
    {
        // The inverse question, and the only half of the probe answerable without
        // opening a socket: this test is about the no-database path, so a
        // configured database — reachable or not — means there is nothing here
        // to assert.
        if (self::aDatabaseIsConfigured()) {
            $this->markTestSkipped('A database is configured, so the no-database path is not what would run.');
        }

        $command = new MigrationsCommand();

        ob_start();
        $exitCode = $command->execute(['status']);
        $output = ob_get_clean();

        // Should fail with non-zero exit code
        $this->assertNotSame(0, $exitCode);
        // Should show error message
        $this->assertStringContainsString('Error', $output);
    }
}
