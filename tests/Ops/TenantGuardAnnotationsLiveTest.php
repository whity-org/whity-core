<?php

declare(strict_types=1);

namespace Tests\Ops;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Whity\Core\Tenant\TenantPredicateGuard;

/**
 * Every `@tenant-guard-ignore:` annotation must still suppress something (#2135).
 *
 * An annotation is a reviewed exception to the platform's isolation rule, and
 * the scanner only checks that one is PRESENT. Once the statement under it
 * stops needing it (the table became a sanctioned global one, a tenant
 * predicate was added, the query moved), the annotation keeps reading as a
 * reviewed hole that nothing is guarding any more, and its reason quietly
 * stops being about anything. So each one is removed in turn here, and the
 * file is scanned again: if no new violation appears, the annotation is dead
 * and has to go.
 *
 * Covers the roots the CI guard scans with core's own table registry: src/
 * and database/ (migrations excluded, as in the guard). plugins/ is left out on
 * purpose: a plugin's annotations are read against the plugin's own tables by
 * scripts/ci-plugin-tenant-conformance.php, not by core's registry.
 */
final class TenantGuardAnnotationsLiveTest extends TestCase
{
    private const ROOTS = ['src', 'database'];

    public function testEveryAnnotationStillSuppressesAViolation(): void
    {
        $guard = new TenantPredicateGuard();
        $tag = TenantPredicateGuard::IGNORE_TAG;
        $repo = dirname(__DIR__, 2);
        // The tag directly after a comment marker. Prose that merely mentions
        // the tag (in backticks, mid-sentence) is not an annotation.
        $annotation = '#^\s*(//|\*|\#)\s*' . preg_quote($tag, '#') . '#';

        $dead = [];
        $checked = 0;
        foreach (self::ROOTS as $root) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($repo . '/' . $root, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                $path = str_replace('\\', '/', (string) $file);
                if (!str_ends_with($path, '.php') || preg_match('#(^|/)[Mm]igrations/#', $path) === 1) {
                    continue;
                }
                $source = (string) file_get_contents($path);
                if (!str_contains($source, $tag)) {
                    continue;
                }

                $before = count($guard->scanSource($source, $path));
                $lines = explode("\n", $source);
                foreach ($lines as $i => $line) {
                    if (preg_match($annotation, $line) !== 1) {
                        continue;
                    }
                    $checked++;
                    $without = $lines;
                    $without[$i] = str_replace($tag, 'removed-for-test:', $line);
                    if (count($guard->scanSource(implode("\n", $without), $path)) <= $before) {
                        $dead[] = substr($path, strlen(str_replace('\\', '/', $repo)) + 1) . ':' . ($i + 1);
                    }
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'No annotations were found, so the scan itself is broken.');
        self::assertSame(
            [],
            $dead,
            "These @tenant-guard-ignore annotations suppress nothing; delete them:\n" . implode("\n", $dead)
        );
    }
}
