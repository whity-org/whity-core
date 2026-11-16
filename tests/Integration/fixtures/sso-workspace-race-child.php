<?php

declare(strict_types=1);

/**
 * One of the two racing "Create your workspace" requests in
 * SsoWorkspaceSetupRealEngineTest::testTwoRacingRequestsMakeOneWorkspace.
 *
 * Its own process and its own connection, so the profile row lock in
 * FederatedWorkspaceProvisioner is really contended. Prints
 * {"tenant_id": int|null} and exits 0, or prints the error and exits 1.
 *
 * Usage: php sso-workspace-race-child.php <dsn> <user> <password> <schema> <profile_id> <provider_id>
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Whity\Core\Identity\FederatedProviderContext;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\FederatedWorkspaceProvisioner;

[, $dsn, $user, $password, $schema, $profileId, $providerId] = $argv;

try {
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET search_path TO "' . str_replace('"', '""', $schema) . '"');
    $settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));

    $tenantId = (new FederatedWorkspaceProvisioner($pdo, $settings))->provisionFor(
        (int) $profileId,
        'amro@example.test',
        new FederatedProviderContext((int) $providerId, 'whity_id', 0),
        'Example Bakery',
    );

    echo json_encode(['tenant_id' => $tenantId]), "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
