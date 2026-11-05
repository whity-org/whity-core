<?php

declare(strict_types=1);

namespace Tests\Integration\Mail;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\FakeOAuth\LoopbackTokenHttp;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Http\HttpFetcher;
use Whity\Core\Mail\MailerFactory;
use Whity\Core\Mail\OAuth\OAuthTokenException;
use Whity\Core\Mail\OAuth\SmtpOAuthState;
use Whity\Core\Mail\OAuth\SmtpOAuthStore;
use Whity\Core\Mail\SmtpAuthRejectedException;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

require_once __DIR__ . '/../../Support/FakeOAuth/LoopbackTokenHttp.php';

/**
 * XOAUTH2 mail end to end against real sockets (WHIT-901): a fake token endpoint
 * over HTTP and a fake SMTP server that checks the XOAUTH2 string, driven through
 * the real factory, mailer, token manager and database. No live provider is
 * involved anywhere in this suite.
 */
final class SmtpOAuthFakeServersRealEngineTest extends TestCase
{
    private const KEY = 'unit-test-encryption-key-please-ignore-0123456789';
    private const MAILBOX = 'mail@example-bakery.test';
    private const CLIENT_SECRET = 'client-secret-MUST-NOT-LEAK';
    private const REFRESH_TOKEN = 'refresh-token-MUST-NOT-LEAK';

    private string $dir;
    private int $smtpPort;
    private string $tokenBase;
    /** @var list<resource> */
    private array $processes = [];
    private PDO $pdo;
    private GlobalSettingsRepository $globals;
    private SettingsService $settings;
    private EncryptedSecretStore $secrets;
    private LoopbackTokenHttp $http;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/whity-fake-oauth-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        touch($this->dir . '/requests.log');
        touch($this->dir . '/auth.log');
        touch($this->dir . '/delivered.log');
        $this->scenario(['mode' => 'ok']);
        $this->acceptTokens(['tok-1', 'tok-2', 'tok-3']);

        $tokenPort = $this->freePort();
        $this->smtpPort = $this->freePort();
        $this->tokenBase = 'http://127.0.0.1:' . $tokenPort;

        $this->spawn(
            [PHP_BINARY, '-S', '127.0.0.1:' . $tokenPort, __DIR__ . '/../../Support/FakeOAuth/token-endpoint.php'],
            ['FAKE_OAUTH_DIR' => $this->dir, 'PHP_CLI_SERVER_WORKERS' => '8'],
        );
        $this->spawn([PHP_BINARY, __DIR__ . '/../../Support/FakeOAuth/smtp-server.php', (string) $this->smtpPort, $this->dir]);
        $this->waitForPort($tokenPort);
        $this->waitForPort($this->smtpPort);

        $this->pdo = SchemaFromMigrations::make(true);
        $this->globals = new GlobalSettingsRepository($this->pdo);
        $this->settings = new SettingsService($this->globals, new TenantSettingsRepository($this->pdo));
        $this->secrets = new EncryptedSecretStore(['v1' => self::KEY], 'v1');
        $this->http = new LoopbackTokenHttp($this->tokenBase);

        foreach ([
            SettingsRegistry::MAIL_TRANSPORT => 'smtp',
            SettingsRegistry::MAIL_SMTP_HOST => '127.0.0.1',
            SettingsRegistry::MAIL_SMTP_PORT => (string) $this->smtpPort,
            SettingsRegistry::MAIL_SMTP_ENCRYPTION => 'none',
            SettingsRegistry::MAIL_SMTP_USERNAME => self::MAILBOX,
            SettingsRegistry::MAIL_FROM_ADDRESS => self::MAILBOX,
            SettingsRegistry::MAIL_SMTP_AUTH_MODE => 'oauth2',
            SettingsRegistry::MAIL_OAUTH_PROVIDER => 'google',
            SettingsRegistry::MAIL_OAUTH_CLIENT_ID => 'client-id-1',
        ] as $key => $value) {
            $this->settings->setGlobal($key, $value);
        }

        $store = new SmtpOAuthStore($this->globals, $this->secrets);
        $store->setClientSecret(self::CLIENT_SECRET);
        $store->setRefreshToken(self::REFRESH_TOKEN);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testFetchesATokenAndSignsInWithAWellFormedXoauth2String(): void
    {
        $this->send('First');

        self::assertSame(['ok ' . self::MAILBOX . ' tok-1'], $this->lines('auth.log'));
        self::assertSame(['First'], $this->lines('delivered.log'));

        $requests = $this->requests();
        self::assertCount(1, $requests);
        self::assertSame('/google/token', $requests[0]['path']);
        self::assertSame([
            'grant_type' => 'refresh_token',
            'client_id' => 'client-id-1',
            'client_secret' => self::CLIENT_SECRET,
            'refresh_token' => self::REFRESH_TOKEN,
        ], $requests[0]['form']);
        self::assertSame(['https://oauth2.googleapis.com/token'], $this->http->urls);
    }

