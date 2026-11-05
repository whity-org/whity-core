<?php

declare(strict_types=1);

namespace Tests\Integration\Mail;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tests\Support\FakeOAuth\ScriptedTokenHttp;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Mail\MailerFactory;
use Whity\Core\Mail\NullMailer;
use Whity\Core\Mail\OAuth2SmtpMailer;
use Whity\Core\Mail\OAuth\OAuthTokenException;
use Whity\Core\Mail\OAuth\SmtpOAuthProvider;
use Whity\Core\Mail\OAuth\SmtpOAuthState;
use Whity\Core\Mail\OAuth\SmtpOAuthStore;
use Whity\Core\Mail\OAuth\SmtpOAuthTokenManager;
use Whity\Core\Mail\SmtpMailer;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\SettingsValidationException;
use Whity\Core\Settings\TenantSettingsRepository;

require_once __DIR__ . '/../../Support/FakeOAuth/ScriptedTokenHttp.php';

/**
 * The token lifecycle's timing and failure rules on a real engine, with a scripted
 * provider and a clock the test moves (WHIT-901).
 */
final class SmtpOAuthTokenManagerRealEngineTest extends TestCase
{
    private const KEY = 'unit-test-encryption-key-please-ignore-0123456789';
    private const SECRET = 'client-secret-MUST-NOT-LEAK';
    private const REFRESH = 'refresh-token-MUST-NOT-LEAK';

    private GlobalSettingsRepository $globals;
    private SettingsService $settings;
    private SmtpOAuthStore $store;
    private EncryptedSecretStore $secrets;
    private ScriptedTokenHttp $http;
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $logged;
    private AbstractLogger $logger;
    private int $now = 2_000_000_000;

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $this->globals = new GlobalSettingsRepository($pdo);
        $this->settings = new SettingsService($this->globals, new TenantSettingsRepository($pdo));
        $this->secrets = new EncryptedSecretStore(['v1' => self::KEY], 'v1');
        $this->store = new SmtpOAuthStore($this->globals, $this->secrets);

        $this->http = new ScriptedTokenHttp();

