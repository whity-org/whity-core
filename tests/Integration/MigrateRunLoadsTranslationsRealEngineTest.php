<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Cli\Commands\MigrationsCommand;
use Whity\Core\i18n\TranslationCatalog;
use Whity\Core\i18n\TranslationRefresh;
use Whity\Database\Database;

/**
 * An upgrade loads new labels without a separate step.
 *
 * `migrate run` is the step every upgrade path already takes. Before this, an
 * upgraded install showed English for every label added since it was first seeded.
 */
final class MigrateRunLoadsTranslationsRealEngineTest extends TestCase
{
    private const LANGUAGE = 'ar';

    private PDO $pdo;
    private string $baseDir;
    private string $domain;
    private string $key;

    protected function setUp(): void
    {
        $this->pdo     = SchemaFromMigrations::make();
        $this->baseDir = dirname(__DIR__, 2);

        $catalog      = (new TranslationCatalog($this->baseDir))->readLocale(self::LANGUAGE);
        $this->domain = (string) array_key_first($catalog);
        $this->key    = (string) array_key_first($catalog[$this->domain]);
        self::assertNotSame('', $this->key, 'No Arabic catalogue to test with.');
    }

    private function languageId(): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM languages WHERE code = ?');
        $statement->execute([self::LANGUAGE]);

        return (int) $statement->fetchColumn();
    }

    /** The system row an upgraded install would be missing. */
    private function forgetTheLabel(): void
    {
        $this->pdo->prepare('DELETE FROM translations WHERE language_id = ? AND domain = ? AND key = ? AND tenant_id IS NULL')
            ->execute([$this->languageId(), $this->domain, $this->key]);
        self::assertNull($this->storedLabel());
    }

    private function storedLabel(?int $tenantId = null): ?string
    {
        $tenant    = $tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = ?';
        $statement = $this->pdo->prepare("SELECT translation FROM translations WHERE language_id = ? AND domain = ? AND key = ? AND {$tenant}");
        $statement->execute([$this->languageId(), $this->domain, $this->key, ...($tenantId === null ? [] : [$tenantId])]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function testAMissingLabelIsLoaded(): void
    {
        $this->forgetTheLabel();

        $totals = (new TranslationRefresh($this->pdo, $this->baseDir))->run();

        self::assertIsArray($totals);
        self::assertGreaterThanOrEqual(2, $totals['languages']);
        self::assertSame(1, $totals['inserted']);
        self::assertNotNull($this->storedLabel());
    }

    public function testRunningTwiceChangesNothing(): void
    {
        $refresh = new TranslationRefresh($this->pdo, $this->baseDir);
        $refresh->run();
        $again = $refresh->run();

        self::assertIsArray($again);
        self::assertSame(0, $again['inserted']);
        self::assertSame(0, $again['updated']);
    }

    public function testAPersonsEditIsKept(): void
    {
        $this->pdo->prepare(
            'UPDATE translations SET translation = ?, source_managed = FALSE
              WHERE language_id = ? AND domain = ? AND key = ? AND tenant_id IS NULL'
        )->execute(['edited by a person', $this->languageId(), $this->domain, $this->key]);

        (new TranslationRefresh($this->pdo, $this->baseDir))->run();

        self::assertSame('edited by a person', $this->storedLabel());
    }

    public function testAWorkspaceOverrideIsKept(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (7, 'Override') ON CONFLICT DO NOTHING");
        $this->pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, 7, NOW(), NOW())'
        )->execute([$this->languageId(), $this->domain, $this->key, 'our wording']);

        (new TranslationRefresh($this->pdo, $this->baseDir))->run();

        self::assertSame('our wording', $this->storedLabel(7));
    }

    public function testADatabaseWithNoTranslationTableIsLeftAlone(): void
    {
        $bare = new PDO('sqlite::memory:');
        $bare->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        self::assertNull((new TranslationRefresh($bare, $this->baseDir))->run());
    }

    /** @return array{0: int, 1: string} */
    private function migrateRun(): array
    {
        $pdo     = $this->pdo;
        $db      = Database::withFactory(static fn (): PDO => $pdo, 86400, 86400);
        $command = new MigrationsCommand($db, null, sys_get_temp_dir() . '/whity-no-core-migrations-' . uniqid());
        if (!is_dir($dir = (string) (new \ReflectionProperty($command, 'injectedMigrationDir'))->getValue($command))) {
            mkdir($dir);
        }

        ob_start();
        try {
            $code = $command->execute(['run']);
        } finally {
            $output = (string) ob_get_clean();
            rmdir($dir);
        }

        return [$code, $output];
    }

    /** The command itself, so the wiring is proven and not only the class. */
    public function testMigrateRunLoadsALabelWithNoMigrationPending(): void
    {
        $this->forgetTheLabel();

        [$code, $output] = $this->migrateRun();

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Translations:', $output);
        self::assertNotNull($this->storedLabel());
    }

    /** Wording must never fail an upgrade whose migrations succeeded. */
    public function testALabelFailureWarnsAndDoesNotFailTheMigration(): void
    {
        $this->pdo->exec('ALTER TABLE translations RENAME COLUMN translation TO renamed_away');

        [$code, $output] = $this->migrateRun();

        self::assertSame(0, $code, $output);
        self::assertStringContainsString("Translations weren't loaded", $output);
        self::assertStringContainsString('i18n:sync --all', $output);
    }
}