    public function testACachedTokenIsReusedWithoutAskingTheProviderAgain(): void
    {
        $this->send('One');
        $this->send('Two');

        self::assertCount(1, $this->requests());
        self::assertSame(['One', 'Two'], $this->lines('delivered.log'));
    }

    public function testATokenCloseToExpiryIsRefreshedAhead(): void
    {
        // 100 seconds left is inside the refresh window, but still usable.
        $this->scenario(['mode' => 'ok', 'expires_in' => 100]);
        $this->send('One');
        $this->scenario(['mode' => 'ok', 'expires_in' => 3600]);
        $this->send('Two');

        self::assertCount(2, $this->requests());
        self::assertSame(['ok ' . self::MAILBOX . ' tok-1', 'ok ' . self::MAILBOX . ' tok-2'], $this->lines('auth.log'));
    }

    public function testARefusedTokenIsRefreshedAndRetriedOnceAndDeliversOnce(): void
    {
        $this->send('One');
        // The server stops accepting tok-1, as after a revocation or a clock slip.
        $this->acceptTokens(['tok-2']);

        $this->send('Two');

        self::assertSame([
            'ok ' . self::MAILBOX . ' tok-1',
            'refused ' . self::MAILBOX . ' tok-1',
            'ok ' . self::MAILBOX . ' tok-2',
        ], $this->lines('auth.log'));
        self::assertSame(['One', 'Two'], $this->lines('delivered.log'));
    }

    public function testAServerThatKeepsRefusingStopsAfterOneRetryAndSaysSo(): void
    {
        $this->acceptTokens([]);

        try {
            $this->send('Never');
            self::fail('The send should have failed');
        } catch (SmtpAuthRejectedException $e) {
            self::assertStringContainsString('SMTP username', $e->publicHint());
        }

        self::assertCount(2, $this->lines('auth.log'));
        self::assertCount(2, $this->requests());
        self::assertSame([], $this->lines('delivered.log'));
        self::assertSame(SmtpOAuthState::SmtpRejected, $this->recordedState());
    }

    public function testARevokedRefreshTokenBecomesAPlainStatusAndStopsHittingTheProvider(): void
    {
        $this->scenario(['mode' => 'invalid_grant']);

        try {
            $this->send('Lost');
            self::fail('The send should have failed, not vanished');
        } catch (OAuthTokenException $e) {
            self::assertSame(SmtpOAuthState::NeedsReconnect, $e->state);
            self::assertStringContainsString('needs reconnecting', $e->publicHint());
            self::assertStringNotContainsString('PROVIDER-WORDS', $e->getMessage() . $e->publicHint());
        }
        self::assertSame(SmtpOAuthState::NeedsReconnect, $this->recordedState());

        try {
            $this->send('Lost again');
            self::fail('The send should have failed');
        } catch (OAuthTokenException) {
        }
        self::assertCount(1, $this->requests(), 'A refused refresh token is not retried on every send');
        self::assertSame([], $this->lines('auth.log'), 'No token, no SMTP sign-in attempt');
    }

    public function testAProviderOutageIsNotMistakenForARevocation(): void
    {
        $this->scenario(['mode' => 'server_error']);

        try {
            $this->send('Later');
            self::fail('The send should have failed');
        } catch (OAuthTokenException $e) {
            self::assertSame(SmtpOAuthState::TokenServiceUnavailable, $e->state);
        }
        self::assertSame(SmtpOAuthState::TokenServiceUnavailable, $this->recordedState());
    }

    public function testMicrosoftUsesItsFixedEndpointScopeAndRotatedRefreshToken(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_OAUTH_PROVIDER, 'microsoft');
        $this->settings->setGlobal(SettingsRegistry::MAIL_OAUTH_MICROSOFT_TENANT, '11111111-2222-3333-4444-555555555555');
        $this->scenario(['mode' => 'ok', 'rotate' => true]);

        $this->send('One');

        self::assertSame(
            ['https://login.microsoftonline.com/11111111-2222-3333-4444-555555555555/oauth2/v2.0/token'],
            $this->http->urls,
        );
        self::assertSame('https://outlook.office365.com/SMTP.Send offline_access', $this->requests()[0]['form']['scope']);

