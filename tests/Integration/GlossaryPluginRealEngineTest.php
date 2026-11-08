<?php

declare(strict_types=1);

namespace Tests\Integration;

use Glossary\Api\GlossaryTermsApiHandler;
use Glossary\GlossaryPlugin;
use Glossary\Migrations\CreateGlossaryTermsTable;
use Glossary\Migrations\GrantGlossaryPermissionsToAdmin;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\I18n\LocalizedText;

$glossaryDir = dirname(__DIR__, 2) . '/plugins/Glossary';
require_once $glossaryDir . '/GlossaryPlugin.php';
require_once $glossaryDir . '/Api/GlossaryTermsApiHandler.php';
require_once $glossaryDir . '/Migrations/CreateGlossaryTermsTable.php';
require_once $glossaryDir . '/Migrations/GrantGlossaryPermissionsToAdmin.php';

/**
 * The reference plugin for translatable fields (#521), on a real engine:
 * SQLite locally and PostgreSQL when PHPUNIT_PG_DSN is set.
 */
final class GlossaryPluginRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private GlossaryTermsApiHandler $handler;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        (new CreateGlossaryTermsTable())->up($this->pdo);
        $this->handler = new GlossaryTermsApiHandler($this->pdo);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testATermKeepsBothLanguagesThroughCreateAndList(): void
    {
        TenantContext::setTenantId(self::TENANT_A);

        $created = $this->handler->create($this->body([
            'code' => ' INV ',
            'term' => ['ar' => 'فاتورة', 'en' => 'Invoice'],
            'definition' => ['ar' => '', 'en' => 'A bill.'],
        ]));

        self::assertSame(201, $created->getStatusCode());
        self::assertSame('INV', $this->data($created)['code']);

        $rows = $this->rows($this->handler->list());
        self::assertCount(1, $rows);
        self::assertSame(['ar' => 'فاتورة', 'en' => 'Invoice'], $rows[0]['term']);
        self::assertSame(['en' => 'A bill.'], $rows[0]['definition'], 'A blank language is not stored');
    }

    public function testADefinitionLeftOutIsAnEmptyObjectOnTheWire(): void
    {
        TenantContext::setTenantId(self::TENANT_A);

        $created = $this->handler->create($this->body(['code' => 'A', 'term' => ['en' => 'Alpha']]));

        self::assertStringContainsString('"definition":{}', (string) $created->getBody());
    }

    public function testArabicIsStoredReadably(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $this->handler->create($this->body(['code' => 'A', 'term' => ['ar' => 'فاتورة']]));

        $stored = $this->query('SELECT term FROM glossary_terms')->fetchColumn();

        self::assertSame('{"ar":"فاتورة"}', $stored);
    }

    public function testUpdateReplacesTheLanguages(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $id = $this->createTerm('A', ['ar' => 'قديم', 'en' => 'Old']);

        $updated = $this->handler->update($this->body(['code' => 'A', 'term' => ['en' => 'New']]), $id);

        self::assertSame(200, $updated->getStatusCode());
        self::assertSame(['en' => 'New'], $this->rows($this->handler->list())[0]['term']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refusedBodies(): iterable
    {
        yield 'code missing' => [['term' => ['en' => 'A']]];
        yield 'code too long' => [['code' => str_repeat('a', 65), 'term' => ['en' => 'A']]];
        yield 'term missing' => [['code' => 'A']];
        yield 'term has no text' => [['code' => 'A', 'term' => ['ar' => ' ', 'en' => '']]];
        yield 'term is a plain string' => [['code' => 'A', 'term' => 'Alpha']];
        yield 'term has another language' => [['code' => 'A', 'term' => ['fr' => 'Alpha']]];
        yield 'definition is the wrong shape' => [['code' => 'A', 'term' => ['en' => 'A'], 'definition' => 'x']];
        yield 'one language too long' => [['code' => 'A', 'term' => ['ar' => str_repeat('ب', 2001)]]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedBodies')]
    public function testABadBodyIsRefusedAndStoresNothing(array $body): void
    {
        TenantContext::setTenantId(self::TENANT_A);

        self::assertSame(400, $this->handler->create($this->body($body))->getStatusCode());
        self::assertSame([], $this->rows($this->handler->list()));
    }

    public function testAnotherWorkspaceCannotSeeChangeOrRemoveATerm(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $id = $this->createTerm('A', ['en' => 'Mine']);

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_B);
        self::assertSame([], $this->rows($this->handler->list()));
        self::assertSame(404, $this->handler->update($this->body(['code' => 'X', 'term' => ['en' => 'Theirs']]), $id)->getStatusCode());
        self::assertSame(404, $this->handler->delete($id)->getStatusCode());

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_A);
        self::assertSame(['en' => 'Mine'], $this->rows($this->handler->list())[0]['term']);
    }

    public function testDeleteRemovesTheTerm(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $id = $this->createTerm('A', ['en' => 'Gone']);

        self::assertSame(200, $this->handler->delete($id)->getStatusCode());
        self::assertSame([], $this->rows($this->handler->list()));
    }

    public function testNoWorkspaceIsRefused(): void
    {
        self::assertSame(403, $this->handler->list()->getStatusCode());
        self::assertSame(403, $this->handler->create($this->body(['code' => 'A', 'term' => ['en' => 'A']]))->getStatusCode());
        self::assertSame(403, $this->handler->update($this->body([]), 1)->getStatusCode());
        self::assertSame(403, $this->handler->delete(1)->getStatusCode());
    }

    public function testTheRoutesPublishTheMarkerForTheScreens(): void
    {
        $routes = (new GlossaryPlugin())->getRoutes();
        $components = $routes[1]['schema']['components'] ?? [];

        foreach (['GlossaryTerm', 'GlossaryTermRequest'] as $component) {
            foreach (['term', 'definition'] as $field) {
                self::assertTrue(
                    LocalizedText::isSchema($components[$component]['properties'][$field] ?? []),
                    "{$component}.{$field} must carry the marker"
                );
            }
        }
    }

    public function testGrantMigrationGivesAdminBothPermissionsAndRollsBack(): void
    {
        $this->pdo->exec("INSERT INTO roles (name, created_at) SELECT 'admin', CURRENT_TIMESTAMP WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name = 'admin')");
        $migration = new GrantGlossaryPermissionsToAdmin();

        $migration->up($this->pdo);
        $migration->up($this->pdo);
        self::assertSame(['glossary:manage', 'glossary:view'], $this->adminGlossaryGrants());

        $migration->down($this->pdo);
        self::assertSame([], $this->adminGlossaryGrants());
        self::assertSame('0', (string) $this->query("SELECT COUNT(*) FROM permissions WHERE name LIKE 'glossary:%'")->fetchColumn());
    }

    /**
     * @return list<string>
     */
    private function adminGlossaryGrants(): array
    {
        $stmt = $this->query(
            "SELECT p.name FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE r.name = 'admin' AND p.name LIKE 'glossary:%'
              ORDER BY p.name"
        );

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function query(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $stmt);

        return $stmt;
    }

    /**
     * @param array<string, string> $term
     */
    private function createTerm(string $code, array $term): int
    {
        return (int) $this->data($this->handler->create($this->body(['code' => $code, 'term' => $term])))['id'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function body(array $body): Request
    {
        return new Request('POST', '/api/glossary/terms', [], (string) json_encode($body));
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Response $response): array
    {
        return json_decode((string) $response->getBody(), true)['data'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(Response $response): array
    {
        return json_decode((string) $response->getBody(), true)['data'];
    }
}
