<?php

declare(strict_types=1);

namespace Tests\Sdk;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\SdkException;

/**
 * The SDK's surface agrees with itself and its docs (SDK 1.123, WHIT-1230).
 *
 * Each SDK minor adds a contract in one place; these checks catch the places it
 * has to reach as well: the common error type, the reference page, the hook
 * catalogue, and core actually firing what the SDK publishes.
 */
final class SdkSurfaceContractTest extends TestCase
{
    /**
     * Published events core does not fire yet. Each entry names why, so a
     * reader knows whether to wait for it or not rely on it.
     */
    private const RESERVED_EVENTS = [
        // None today. `submission.reviewed` was here until WHIT-1243 fired it
        // again from the route step where the office records its answers.
    ];

    public function testEveryExceptionTheSdkDefinesIsAnSdkException(): void
    {
        $missing = [];
        foreach (self::sdkTypes() as $type) {
            $reflection = new ReflectionClass($type);
            if ($reflection->isSubclassOf(\Throwable::class) && !$reflection->isInterface() && !$reflection->implementsInterface(SdkException::class)) {
                $missing[] = $type;
            }
        }

        self::assertSame([], $missing, 'Every exception in sdk/src implements Whity\Sdk\SdkException, so a plugin can catch them all.');
    }

    public function testEverySdkInterfaceIsNamedOnAWikiPageOrTheReadme(): void
    {
        $docs = (string) file_get_contents(self::root() . '/sdk/README.md');
        foreach ((array) glob(self::root() . '/docs/wiki/*.md') as $page) {
            $docs .= (string) file_get_contents((string) $page);
        }

        $missing = [];
        foreach (self::sdkTypes() as $type) {
            if (!interface_exists($type)) {
                continue;
            }
            $short = substr($type, (int) strrpos($type, '\\') + 1);
            if (preg_match('/(?<![A-Za-z])' . preg_quote($short, '/') . '(?![A-Za-z])/', $docs) !== 1) {
                $missing[] = $type;
            }
        }

        self::assertSame([], $missing, 'Name each SDK interface in docs/wiki/Plugin-SDK-Reference.md (or a page of its own).');
    }

    public function testEveryPublishedEventHasARowInTheHookCatalogue(): void
    {
        $catalogue = (string) file_get_contents(self::root() . '/docs/wiki/HOOK_SYSTEM.md');

        $missing = [];
        foreach (self::events() as $name => $value) {
            if (!str_contains($catalogue, "| `{$value}` | `{$name}` |")) {
                $missing[] = $value;
            }
        }

        self::assertSame([], $missing, 'Add a row for each event to "Events fired by the core" in docs/wiki/HOOK_SYSTEM.md.');
    }

    /**
     * The other direction from HookEventCatalogueTest: a published event that
     * nothing fires is a promise plugins wait on forever.
     */
    public function testEveryPublishedEventIsFiredOrReserved(): void
    {
        $source = '';
        foreach (['src', 'public'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $source .= (string) file_get_contents($file->getPathname());
                }
            }
        }

        $events = self::events();
        $unfired = [];
        foreach ($events as $name => $value) {
            // An `.async` twin is fired by dispatchAsync($base . '.async'), so
            // its base being fired counts.
            $base = str_ends_with($name, '_ASYNC') ? substr($name, 0, -6) : $name;
            $baseValue = $events[$base] ?? $value;
            $named = str_contains($source, "Events::{$name}") || str_contains($source, "Events::{$base}")
                || str_contains($source, "'{$value}'") || str_contains($source, "'{$baseValue}'");
            if (!$named) {
                $unfired[] = $name;
            }
        }

        self::assertSame(array_keys(self::RESERVED_EVENTS), $unfired, 'An event is fired in src/ or public/, or listed in RESERVED_EVENTS with why. Remove an entry once core fires it.');
    }

    /** @return array<string, string> constant name => event */
    private static function events(): array
    {
        return array_filter((new ReflectionClass(Events::class))->getConstants(), 'is_string');
    }

    /** @return list<class-string> */
    private static function sdkTypes(): array
    {
        $base = self::root() . '/sdk/src/';
        $types = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $base)), -4);
            $type = 'Whity\\Sdk\\' . str_replace('/', '\\', $relative);
            if (class_exists($type) || interface_exists($type) || trait_exists($type)) {
                $types[] = $type;
            }
        }
        sort($types);

        return $types;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
