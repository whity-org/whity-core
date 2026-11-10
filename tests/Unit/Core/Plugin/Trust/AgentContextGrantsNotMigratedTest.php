<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Plugin\Trust;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Whity\Core\Plugin\Trust\AgentContextGrants;
use Whity\Core\Plugin\Trust\CodePluginVerification;
use Whity\Database\UndefinedTable;

/**
 * The worker reads the grants while it boots, and on an upgrade it boots before
 * `migrate run`. v0.2.27 crash-looped there, on every existing install, because
 * nothing could run the migrations in a container that would not start.
 */
final class AgentContextGrantsNotMigratedTest extends TestCase
{
    private const PG_MISSING_GRANTS = 'SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "plugin_agent_context_grants" does not exist';

    private function grantsOn(PDO $db): AgentContextGrants
    {
        return new AgentContextGrants($db, new CodePluginVerification($db));
    }

    private function sqliteWithoutTheTable(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function failure(string $message, string $sqlState): PDOException
    {
        $e = new PDOException($message);
        $code = new \ReflectionProperty(\Exception::class, 'code');
        $code->setValue($e, $sqlState);

        return $e;
    }

    public function testAMissingTableMeansNothingIsGranted(): void
    {
        $grants = $this->grantsOn($this->sqliteWithoutTheTable());

        self::assertSame([], $grants->granted());
        self::assertSame([], $grants->list());
        self::assertFalse($grants->isGranted('PriceBook'));
    }

    public function testTheMissingTableOnPostgresMeansNothingIsGranted(): void
    {
        $db = $this->createStub(PDO::class);
        $db->method('query')->willThrowException($this->failure(self::PG_MISSING_GRANTS, '42P01'));
        $db->method('prepare')->willThrowException($this->failure(self::PG_MISSING_GRANTS, '42P01'));
        $grants = $this->grantsOn($db);

        self::assertSame([], $grants->granted());
        self::assertSame([], $grants->list());
        self::assertFalse($grants->isGranted('PriceBook'));
    }

    public function testAnyOtherDatabaseErrorStillRaises(): void
    {
        $denied = $this->failure('SQLSTATE[42501]: Insufficient privilege: permission denied for table plugin_agent_context_grants', '42501');
        $db = $this->createStub(PDO::class);
        $db->method('query')->willThrowException($denied);

        $this->expectExceptionObject($denied);
        $this->grantsOn($db)->granted();
    }

    public function testADifferentMissingTableStillRaises(): void
    {
        $other = $this->failure('SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "plugins" does not exist', '42P01');
        $db = $this->createStub(PDO::class);
        $db->method('prepare')->willThrowException($other);

        $this->expectExceptionObject($other);
        $this->grantsOn($db)->isGranted('PriceBook');
    }

    public function testATableWhoseNameOnlyStartsTheSameIsNotAMatch(): void
    {
        self::assertFalse(UndefinedTable::is(
            $this->failure('SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "plugin_agent_context_grants_old" does not exist', '42P01'),
            'plugin_agent_context_grants',
        ));
        self::assertFalse(UndefinedTable::is(
            $this->failure('SQLSTATE[HY000]: General error: 1 no such table: plugin_agent_context_grants_old', 'HY000'),
            'plugin_agent_context_grants',
        ));
    }
}
