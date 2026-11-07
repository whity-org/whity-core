<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TenantsApiHandler;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * A workspace's name in more than one language (#821).
 *
 * `tenants.name` keeps the original, `tenants.name_language` its language, and
 * `localized_texts` the other languages. The API shows all of them as one
 * `names` map. The Arabic name is often the legal original, so nothing here
 * assumes English.
 */
final class TenantLocalizedNamesRealEngineTest extends TestCase
{
    private const AR = 'جامعة عمان الأهلية';
    private const EN = 'Al-Ahliyya Amman University';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES
            (1, 'Example Bakery', datetime('now')),
            (2, 'Example Florist', datetime('now'))");
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $this->pdo->exec("SELECT setval(pg_get_serial_sequence('tenants', 'id'), (SELECT MAX(id) FROM tenants))");
        }
        $_GET = [];
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        $_GET = [];
    }

    public function testAnExistingWorkspaceListsItsOriginalNameInEnglish(): void
    {
        MockRequestFactory::setTestTenant(1);
        $tenant = $this->list('/api/tenants')['data'][0];

        $this->assertSame('Example Bakery', $tenant['name']);
        $this->assertSame('en', $tenant['nameLanguage']);
        $this->assertSame(['en' => 'Example Bakery'], $tenant['names']);
    }

    public function testAnArabicOriginalWithAnEnglishNameRoundTrips(): void
    {
        MockRequestFactory::setTestTenant(1);
        $response = $this->update(1, ['names' => ['ar' => self::AR, 'en' => self::EN], 'nameLanguage' => 'ar']);
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());

        // The original stays in the column, so every existing query still sees it.
        $row = $this->row('SELECT name, name_language FROM tenants WHERE id = 1');
        $this->assertSame(self::AR, $row['name']);
        $this->assertSame('ar', $row['name_language']);
        $this->assertSame(
            [['language_code' => 'en', 'value' => self::EN]],
            $this->rows("SELECT language_code, value FROM localized_texts WHERE tenant_id = 1 AND entity_type = 'tenant' AND entity_id = 1")
        );

        $tenant = $this->list('/api/tenants')['data'][0];
        $this->assertSame(self::AR, $tenant['name']);
        $this->assertSame('ar', $tenant['nameLanguage']);
        $this->assertSame(['ar' => self::AR, 'en' => self::EN], $tenant['names']);
    }

    public function testRenamingTheOriginalKeepsTheOtherLanguages(): void
    {
        MockRequestFactory::setTestTenant(1);
        $this->update(1, ['names' => ['ar' => self::AR, 'en' => self::EN], 'nameLanguage' => 'ar']);

        $response = $this->update(1, ['name' => 'جامعة عمان']);
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());

        $tenant = $this->list('/api/tenants')['data'][0];
        $this->assertSame(['ar' => 'جامعة عمان', 'en' => self::EN], $tenant['names']);
    }

    public function testSwitchingTheOriginalLanguageSwapsWhichNameIsInTheColumn(): void
    {
        MockRequestFactory::setTestTenant(1);
        $this->update(1, ['names' => ['ar' => self::AR, 'en' => self::EN], 'nameLanguage' => 'ar']);

        $response = $this->update(1, ['nameLanguage' => 'en']);
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());

        $this->assertSame(self::EN, $this->scalar('SELECT name FROM tenants WHERE id = 1'));
        $this->assertSame(['en' => self::EN, 'ar' => self::AR], $this->list('/api/tenants')['data'][0]['names']);
    }

    public function testABlankLanguageIsRemoved(): void
    {
        MockRequestFactory::setTestTenant(1);
        $this->update(1, ['names' => ['ar' => self::AR, 'en' => self::EN], 'nameLanguage' => 'ar']);
        $this->update(1, ['names' => ['ar' => self::AR, 'en' => '']]);

        $this->assertSame(['ar' => self::AR], $this->list('/api/tenants')['data'][0]['names']);
    }

    public function testTheOriginalCannotBeLeftBlank(): void
    {
        MockRequestFactory::setTestTenant(1);
        $response = $this->update(1, ['names' => ['en' => self::EN], 'nameLanguage' => 'ar']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Example Bakery', $this->scalar('SELECT name FROM tenants WHERE id = 1'));
    }

    public function testABadLanguageCodeIsRefused(): void
    {
        MockRequestFactory::setTestTenant(1);

        $this->assertSame(422, $this->update(1, ['nameLanguage' => 'Arabic'])->getStatusCode());
        $this->assertSame(422, $this->update(1, ['names' => ['Arabic' => self::AR]])->getStatusCode());
        $this->assertSame(422, $this->update(1, ['names' => 'not a map'])->getStatusCode());
        $this->assertSame(422, $this->update(1, ['names' => ['en' => str_repeat('a', 256)]])->getStatusCode());
    }

    public function testAnotherLanguageCannotTakeAnotherWorkspacesName(): void
    {
        MockRequestFactory::setTestTenant(0);
        $response = $this->update(1, ['names' => ['en' => 'Example Florist']]);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testSearchFindsAWorkspaceByItsOtherLanguageName(): void
    {
        MockRequestFactory::setTestTenant(0);
        $this->update(1, ['names' => ['ar' => self::AR, 'en' => self::EN], 'nameLanguage' => 'ar']);

        $found = $this->list('/api/tenants?q=Ahliyya');
        $this->assertSame([1], array_column($found['data'], 'id'));
        $this->assertSame(1, $found['pagination']['total']);
    }

    public function testCreatingWithAnArabicNameRecordsArabicAsTheOriginal(): void
    {
        MockRequestFactory::setTestTenant(0);
        $response = $this->handler()->create(new Request('POST', '/api/tenants', [], (string) json_encode([
            'name' => self::AR,
            'slug' => 'aau',
        ])));

        $this->assertSame(201, $response->getStatusCode(), $response->getBody());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame('ar', $data['nameLanguage']);
        $this->assertSame(['ar' => self::AR], $data['names']);
    }

    public function testCreatingWithBothNames(): void
    {
        MockRequestFactory::setTestTenant(0);
        $response = $this->handler()->create(new Request('POST', '/api/tenants', [], (string) json_encode([
            'names' => ['ar' => self::AR, 'en' => self::EN],
            'nameLanguage' => 'ar',
            'slug' => 'aau',
        ])));

        $this->assertSame(201, $response->getStatusCode(), $response->getBody());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(self::AR, $data['name']);
        $this->assertSame(['ar' => self::AR, 'en' => self::EN], $data['names']);

        $id = (int) $data['id'];
        $this->assertSame(
            self::EN,
            $this->scalar("SELECT value FROM localized_texts WHERE tenant_id = {$id} AND entity_id = {$id} AND language_code = 'en'")
        );
    }

    public function testDeletingAWorkspaceRemovesItsNames(): void
    {
        MockRequestFactory::setTestTenant(0);
        $this->update(2, ['names' => ['en' => 'Example Florist', 'ar' => 'زهور المثال']]);

        $response = $this->handler()->delete(new Request('DELETE', '/api/tenants/2', []), ['id' => 2]);
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());

        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM localized_texts WHERE tenant_id = 2'));
    }

    /** @param array<string, mixed> $body */
    private function update(int $id, array $body): Response
    {
        return $this->handler()->update(
            new Request('PATCH', "/api/tenants/{$id}", [], (string) json_encode($body)),
            ['id' => $id]
        );
    }

    /** @return array<string, mixed> */
    private function list(string $path): array
    {
        $query = (string) parse_url($path, PHP_URL_QUERY);
        parse_str($query, $_GET);
        $response = $this->handler()->list(new Request('GET', $path, []));
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());

        return json_decode($response->getBody(), true);
    }

    private function handler(): TenantsApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        return new TenantsApiHandler($this->pdo, $hooks);
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

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt, 'Query failed: ' . $sql);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
