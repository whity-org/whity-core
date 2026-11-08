<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PluginSecretFieldsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Plugin\HostPluginSecrets;
use Whity\Core\Request;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\OpenAPI\CoreApiSchemas;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\Secrets\PluginSecretFieldsInterface;

/**
 * The host-drawn secret field (WHIT-1016): the value goes in and never comes
 * back out to a browser, and belongs to the caller's workspace.
 *
 * Runs on real Postgres in CI (PHPUNIT_PG_DSN), SQLite locally.
 */
final class PluginSecretFieldsApiHandlerRealEngineTest extends TestCase
{
    private const BAKERY = 11;
    private const FLORIST = 22;
    private const VALUE = 'sk-live-the-actual-secret';

    private PDO $pdo;
    private HostPluginSecrets $secrets;
    private PluginInterface $acme;
    private PluginSecretFieldsApiHandler $handler;
    private bool $permitted = true;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make();
        foreach ([self::BAKERY, self::FLORIST] as $id) {
            $this->pdo->prepare('INSERT INTO tenants (id, name, slug) VALUES (?, ?, ?) ON CONFLICT DO NOTHING')
                ->execute([$id, 'Example ' . $id, 'tenant-' . $id]);
        }

        $cipher = EncryptedSecretStore::fromEnv(['APP_ENV' => 'testing', 'ENCRYPTION_KEY' => str_repeat('k', 32)]);
        $this->secrets = new HostPluginSecrets($this->pdo, static fn (): EncryptedSecretStore => $cipher);
        $this->acme = new class () implements PluginInterface, PluginSecretFieldsInterface {
            public function getName(): string
            {
                return 'Acme';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getRoutes(): array
            {
                return [];
            }

            public function getHooks(): array
            {
                return [];
            }

            public function getPermissions(): array
            {
                return ['acme:settings:manage'];
            }

            public function getMigrations(): array
            {
                return [];
            }

            public function getSecretFields(): array
            {
                return ['api_key' => ['permission' => 'acme:settings:manage']];
            }
        };

        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(fn (): bool => $this->permitted);
        $this->handler = new PluginSecretFieldsApiHandler($roles, $this->secrets, fn (): array => [$this->acme]);

        TenantContext::setTenantId(self::BAKERY);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testTheValueIsStoredButNeverReturned(): void
    {
        $saved = $this->save();
        $status = $this->handler->status($this->request('GET'), $this->params());

        self::assertSame(200, $saved->getStatusCode());
        self::assertStringNotContainsString(self::VALUE, $saved->getBody());
        self::assertStringNotContainsString(self::VALUE, $status->getBody());
        self::assertTrue(json_decode($status->getBody(), true)['is_set']);
        self::assertSame(['is_set', 'updated_at'], array_keys(json_decode($status->getBody(), true)));
        self::assertSame(self::VALUE, $this->secrets->for($this->acme)->get(self::BAKERY, 'api_key'), 'the plugin itself can read it');
    }

    public function testThePublishedContractAndTheAnswersCarryOnlyTheStatus(): void
    {
        $contract = CoreApiSchemas::components()['PluginSecretFieldStatusResponse'];
        self::assertFalse($contract['additionalProperties']);
        self::assertSame(['is_set', 'updated_at'], array_keys($contract['properties']));

        $routes = array_values(array_filter(
            CoreApiSchemas::routes(),
            static fn (array $route): bool => $route['path'] === '/api/plugin-secrets/{plugin}/{key}',
        ));
        self::assertSame(['GET', 'PUT', 'DELETE'], array_column($routes, 'method'));
        foreach ($routes as $route) {
            self::assertStringEndsWith(
                '/PluginSecretFieldStatusResponse',
                $route['schema']['responses'][200]['content']['application/json']['schema']['$ref'],
                $route['method'],
            );
            self::assertSame(['expose' => false], $route['schema']['mcp'], $route['method']);
        }

        foreach (
            [
                $this->save(),
                $this->handler->status($this->request('GET'), $this->params()),
                $this->handler->clear($this->request('DELETE'), $this->params()),
            ] as $answer
        ) {
            self::assertSame(array_keys($contract['properties']), array_keys(json_decode($answer->getBody(), true)));
        }
    }

    public function testItIsStoredEncrypted(): void
    {
        $this->save();

        $rows = $this->pdo->query('SELECT value FROM plugin_secrets');
        self::assertNotFalse($rows);
        $stored = $rows->fetchColumn();
        self::assertIsString($stored);
        self::assertStringNotContainsString(self::VALUE, $stored);
    }

    public function testAnotherWorkspaceDoesNotSeeIt(): void
    {
        $this->save();

        TenantContext::reset();
        TenantContext::setTenantId(self::FLORIST);
        $status = $this->handler->status($this->request('GET'), $this->params());

        self::assertFalse(json_decode($status->getBody(), true)['is_set']);
        self::assertNull($this->secrets->for($this->acme)->get(self::FLORIST, 'api_key'));
    }

    public function testRemovingIt(): void
    {
        $this->save();

        $cleared = $this->handler->clear($this->request('DELETE'), $this->params());

        self::assertFalse(json_decode($cleared->getBody(), true)['is_set']);
        self::assertNull($this->secrets->for($this->acme)->get(self::BAKERY, 'api_key'));
    }

    public function testWithoutThePermissionNothingHappens(): void
    {
        $this->permitted = false;

        self::assertSame(403, $this->save()->getStatusCode());
        self::assertSame(403, $this->handler->status($this->request('GET'), $this->params())->getStatusCode());
        self::assertNull($this->secrets->for($this->acme)->get(self::BAKERY, 'api_key'));
    }

    public function testAnUndeclaredSecretIsNotThere(): void
    {
        $this->secrets->for($this->acme)->set(self::BAKERY, 'internal', 'kept-by-the-plugin');

        $status = $this->handler->status($this->request('GET'), ['plugin' => 'acme', 'key' => 'internal']);
        $cleared = $this->handler->clear($this->request('DELETE'), ['plugin' => 'acme', 'key' => 'internal']);

        self::assertSame(404, $status->getStatusCode());
        self::assertSame(404, $cleared->getStatusCode());
        self::assertSame('kept-by-the-plugin', $this->secrets->for($this->acme)->get(self::BAKERY, 'internal'));
    }

    public function testAnUnknownPluginIsNotThere(): void
    {
        self::assertSame(404, $this->handler->status($this->request('GET'), ['plugin' => 'rival', 'key' => 'api_key'])->getStatusCode());
    }

    public function testNobodySignedInIsRefused(): void
    {
        self::assertSame(401, $this->handler->status(new Request('GET', '/x'), $this->params())->getStatusCode());
    }

    public function testTheSystemWorkspaceIsRefused(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(0);

        self::assertSame(400, $this->handler->status($this->request('GET'), $this->params())->getStatusCode());
    }

    public function testAnEmptyValueIsRefusedAndAnErrorDoesNotEchoIt(): void
    {
        self::assertSame(422, $this->handler->save($this->request('PUT', ['value' => '']), $this->params())->getStatusCode());

        $tooLong = str_repeat('s', 16385);
        $refused = $this->handler->save($this->request('PUT', ['value' => $tooLong]), $this->params());

        self::assertSame(422, $refused->getStatusCode());
        self::assertStringNotContainsString($tooLong, $refused->getBody());
    }

    private function save(): Response
    {
        return $this->handler->save($this->request('PUT', ['value' => self::VALUE]), $this->params());
    }

    /** @return array<string, string> */
    private function params(): array
    {
        return ['plugin' => 'acme', 'key' => 'api_key'];
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, array $body = []): Request
    {
        $request = new Request($method, '/api/v1/plugin-secrets/acme/api_key');
        $request->user = (object) ['profile_id' => 101];
        $request->setAttribute(JsonBody::ATTR_PARSED_BODY, $body);
        return $request;
    }
}
