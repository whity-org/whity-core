<?php

declare(strict_types=1);

/**
 * Regenerate the event tables in docs/wiki/HOOK_SYSTEM.md from
 * Whity\Sdk\Hooks\EventCatalogue (SDK 1.164).
 *
 *   php scripts/hook-catalogue.php           write the page
 *   php scripts/hook-catalogue.php --check   exit 1 when the page is stale
 *
 * tests/Sdk/EventCatalogueTest.php runs the same check, so a stale page fails
 * CI with the rest of the SDK tests.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/lib/hook-catalogue.php';

$path = $root . '/docs/wiki/HOOK_SYSTEM.md';
$page = (string) file_get_contents($path);
$fresh = whity_hook_catalogue_apply($page);

if (in_array('--check', array_slice($argv, 1), true)) {
    if ($fresh !== str_replace("\r\n", "\n", $page)) {
        fwrite(STDERR, "docs/wiki/HOOK_SYSTEM.md is stale. Run: php scripts/hook-catalogue.php\n");
        exit(1);
    }
    echo 'HOOK_SYSTEM.md matches the event catalogue (' . count(\Whity\Sdk\Hooks\EventCatalogue::names()) . " events).\n";
    exit(0);
}

file_put_contents($path, $fresh);
echo 'Wrote docs/wiki/HOOK_SYSTEM.md (' . count(\Whity\Sdk\Hooks\EventCatalogue::names()) . " events).\n";
