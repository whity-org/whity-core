<?php

declare(strict_types=1);

namespace Tests\Sdk;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/lib/plugin-sdk-contract.php';

/**
 * @whity/plugin-sdk (SDK 1.165, decision D8) matches the PHP SDK: its generated
 * contract is what the generator writes now, and its version is the SDK's.
 * The package's own tests (packages/plugin-sdk) check the block types against
 * that contract.
 */
final class PluginSdkPackageTest extends TestCase
{
    public function testTheGeneratedContractIsCurrent(): void
    {
        $path = dirname(__DIR__, 2) . '/' . WHITY_PLUGIN_SDK_CONTRACT_FILE;

        self::assertFileExists($path);
        self::assertSame(
            whity_plugin_sdk_contract_render(),
            str_replace("\r\n", "\n", (string) file_get_contents($path)),
            WHITY_PLUGIN_SDK_CONTRACT_FILE . ' is stale. Run: php scripts/plugin-sdk-contract.php',
        );
    }

    public function testThePackageVersionIsTheSdkVersion(): void
    {
        $package = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/packages/plugin-sdk/package.json'), true, 16, JSON_THROW_ON_ERROR);

        self::assertIsArray($package);
        self::assertSame(\Whity\Sdk\Sdk::VERSION, $package['version'] ?? null, 'Bump packages/plugin-sdk/package.json with the SDK.');
    }

    /** Publishing waits for a release (owner): no publish workflow and no publish settings yet. */
    public function testThePackageIsNotPublishedYet(): void
    {
        $root = dirname(__DIR__, 2);
        $package = json_decode((string) file_get_contents($root . '/packages/plugin-sdk/package.json'), true, 16, JSON_THROW_ON_ERROR);

        self::assertIsArray($package);
        self::assertArrayNotHasKey('publishConfig', $package);
        self::assertFileDoesNotExist($root . '/.github/workflows/publish-plugin-sdk.yml');
    }
}
