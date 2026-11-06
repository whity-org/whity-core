<?php

declare(strict_types=1);

/**
 * One worker process asking for an access token at an agreed moment (WHIT-901), so
 * a test can race several of them on real PostgreSQL. Prints the token it got.
 *
 * Usage: php concurrent-token-worker.php <dsn> <user> <password> <key> <tokenBaseUrl> <startAtMicrotime>
 */

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/LoopbackTokenHttp.php';

use Psr\Log\NullLogger;
use Tests\Support\FakeOAuth\LoopbackTokenHttp;
use Whity\Core\Mail\OAuth\SmtpOAuthStore;
use Whity\Core\Mail\OAuth\SmtpOAuthTokenManager;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

if (!isset($argv)) {
    fwrite(STDERR, "Run this from the command line.
");
    exit(2);
}

[$_, $dsn, $user, $password, $key, $tokenBase, $startAt] = $argv;

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$globals = new GlobalSettingsRepository($pdo);

$tokens = new SmtpOAuthTokenManager(
    new SettingsService($globals, new TenantSettingsRepository($pdo)),
    new SmtpOAuthStore($globals, new EncryptedSecretStore(['v1' => $key], 'v1')),
    new LoopbackTokenHttp($tokenBase),
    new NullLogger(),
);

$wait = (float) $startAt - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

echo $tokens->accessToken();
