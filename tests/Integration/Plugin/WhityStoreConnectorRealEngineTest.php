<?php

declare(strict_types=1);

namespace Tests\Integration\Plugin;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Plugin\Store\WhityStoreConnectException;
use Whity\Core\Plugin\Store\WhityStoreConnector;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;

/**
 * WHIT-1003: an installation connects to a Whity store through Whity ID, and a fresh access
 * token then rides every store call. Real app_settings (leases, compare-and-set), fake HTTP.
 */
final class WhityStoreConnectorRealEngineTest extends TestCase
{
    private const KEY = 'unit-test-encryption-key-please-ignore-0123456789';
    private const ISSUER = 'https://id.whity.test';
    private const STORE = 'https://store.whity.test';
    private const RESOURCE = 'https://store.whity.test/api/v1/plugin-store';
    private const REDIRECT = 'https://bakery.example.test/admin/plugins/store/connect';

    private GlobalSettingsRepository $globals;
    private EncryptedSecretStore $secrets;
    private int $now = 2_000_000_000;

    /** @var list<array{string, string, array<mixed>}> */
    private array $calls = [];

    /** @var array<string, list<array<mixed>|null>> scripted POST replies per URL */
    private array $replies = [];

    private int $registrations = 0;

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $this->globals = new GlobalSettingsRepository($pdo);
        $this->secrets = new EncryptedSecretStore(['v1' => self::KEY], 'v1');
    }

    /** @param list<string> $issuers */
    private function connector(string $redirect = self::REDIRECT, array $issuers = [self::ISSUER]): WhityStoreConnector
    {
        return new WhityStoreConnector(
            $this->globals,
            $this->secrets,
            $issuers,
            $redirect,
            'Whity (bakery.example.test)',
            function (string $url, array $headers = []): ?array {
                $this->calls[] = ['GET', $url, $headers];

                return match ($url) {
                    self::STORE . '/api/v1/plugin-store/auth' => [
                        'resource' => self::RESOURCE,
                        'authorization_servers' => [self::ISSUER],
                        'scopes_supported' => ['store:download'],
                    ],
                    self::ISSUER . '/.well-known/oauth-authorization-server' => [
                        'issuer' => self::ISSUER,
                        'authorization_endpoint' => self::ISSUER . '/authorize',
                        'token_endpoint' => self::ISSUER . '/oauth/token',
                        'registration_endpoint' => self::ISSUER . '/oauth/register',
                        'revocation_endpoint' => self::ISSUER . '/oauth/revoke',
                    ],
                    default => null,
                };
            },
            function (string $url, array $params): ?array {
                $this->calls[] = ['POST', $url, $params];
                $queue = $this->replies[$url] ?? [];
                $reply = array_shift($queue);
                $this->replies[$url] = $queue;

                return $reply;
            },
            function (string $url, array $payload): array {
                $this->calls[] = ['JSON', $url, $payload];
                $this->registrations++;

                return ['client_id' => 'wid_dyn_installation' . $this->registrations];
            },
            fn (): int => $this->now,
            function (int $microseconds): void {
                $this->now += 1;
            },
        );
    }

    /** @param array<string, mixed> $claims */
    private static function idToken(array $claims): string
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        return $b64('{"alg":"RS256"}') . '.' . $b64((string) json_encode($claims)) . '.sig';
    }

    /** @return array{0: WhityStoreConnector, 1: array<string, string>} */
    private function startedConnect(): array
    {
        $connector = $this->connector();
        $url = $connector->start(self::STORE, 7);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parsed);
        $query = [];
        foreach ($parsed as $key => $value) {
            if (is_string($value)) {
                $query[(string) $key] = $value;
            }
        }

        return [$connector, $query];
    }

    private function connect(): WhityStoreConnector
    {
        [$connector, $query] = $this->startedConnect();
        $this->replies[self::ISSUER . '/oauth/token'][] = [
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 300,
            'id_token' => self::idToken([
                'iss' => self::ISSUER,
                'aud' => $query['client_id'],
                'nonce' => $query['nonce'],
                'sub' => '01JSUB',
                'name' => 'Rania Haddad',
                'email' => 'rania@example-bakery.test',
            ]),
        ];
        $connector->complete('the-code', $query['state'], 7);

        return $connector;
    }

    public function testStartRegistersOnceAndAsksForTheStoreResource(): void
    {
        [$connector, $query] = $this->startedConnect();

        self::assertSame('code', $query['response_type']);
        self::assertSame('wid_dyn_installation1', $query['client_id']);
        self::assertSame(self::REDIRECT, $query['redirect_uri']);
        self::assertSame(self::RESOURCE, $query['resource']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertStringContainsString('store:download', $query['scope']);

        $registration = array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'JSON'))[0][2];
        self::assertSame([self::REDIRECT], $registration['redirect_uris']);
        self::assertSame('web', $registration['application_type']);
        self::assertSame('none', $registration['token_endpoint_auth_method']);
        self::assertSame('whity-core', $registration['software_id']);

        $connector->start(self::STORE, 7);
        self::assertSame(1, $this->registrations, 'a second connect reuses the registered client');
    }

    public function testCompleteStoresTheConnectionAndNeverReturnsATokens(): void
    {
        $connector = $this->connect();
        $status = $connector->status();

        self::assertTrue($status['connected']);
        self::assertSame('store.whity.test', $status['store_host']);
        self::assertSame('Rania Haddad', $status['account']['name']);
        self::assertSame('rania@example-bakery.test', $status['account']['email']);
        self::assertStringNotContainsString('refresh-1', (string) json_encode($status));
        self::assertStringNotContainsString('refresh-1', (string) $this->globals->get(WhityStoreConnector::REFRESH_TOKEN_KEY));

        $exchange = array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'POST'))[0][2];
        self::assertSame('authorization_code', $exchange['grant_type']);
        self::assertSame(self::RESOURCE, $exchange['resource']);
        self::assertNotEmpty($exchange['code_verifier']);
    }

    public function testAWrongOrReusedStateIsRefused(): void
    {
        [$connector, $query] = $this->startedConnect();

        try {
            $connector->complete('the-code', 'not-the-state', 7);
            self::fail('a wrong state must be refused');
        } catch (WhityStoreConnectException $e) {
            self::assertSame(422, $e->status);
        }

        $this->expectException(WhityStoreConnectException::class);
        $connector->complete('the-code', $query['state'], 7);
    }

    public function testAnotherOperatorCannotFinishSomeoneElsesConnect(): void
    {
        [$connector, $query] = $this->startedConnect();

        $this->expectException(WhityStoreConnectException::class);
        $connector->complete('the-code', $query['state'], 8);
    }

    public function testAnExpiredConnectIsRefused(): void
    {
        [$connector, $query] = $this->startedConnect();
        $this->now += 3600;

        $this->expectException(WhityStoreConnectException::class);
        $connector->complete('the-code', $query['state'], 7);
    }

    public function testAnUntrustedIssuerIsRefused(): void
    {
        $this->expectException(WhityStoreConnectException::class);
        $this->connector(self::REDIRECT, ['https://id.elsewhere.test'])->start(self::STORE, 7);
    }

    public function testAPlainHttpInstallationOffLoopbackCannotConnect(): void
    {
        $this->expectException(WhityStoreConnectException::class);
        $this->connector('http://intranet.bakery/admin/plugins/store/connect')->start(self::STORE, 7);
    }

    public function testALoopbackInstallationRegistersAsNative(): void
    {
        $this->connector('http://localhost:8091/admin/plugins/store/connect')->start(self::STORE, 7);

        $registration = array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'JSON'))[0][2];
        self::assertSame('native', $registration['application_type']);
        self::assertArrayNotHasKey('client_uri', $registration);
    }

    public function testTheCachedTokenIsUsedThenRefreshedAndRotated(): void
    {
        $connector = $this->connect();

        self::assertSame('access-1', $connector->accessTokenFor('store.whity.test'));
        self::assertNull($connector->accessTokenFor('other.store.test'), 'only the connected store gets a token');

        $this->now += 290;
        $this->replies[self::ISSUER . '/oauth/token'][] = ['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 300];
        self::assertSame('access-2', $connector->accessTokenFor('store.whity.test'));

        $refresh = array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'POST'))[1][2];
        self::assertSame('refresh_token', $refresh['grant_type']);
        self::assertSame('refresh-1', $refresh['refresh_token']);
        self::assertSame(self::RESOURCE, $refresh['resource']);
        self::assertSame('refresh-2', $this->secrets->decrypt((string) $this->globals->get(WhityStoreConnector::REFRESH_TOKEN_KEY)));

        self::assertSame('access-2', $connector->accessTokenFor('store.whity.test'), 'cached again, no second refresh');
        self::assertCount(2, array_filter($this->calls, static fn (array $c): bool => $c[0] === 'POST'));
    }

    public function testAnotherWorkersLeaseIsWaitedFor(): void
    {
        $connector = $this->connect();
        $this->now += 400; // cached token expired
        $lease = $this->globals->claimLease(WhityStoreConnector::LEASE_KEY, 5, $this->now);
        self::assertNotNull($lease);

        // While the lease is held nothing refreshes; once it lapses this worker refreshes itself.
        $this->replies[self::ISSUER . '/oauth/token'][] = ['access_token' => 'access-3', 'refresh_token' => 'refresh-3', 'expires_in' => 300];
        self::assertSame('access-3', $connector->accessTokenFor('store.whity.test'));
    }

    public function testARevokedChainAsksForAPerson(): void
    {
        $connector = $this->connect();
        $this->now += 400;
        $this->replies[self::ISSUER . '/oauth/token'][] = ['error' => 'invalid_grant'];

        self::assertNull($connector->accessTokenFor('store.whity.test'));
        self::assertTrue($connector->status()['needs_reconnect']);
        self::assertNull($connector->accessTokenFor('store.whity.test'), 'no second refresh attempt');
    }

    public function testStoreCallsCarryTheConnectionsTokenUnlessOneIsGiven(): void
    {
        $connector = $this->connect();
        $pdo = SchemaFromMigrations::make(true);
        $settings = new \Whity\Core\Settings\SettingsService(
            new GlobalSettingsRepository($pdo),
            new \Whity\Core\Settings\TenantSettingsRepository($pdo),
        );
        $settings->setGlobal(\Whity\Core\Settings\SettingsRegistry::PLUGINS_STORE_ALLOWED_HOSTS, 'store.whity.test');

        $sent = [];
        $handler = new \Whity\Api\InstallFromStoreApiHandler(
            sys_get_temp_dir() . '/whity_store_connect_' . uniqid(),
            $settings,
            null,
            null,
            function (string $url, array $headers) use (&$sent): ?string {
                $sent[] = $headers;

                return null; // the store "refuses"; only the headers matter here
            },
            function (string $url, array $headers = []) use (&$sent): array {
                $sent[] = $headers;

                return ['data' => []];
            },
            null, null, null, null,
            $connector,
        );

        \Whity\Core\Tenant\TenantContext::reset();
        \Whity\Core\Tenant\TenantContext::setTenantId(0);
        try {
            $install = static fn (array $body) => $handler->install(new \Whity\Core\Request(
                'POST',
                '/api/plugins/install-from-store',
                [],
                (string) json_encode(array_merge(['store_url' => self::STORE, 'slug' => 'demo', 'version' => '1.0.0'], $body)),
            ));

            $install([]);
            self::assertSame(['Authorization' => 'Bearer access-1'], $sent[0]);

            $install(['token' => 'wps_manual']);
            self::assertSame(['Authorization' => 'Bearer wps_manual'], $sent[1], 'an operator\'s own token wins');

            $handler->browseCatalog(new \Whity\Core\Request('GET', '/api/plugins/store/catalog?store_url=' . urlencode(self::STORE), [], ''));
            self::assertSame(['Authorization' => 'Bearer access-1'], $sent[2], 'the catalogue call carries it too');

            $allowed = json_decode($handler->allowedStores(new \Whity\Core\Request('GET', '/x', [], ''))->getBody(), true);
            self::assertTrue($allowed['data']['connection']['connected']);
            self::assertStringNotContainsString('access-1', (string) json_encode($allowed));
        } finally {
            \Whity\Core\Tenant\TenantContext::reset();
        }
    }

    public function testDisconnectRevokesAndForgets(): void
    {
        $connector = $this->connect();
        $this->replies[self::ISSUER . '/oauth/revoke'][] = [];

        $connector->disconnect();

        $revoke = array_values(array_filter($this->calls, static fn (array $c): bool => $c[1] === self::ISSUER . '/oauth/revoke'))[0][2];
        self::assertSame('refresh-1', $revoke['token']);
        self::assertSame(['connected' => false], $connector->status());
        self::assertNull($connector->accessTokenFor('store.whity.test'));
        self::assertNull($this->globals->get(WhityStoreConnector::REFRESH_TOKEN_KEY));
        self::assertNotNull($this->globals->get(WhityStoreConnector::CLIENT_KEY), 'the registration is kept for next time');
    }
}