        // The rotated token is what the store now holds, still encrypted.
        $stored = (string) $this->globals->get(SmtpOAuthStore::REFRESH_TOKEN_KEY);
        self::assertStringNotContainsString('rotated-refresh-1', $stored);
        self::assertSame('rotated-refresh-1', $this->secrets->decrypt($stored));
        // The token cached alongside it survives the rotation.
        $this->send('Two');
        self::assertCount(1, $this->requests());
    }

    public function testNothingSecretIsStoredInTheClear(): void
    {
        $this->send('One');

        $stmt = $this->pdo->query("SELECT setting_key, value FROM app_settings WHERE setting_key LIKE 'mail.%'");
        self::assertNotFalse($stmt);
        $all = (string) json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

        foreach ([self::CLIENT_SECRET, self::REFRESH_TOKEN, 'tok-1'] as $secret) {
            self::assertStringNotContainsString($secret, $all);
        }
        self::assertStringContainsString(SmtpOAuthStore::ACCESS_TOKEN_KEY, $all, 'The token cache row exists, encrypted');
    }

    public function testConcurrentWorkersShareOneRefresh(): void
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            self::markTestSkipped('Separate worker processes need a real shared database: runs on PostgreSQL');
        }

        $query = $this->pdo->query('SELECT current_database()');
        self::assertNotFalse($query);
        $database = (string) $query->fetchColumn();
        $dsn = (string) preg_replace('/dbname=[^;]+/', 'dbname=' . $database, (string) getenv('PHPUNIT_PG_DSN'));

        // A slow provider, so every worker arrives while the first is still refreshing.
        $this->scenario(['mode' => 'ok', 'delay_ms' => 800]);
        $startAt = (string) (microtime(true) + 1.0);

        $workers = [];
        for ($i = 0; $i < 6; $i++) {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                __DIR__ . '/../../Support/FakeOAuth/concurrent-token-worker.php',
                $dsn,
                (string) getenv('PHPUNIT_PG_USER'),
                (string) getenv('PHPUNIT_PG_PASSWORD'),
                self::KEY,
                $this->tokenBase,
                $startAt,
            ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            /** @var array{1: resource, 2: resource} $pipes */
            $workers[] = [$process, $pipes];
        }

        $tokens = [];
        foreach ($workers as [$process, $pipes]) {
            $tokens[] = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $errors);
        }

        self::assertCount(1, $this->requests(), 'Six workers, one call to the provider');
        self::assertSame(['tok-1'], array_values(array_unique($tokens)));
        self::assertNull($this->globals->get(SmtpOAuthStore::REFRESH_LEASE_KEY), 'The lease is given back');
    }

    public function testTheProductionHttpClientRefusesLoopbackAndPlainHttp(): void
    {
        // Why the tests need a stand-in, and proof the real client has no way here.
        $this->expectException(\RuntimeException::class);
        (new HttpFetcher(1))->postForm($this->tokenBase . '/google/token', ['grant_type' => 'refresh_token']);
    }

    private function send(string $subject): void
    {
        MailerFactory::fromSettings($this->settings, $this->globals, $this->secrets, new NullLogger(), $this->http)
            ->send('friend@example-bakery.test', $subject, 'Hello');
    }

    private function recordedState(): ?SmtpOAuthState
    {
        return (new SmtpOAuthStore($this->globals, $this->secrets))->status()['state'] ?? null;
    }

    /** @param array<string, mixed> $scenario */
    private function scenario(array $scenario): void
    {
        file_put_contents($this->dir . '/scenario.json', (string) json_encode($scenario));
    }

    /** @param list<string> $tokens */
    private function acceptTokens(array $tokens): void
    {
        file_put_contents($this->dir . '/accepted-tokens', implode("\n", $tokens) . "\n");
    }

    /** @return list<string> */
    private function lines(string $file): array
    {
        return file($this->dir . '/' . $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }

    /** @return list<array{path: string, form: array<string, string>}> */
    private function requests(): array
    {
        /** @var list<array{path: string, form: array<string, string>}> $requests */
        $requests = array_map(
            static fn(string $line): mixed => json_decode($line, true),
            $this->lines('requests.log'),
        );

        return $requests;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private function spawn(array $command, array $env = []): void
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env === [] ? null : array_merge(getenv(), $env),
        );
        self::assertIsResource($process);
        $this->processes[] = $process;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function waitForPort(int $port): void
    {
        for ($i = 0; $i < 100; $i++) {
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.1);
            if ($probe !== false) {
                fclose($probe);
                return;
            }
            usleep(50_000);
        }
        self::fail('A fake server did not come up on port ' . $port);
    }
}
