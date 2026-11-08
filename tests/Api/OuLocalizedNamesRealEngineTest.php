<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\OusApiHandler;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * A unit's name in more than one language (#821, WHIT-978).
 *
 * Units use the same mechanism as workspaces: `organizational_units.name`
 * keeps the original, `name_language` its language, and `localized_texts`
 * (entity_type `ou`) the other languages.
 */
final class OuLocalizedNamesRealEngineTest extends TestCase
{
    private const AR = 'كلية الهندسة';
    private const EN = 'Faculty of Engineering';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Example Bakery'), (2, 'Example Florist')");
        $this->pdo->exec("
            INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, description, created_at) VALUES
                (10, 1, NULL, 'Kitchen', 'kitchen', '', datetime('now')),
                (11, 1, NULL, 'Front of house', 'front-of-house', '', datetime('now')),
                (30, 2, NULL, 'Greenhouse', 'greenhouse', '', datetime('now'))
        ");
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $this->pdo->exec("SELECT setval(pg_get_serial_sequence('organizational_units', 'id'), (SELECT MAX(id) FROM organizational_units))");
        }
        $_GET = [];
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        $_GET = [];
    }

    public function testAnExistingUnitListsItsOriginalNameInEnglish(): void
    {
        MockRequestFactory::setTestTenant(1);
        $unit = $this->unit(10);

        $this->assertSame('Kitchen', $unit['name']);
        $this->assertSame('en', $unit['name_language']);
        $this->assertSame(['en' => 'Kitchen'], $unit['names']);
    }

    public function testAnArabicOriginalWithAnEnglishNameRoundTrips(): void
    {
        MockRequestFactory::setTestTenant(1);
        $response = $this->update(10, ['names' => ['ar' => self::AR, 'en' => self::EN], 'name_language' => 'ar']);
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());

        $row = $this->row('SELECT name, name_language FROM organizational_units WHERE id = 10');
        $this->assertSame(self::AR, $row['name']);
        $this->assertSame('ar', $row['name_language']);

        $unit = $this->unit(10);
        $this->assertSame(['ar' => self::AR, 'en' => self::EN], $unit['names']);

        $detail = json_decode($this->handler()->get(new Request('GET', '/api/ous/10'), ['id' => 10])->getBody(), true)['data'];
        $this->assertSame('ar', $detail['name_language']);
        $this->assertSame(['ar' => self::AR, 'en' => self::EN], $detail['names']);
    }

    public function testRenamingTheOriginalKeepsTheOtherLanguages(): void
    {
        MockRequestFactory::setTestTenant(1);
        $this->update(10, ['names' => ['ar' => self::AR, 'en' => self::EN], 'name_language' => 'ar']);
        $this->assertSame(200, $this->update(10, ['name' => 'الهندسة'])->getStatusCode());

        $this->assertSame(['ar' => 'الهندسة', 'en' => self::EN], $this->unit(10)['names']);
    }

    public function testTheOriginalCannotBeLeftBlank(): void
    {
        MockRequestFactory::setTestTenant(1);
        $response = $this->update(10, ['names' => ['en' => self::EN], 'name_language' => 'ar']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Kitchen', $this->scalar('SELECT name FROM organizational_units WHERE id = 10'));
    }

    public function testASiblingCannotTakeTheSameOriginalName(): void
    {
        MockRequestFactory::setTestTenant(1);
        $response = $this->update(11, ['names' => ['en' => 'Kitchen', 'ar' => 'المطبخ']]);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testCreatingWithBothNames(): void
    {
        MockRequestFactory::setTestTenant(1);
        $response = $this->handler()->create(new Request('POST', '/api/ous', [], (string) json_encode([
            'name' => self::AR,
            'names' => ['ar' => self::AR, 'en' => self::EN],
            'name_language' => 'ar',
        ])));

        $this->assertSame(201, $response->getStatusCode(), $response->getBody());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(self::AR, $data['name']);
        $this->assertSame('ar', $data['name_language']);
        $this->assertSame(['ar' => self::AR, 'en' => self::EN], $data['names']);
        $this->assertSame(['ar' => self::AR, 'en' => self::EN], $this->unit((int) $data['id'])['names']);
    }

    public function testCreatingWithAnArabicNameRecordsArabicAsTheOriginal(): void
    {
        MockRequestFactory::setTestTenant(1);
        $response = $this->handler()->create(new Request('POST', '/api/ous', [], (string) json_encode(['name' => self::AR])));

        $this->assertSame(201, $response->getStatusCode(), $response->getBody());
        $this->assertSame('ar', json_decode($response->getBody(), true)['data']['name_language']);
    }

    public function testAnotherWorkspaceNeverSeesTheNames(): void
    {
        MockRequestFactory::setTestTenant(1);
        $this->update(10, ['names' => ['ar' => self::AR, 'en' => self::EN], 'name_language' => 'ar']);

        MockRequestFactory::setTestTenant(2);
        $this->assertSame(403, $this->update(10, ['names' => ['en' => 'Taken over']])->getStatusCode());
        $this->assertSame(
            self::EN,
            $this->scalar("SELECT value FROM localized_texts WHERE entity_type = 'ou' AND entity_id = 10 AND language_code = 'en'")
        );
        $listed = $this->list();
        $this->assertSame([30], array_map('intval', array_column($listed, 'id')));
    }

    public function testTheSystemListingCarriesEachWorkspacesNames(): void
    {
        MockRequestFactory::setTestTenant(1);
        $this->update(10, ['names' => ['ar' => self::AR, 'en' => self::EN], 'name_language' => 'ar']);
        MockRequestFactory::setTestTenant(2);
        $this->update(30, ['names' => ['en' => 'Greenhouse', 'ar' => 'الدفيئة']]);

        MockRequestFactory::setTestTenant(0);
        $byId = [];
        foreach ($this->list() as $unit) {
            $byId[(int) $unit['id']] = $unit['names'];
        }
        $this->assertSame(['ar' => self::AR, 'en' => self::EN], $byId[10]);
        $this->assertSame(['en' => 'Greenhouse', 'ar' => 'الدفيئة'], $byId[30]);
    }

    public function testDeletingAUnitRemovesItsNames(): void
    {
        MockRequestFactory::setTestTenant(1);
        $this->update(11, ['names' => ['en' => 'Front of house', 'ar' => 'الصالة']]);

        $response = $this->handler()->delete(new Request('DELETE', '/api/ous/11'), ['id' => 11]);
        $this->assertSame(204, $response->getStatusCode(), $response->getBody());
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM localized_texts WHERE entity_type = 'ou' AND entity_id = 11"));
    }

    /** @param array<string, mixed> $body */
    private function update(int $id, array $body): Response
    {
        return $this->handler()->update(
            new Request('PATCH', "/api/ous/{$id}", [], (string) json_encode($body)),
            ['id' => $id]
        );
    }

    /** @return list<array<string, mixed>> */
    private function list(): array
    {
        $response = $this->handler()->list(new Request('GET', '/api/ous'));
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());

        return json_decode($response->getBody(), true)['data'];
    }

    /** @return array<string, mixed> */
    private function unit(int $id): array
    {
        foreach ($this->list() as $unit) {
            if ((int) $unit['id'] === $id) {
                return $unit;
            }
        }
        $this->fail("Unit {$id} is not listed.");
    }

    private function handler(): OusApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        return new OusApiHandler($this->pdo, $hooks);
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt, 'Query failed: ' . $sql);

        return $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt, 'Query failed: ' . $sql);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }
}