        $this->logged = new \ArrayObject();
        $logged = $this->logged;
        $this->logger = new class ($logged) extends AbstractLogger {
            /** @param \ArrayObject<int, string> $logged */
            public function __construct(private readonly \ArrayObject $logged)
            {
            }

            /** @param mixed $level */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logged->append((string) $message . ' ' . json_encode($context));
            }
        };

        $this->settings->setGlobal(SettingsRegistry::MAIL_OAUTH_PROVIDER, 'google');
        $this->settings->setGlobal(SettingsRegistry::MAIL_OAUTH_CLIENT_ID, 'client-id-1');
        $this->store->setClientSecret(self::SECRET);
        $this->store->setRefreshToken(self::REFRESH);
    }

    private function manager(): SmtpOAuthTokenManager
    {
        return new SmtpOAuthTokenManager(
            $this->settings,
            $this->store,
            $this->http,
            $this->logger,
            fn(): int => $this->now,
            function (int $microseconds): void {
                $this->now += 1;
            },
        );
    }

    /** @return array<string, mixed> */
    private static function token(string $token, int $expiresIn = 3600): array
    {
        return ['access_token' => $token, 'expires_in' => $expiresIn];
    }

    public function testRefreshesOnlyOnceTheTokenIsInsideTheRefreshWindow(): void
    {
        $this->http->responses = [self::token('tok-1'), self::token('tok-2')];
        $tokens = $this->manager();

        self::assertSame('tok-1', $tokens->accessToken());

        $this->now += 3600 - SmtpOAuthTokenManager::REFRESH_AHEAD_SECONDS - 1;
        self::assertSame('tok-1', $tokens->accessToken());
        self::assertSame(1, $this->http->calls);

        $this->now += 2;
        self::assertSame('tok-2', $tokens->accessToken());
        self::assertSame(2, $this->http->calls);
    }

    public function testAFailedEarlyRefreshKeepsUsingTheTokenThatStillWorks(): void
    {
        $this->http->responses = [self::token('tok-1'), null];
        $tokens = $this->manager();
        $tokens->accessToken();

        $this->now += 3600 - 60;
        self::assertSame('tok-1', $tokens->accessToken());
        self::assertSame(SmtpOAuthState::TokenServiceUnavailable, $this->store->status()['state'] ?? null);
    }

    public function testAnExpiredTokenWithAFailedRefreshFails(): void
    {
        $this->http->responses = [self::token('tok-1'), null];
        $tokens = $this->manager();
        $tokens->accessToken();

        $this->now += 3600;
        $this->expectException(OAuthTokenException::class);
        $tokens->accessToken();
    }

    public function testATemporaryFailurePausesBrieflyThenTriesAgain(): void
    {
        $this->http->responses = [null, self::token('tok-1')];
        $tokens = $this->manager();

        $this->assertThrowsState(SmtpOAuthState::TokenServiceUnavailable, $tokens);
        $this->assertThrowsState(SmtpOAuthState::TokenServiceUnavailable, $tokens);
        self::assertSame(1, $this->http->calls);

        $this->now += SmtpOAuthTokenManager::TEMPORARY_RETRY_SECONDS;
        self::assertSame('tok-1', $tokens->accessToken());
        self::assertNull($this->store->status(), 'A recovery clears the old failure');
    }

    public function testARevokedTokenIsTriedAgainOnlyAfterTheLongPause(): void
    {
        $this->http->responses = [['error' => 'invalid_grant'], ['error' => 'invalid_grant']];
        $tokens = $this->manager();

        $this->assertThrowsState(SmtpOAuthState::NeedsReconnect, $tokens);
        $this->now += SmtpOAuthTokenManager::PERSON_RETRY_SECONDS - 1;
        $this->assertThrowsState(SmtpOAuthState::NeedsReconnect, $tokens);
        self::assertSame(1, $this->http->calls);

        $this->now += 1;
        $this->assertThrowsState(SmtpOAuthState::NeedsReconnect, $tokens);
        self::assertSame(2, $this->http->calls);
    }

    public function testSavingANewRefreshTokenEndsTheFailureAtOnce(): void
    {
        $this->http->responses = [['error' => 'invalid_grant'], self::token('tok-2')];
        $tokens = $this->manager();
        $this->assertThrowsState(SmtpOAuthState::NeedsReconnect, $tokens);

        $this->store->setRefreshToken('a-new-refresh-token');

        self::assertSame('tok-2', $tokens->accessToken());
    }

    public function testRejectedClientCredentialsGetTheirOwnStatus(): void
    {
        $this->http->responses = [['error' => 'invalid_client', 'error_description' => 'PROVIDER-WORDS-MUST-NOT-LEAK']];

        $this->assertThrowsState(SmtpOAuthState::ClientRejected, $this->manager());
    }

    public function testChangingAnyCredentialStopsTheCachedTokenApplying(): void
    {
        $this->http->responses = [self::token('tok-1'), self::token('tok-2')];
        $tokens = $this->manager();
        $tokens->accessToken();

        $this->settings->setGlobal(SettingsRegistry::MAIL_OAUTH_CLIENT_ID, 'client-id-2');

        self::assertSame('tok-2', $tokens->accessToken());
    }

    public function testAnAnswerForCredentialsReplacedMidRefreshIsThrownAway(): void
    {
        // A person saves a new token while the provider is answering.
        $this->http = new ScriptedTokenHttp(function (): void {
            $this->store->setRefreshToken('replaced-by-a-person');
        });
        $this->http->responses = [['access_token' => 'tok-old', 'refresh_token' => 'rotated-from-old', 'expires_in' => 3600]];

        $this->assertThrowsState(SmtpOAuthState::TokenServiceUnavailable, $this->manager());

        self::assertSame(
            'replaced-by-a-person',
            $this->secrets->decrypt((string) $this->globals->get(SmtpOAuthStore::REFRESH_TOKEN_KEY)),
        );
        self::assertNull($this->globals->get(SmtpOAuthStore::ACCESS_TOKEN_KEY));
    }

    public function testAWorkerWithAStillUsableTokenDoesNotWaitOnAnotherWorkersRefresh(): void
    {
        $this->http->responses = [self::token('tok-1')];
        $tokens = $this->manager();
        $tokens->accessToken();
        $this->now += 3600 - 60;

        self::assertNotNull($this->store->claimRefreshLease(30, $this->now));

        self::assertSame('tok-1', $tokens->accessToken());
        self::assertSame(1, $this->http->calls);
    }

    public function testAWorkerWithNoTokenGivesUpIfTheHolderNeverFinishes(): void
    {
        // A lease that outlives the wait, as with a holder stuck on the network.
        self::assertNotNull($this->store->claimRefreshLease(600, $this->now));

        $this->assertThrowsState(SmtpOAuthState::TokenServiceUnavailable, $this->manager());
        self::assertSame(0, $this->http->calls);
    }

    public function testALeaseFromADeadWorkerExpires(): void
    {
        self::assertNotNull($this->store->claimRefreshLease(5, $this->now));
        self::assertNull($this->store->claimRefreshLease(5, $this->now + 4));

        $this->http->responses = [self::token('tok-1')];
        $tokens = $this->manager();

        self::assertSame('tok-1', $tokens->accessToken());
        self::assertSame(1, $this->http->calls);
    }

    public function testLeaseIsExclusiveAndOnlyItsHolderCanReleaseIt(): void
    {
        $lease = $this->store->claimRefreshLease(30, $this->now);
        self::assertNotNull($lease);
        self::assertNull($this->store->claimRefreshLease(30, $this->now));

        $this->store->releaseRefreshLease('0000000000:someone-else');
        self::assertNull($this->store->claimRefreshLease(30, $this->now));

        $this->store->releaseRefreshLease($lease);
        self::assertNotNull($this->store->claimRefreshLease(30, $this->now));
    }

    public function testUnreadableCredentialsSaySoInsteadOfCallingTheProvider(): void
    {
        $this->globals->set(SmtpOAuthStore::REFRESH_TOKEN_KEY, 'v1:not-a-real-ciphertext');

        $this->assertThrowsState(SmtpOAuthState::CredentialsUnreadable, $this->manager());
        self::assertSame(0, $this->http->calls);
    }

    public function testNothingSecretOrFromTheProviderReachesTheLog(): void
    {
        $this->http->responses = [['error' => 'invalid_grant', 'error_description' => 'PROVIDER-WORDS-MUST-NOT-LEAK']];
        $this->assertThrowsState(SmtpOAuthState::NeedsReconnect, $this->manager());

        $log = implode("\n", (array) $this->logged);
        self::assertStringContainsString('needs_reconnect', $log);
        foreach (['PROVIDER-WORDS', self::SECRET, self::REFRESH] as $secret) {
            self::assertStringNotContainsString($secret, $log);
        }
    }

    public function testAnExceptionTraceHidesTheSecretsInTheTokenRequest(): void
    {
        $seen = '';
        try {
            (new \Whity\Core\Http\HttpFetcher(1))->postForm('http://127.0.0.1:1/token', ['refresh_token' => self::REFRESH]);
        } catch (\RuntimeException $e) {
            $seen = print_r($e->getTrace(), true);
        }
        self::assertStringNotContainsString(self::REFRESH, $seen);
    }

    public function testDescribeReportsWhatIsStoredButNeverTheValues(): void
    {
        $described = $this->manager()->describe('smtp.gmail.com', 'tls');

        self::assertSame(SmtpOAuthState::NotYetUsed->value, $described['state']);
        self::assertTrue($described['has_client_secret']);
        self::assertTrue($described['has_refresh_token']);
        $json = (string) json_encode($described);
        self::assertStringNotContainsString(self::SECRET, $json);
        self::assertStringNotContainsString(self::REFRESH, $json);
    }

    public function testDescribeFlagsAHostOutsideTheProviderAndAnUnencryptedConnection(): void
    {
        $tokens = $this->manager();

        self::assertSame('smtp_host_not_allowed', $tokens->describe('mail.attacker.example', 'tls')['state']);
        self::assertSame('insecure_connection', $tokens->describe('smtp.gmail.com', 'none')['state']);
        self::assertSame('not_yet_used', $tokens->describe('127.0.0.1', 'none')['state']);
    }

    public function testATokenIsNeverSentToAHostOutsideTheProvider(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_TRANSPORT, 'smtp');
        $this->settings->setGlobal(SettingsRegistry::MAIL_SMTP_HOST, 'mail.attacker.example');
        $this->settings->setGlobal(SettingsRegistry::MAIL_FROM_ADDRESS, 'mail@example-bakery.test');
        $this->settings->setGlobal(SettingsRegistry::MAIL_SMTP_AUTH_MODE, 'oauth2');
        $connected = false;

        $mailer = MailerFactory::fromSettings(
            $this->settings,
            $this->globals,
            $this->secrets,
            $this->logger,
            $this->http,
            static function () use (&$connected): never {
                $connected = true;
                throw new \LogicException('connected');
            },
        );

        try {
            $mailer->send('friend@example-bakery.test', 'S', 'B');
            self::fail('The send should have been refused');
        } catch (OAuthTokenException $e) {
            self::assertSame(SmtpOAuthState::SmtpHostNotAllowed, $e->state);
        }
        self::assertFalse($connected);
        self::assertSame(0, $this->http->calls, 'No token is even minted');
    }

    public function testOAuthModeWithMissingCredentialsFailsLoudlyRatherThanDroppingMail(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_TRANSPORT, 'smtp');
        $this->settings->setGlobal(SettingsRegistry::MAIL_SMTP_HOST, 'smtp.gmail.com');
        $this->settings->setGlobal(SettingsRegistry::MAIL_FROM_ADDRESS, 'mail@example-bakery.test');
        $this->settings->setGlobal(SettingsRegistry::MAIL_SMTP_AUTH_MODE, 'oauth2');
        $this->store->clearRefreshToken();

        $mailer = MailerFactory::fromSettings($this->settings, $this->globals, $this->secrets, $this->logger, $this->http);

        self::assertNotInstanceOf(NullMailer::class, $mailer);
        self::assertInstanceOf(OAuth2SmtpMailer::class, $mailer);
        $this->expectException(OAuthTokenException::class);
        $mailer->send('friend@example-bakery.test', 'S', 'B');
    }

    public function testAnExistingInstallStaysOnItsPasswordWithoutAnyNewSetting(): void
    {
        // Nothing stored for auth_mode, as on every install from before this change.
        self::assertSame('password', $this->settings->getGlobal()[SettingsRegistry::MAIL_SMTP_AUTH_MODE]);
        $this->settings->setGlobal(SettingsRegistry::MAIL_TRANSPORT, 'smtp');
        $this->settings->setGlobal(SettingsRegistry::MAIL_SMTP_HOST, 'mail.example-bakery.test');
        $this->settings->setGlobal(SettingsRegistry::MAIL_FROM_ADDRESS, 'mail@example-bakery.test');

        $mailer = MailerFactory::fromSettings($this->settings, $this->globals, $this->secrets, $this->logger, $this->http);

        self::assertInstanceOf(SmtpMailer::class, $mailer);
    }

    public function testSecretsAreNotSettingsAndCannotBeReadOrWrittenAsOnes(): void
    {
        $this->http->responses = [self::token('tok-1')];
        $this->manager()->accessToken();

        $global = $this->settings->getGlobal();
        foreach ([
            SmtpOAuthStore::CLIENT_SECRET_KEY,
            SmtpOAuthStore::REFRESH_TOKEN_KEY,
            SmtpOAuthStore::ACCESS_TOKEN_KEY,
            SmtpOAuthStore::STATUS_KEY,
            SmtpOAuthStore::REFRESH_LEASE_KEY,
        ] as $key) {
            self::assertArrayNotHasKey($key, $global);
            self::assertFalse(SettingsRegistry::isKnown($key));
        }

        $this->expectException(SettingsValidationException::class);
        $this->settings->setGlobal(SmtpOAuthStore::CLIENT_SECRET_KEY, 'overwrite');
    }

    public function testMailOAuthSettingsAreGlobalOnly(): void
    {
        foreach ([
            SettingsRegistry::MAIL_SMTP_AUTH_MODE,
            SettingsRegistry::MAIL_OAUTH_PROVIDER,
            SettingsRegistry::MAIL_OAUTH_CLIENT_ID,
            SettingsRegistry::MAIL_OAUTH_MICROSOFT_TENANT,
        ] as $key) {
            self::assertTrue(SettingsRegistry::isGlobalOnly($key), $key);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function badSettings(): iterable
    {
        yield 'unknown provider' => [SettingsRegistry::MAIL_OAUTH_PROVIDER, 'attacker'];
        yield 'url as a provider' => [SettingsRegistry::MAIL_OAUTH_PROVIDER, 'https://attacker.example/token'];
        yield 'unknown auth mode' => [SettingsRegistry::MAIL_SMTP_AUTH_MODE, 'plain'];
        yield 'path in a directory' => [SettingsRegistry::MAIL_OAUTH_MICROSOFT_TENANT, '../../attacker'];
        yield 'host in a directory' => [SettingsRegistry::MAIL_OAUTH_MICROSOFT_TENANT, 'attacker.example'];
        yield 'newline after a guid' => [SettingsRegistry::MAIL_OAUTH_MICROSOFT_TENANT, "11111111-2222-3333-4444-555555555555\n"];
    }

    /** @dataProvider badSettings */
    #[\PHPUnit\Framework\Attributes\DataProvider('badSettings')]
    public function testSettingsCannotSteerTheTokenRequestAnywhereElse(string $key, string $value): void
    {
        $this->expectException(SettingsValidationException::class);
        $this->settings->setGlobal($key, $value);
    }

    public function testTheEndpointComesOnlyFromTheFixedList(): void
    {
        self::assertSame('https://oauth2.googleapis.com/token', SmtpOAuthProvider::tokenEndpoint('google', '../evil'));
        self::assertSame(
            'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            SmtpOAuthProvider::tokenEndpoint('microsoft', 'attacker.example/x'),
        );

        $this->expectException(\InvalidArgumentException::class);
        SmtpOAuthProvider::tokenEndpoint('attacker');
    }

    private function assertThrowsState(SmtpOAuthState $expected, SmtpOAuthTokenManager $tokens): void
    {
        try {
            $tokens->accessToken();
            self::fail('Expected ' . $expected->value);
        } catch (OAuthTokenException $e) {
            self::assertSame($expected, $e->state);
        }
    }
}
