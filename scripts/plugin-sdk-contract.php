<?php

declare(strict_types=1);

/**
 * Regenerate packages/plugin-sdk/src/contract.generated.ts from the PHP SDK
 * (SDK 1.165, decision D8).
 *
 *   php scripts/plugin-sdk-contract.php           write the file
 *   php scripts/plugin-sdk-contract.php --check   exit 1 when it is stale
 *
 * Run it whenever the block contract or Sdk::VERSION changes.
 * tests/Sdk/PluginSdkPackageTest.php runs the same check.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/lib/plugin-sdk-contract.php';

$path = $root . '/' . WHITY_PLUGIN_SDK_CONTRACT_FILE;
$fresh = whity_plugin_sdk_contract_render();

if (in_array('--check', array_slice($argv, 1), true)) {
    $current = is_file($path) ? str_replace("\r\n", "\n", (string) file_get_contents($path)) : '';
    if ($current !== $fresh) {
        fwrite(STDERR, WHITY_PLUGIN_SDK_CONTRACT_FILE . " is stale. Run: php scripts/plugin-sdk-contract.php\n");
        exit(1);
    }
    echo WHITY_PLUGIN_SDK_CONTRACT_FILE . " matches the PHP SDK.\n";
    exit(0);
}

file_put_contents($path, $fresh);
echo 'Wrote ' . WHITY_PLUGIN_SDK_CONTRACT_FILE . ".\n";
