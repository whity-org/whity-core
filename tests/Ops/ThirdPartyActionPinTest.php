<?php

declare(strict_types=1);

namespace Tests\Ops;

use PHPUnit\Framework\TestCase;

/**
 * Every third-party GitHub Action is pinned to a full commit SHA (READINESS
 * CO-27, ASVS 14.2.4). A tag or branch can be moved by whoever controls that
 * repository, and the next run would execute their code with our secrets.
 * GitHub's own actions (`actions/*`, `github/*`) and local ones (`./…`) are
 * left to Dependabot's tags; anything else must name a SHA, with the tag in a
 * comment for Dependabot to keep in step.
 */
final class ThirdPartyActionPinTest extends TestCase
{
    public function testEveryThirdPartyActionIsPinnedToACommit(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/.github/workflows/*.yml') ?: [],
            glob($root . '/.github/actions/*/action.yml') ?: [],
        );
        self::assertNotEmpty($files, 'no workflow files found; the test is looking in the wrong place');

        $thirdParty = 0;
        $unpinned = [];
        foreach ($files as $file) {
            foreach (file($file) ?: [] as $number => $line) {
                if (preg_match('/^\s*-?\s*uses:\s*([^\s#]+)/', $line, $m) !== 1) {
                    continue;
                }
                $ref = $m[1];
                if (str_starts_with($ref, './') || str_starts_with($ref, 'actions/') || str_starts_with($ref, 'github/')
                    || str_starts_with($ref, 'whity-org/')) {
                    continue;
                }
                $thirdParty++;
                if (preg_match('/@[0-9a-f]{40}$/', $ref) !== 1) {
                    $unpinned[] = basename($file) . ':' . ($number + 1) . ' ' . $ref;
                }
            }
        }

        self::assertGreaterThan(0, $thirdParty, 'no third-party actions found; the pattern stopped matching');
        self::assertSame([], $unpinned, "Pin these to a commit SHA, with the tag in a comment:\n" . implode("\n", $unpinned));
    }
}
