<?php

declare(strict_types=1);

namespace Tests\Ops;

use PHPUnit\Framework\TestCase;

/**
 * The Dockerfile's base pin, and the two ini settings that are there for
 * security rather than for tuning.
 *
 * WHY THIS IS A TEST AND NOT A COMMENT. `.github/dependabot.yml` has watched
 * `package-ecosystem: docker` in this directory since it was written, and on
 * 2026-10-24 it had still never opened a single PR against the base image —
 * because the pin was the floating `dunglas/frankenphp:1-php8.4`. There is
 * nothing in a floating tag for Dependabot to bump: the string is already
 * correct for every patch release, so the watcher runs, finds the tag current,
 * and reports nothing. The configuration looked complete and did nothing, which
 * is the same shape as a guard that cannot fail.
 *
 * What it cost: PHP 8.4.27 shipped the fixes for CVE-2026-109618 and
 * CVE-2026-109619 on 2026-10-21 — both CVSS 9.2, unauthenticated RCE, reported
 * exploited in the wild — and three days later a deployment running this image
 * was still serving PHP 8.4.26, with no PR open and no alert raised. The image
 * is only rebuilt by a release, and releases are cut for application changes,
 * so nothing in the pipeline was ever going to notice.
 *
 * An exact pin makes the version a reviewable diff and gives Dependabot
 * something to move. These assertions exist so that reverting to a floating tag
 * — which looks tidier, and silently switches the watcher back off — fails here
 * instead of being found by the next advisory.
 */
final class BaseImagePinTest extends TestCase
{
    private string $dockerfile;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2).'/Dockerfile';

        self::assertFileExists($path, 'the Dockerfile this test is about has moved');

        $this->dockerfile = (string) file_get_contents($path);
    }

    /**
     * The pin carries a full MAJOR.MINOR.PATCH.
     *
     * Deliberately asserts the SHAPE and not the value. A test naming 8.4.27
     * would have to be edited by every security bump, which makes it a chore
     * attached to the most time-critical change in the repository — and a chore
     * is the thing people route around. This asks only the question the floating
     * tag got wrong.
     */
    /**
     * READINESS CO-24: the web, render and local-CI images are watched by
     * Dependabot too (.github/dependabot.yml), which only works when their
     * bases name an exact version. A floating tag (`node:22-bookworm-slim`,
     * `php:8.4-cli`) has nothing for it to bump.
     */
    public function test_every_other_dockerfile_pins_an_exact_base_version(): void
    {
        $root = dirname(__DIR__, 2);
        $checked = 0;
        foreach (['web/Dockerfile', 'render-service/Dockerfile', 'docker/ci-local/Dockerfile'] as $file) {
            $text = (string) file_get_contents($root . '/' . $file);
            $found = preg_match_all('/^FROM\s+(\S+)/m', $text, $m);
            self::assertGreaterThan(0, $found, "no FROM line in {$file}");
            foreach ($m[1] as $image) {
                $checked++;
                self::assertMatchesRegularExpression(
                    '/:\d+\.\d+\.\d+/',
                    $image,
                    "{$file}: the base image {$image} does not name an exact version, so Dependabot can't bump it",
                );
            }
        }
        self::assertGreaterThanOrEqual(6, $checked, 'the scan found fewer FROM lines than these Dockerfiles have');
    }

    public function test_the_base_image_pins_an_exact_php_patch_version(): void
    {
        // `\s*$` rather than `$`, because this repository is checked out with
        // CRLF on Windows and LF in CI. A line-anchored pattern without it
        // passes on the runner and fails on a maintainer's machine, which
        // teaches people the test is flaky rather than that the pin is wrong.
        $matched = preg_match(
            '/^FROM\s+dunglas\/frankenphp:(\S+)\s+AS\s+base\s*$/m',
            $this->dockerfile,
            $m,
        );

        self::assertSame(1, $matched, 'no FROM dunglas/frankenphp:… AS base line in the Dockerfile');

        // The assertSame above guarantees the match, so group 1 is present.
        $tag = $m[1];

        self::assertMatchesRegularExpression(
            '/php\d+\.\d+\.\d+/',
            $tag,
            'the base image tag "'.$tag.'" does not name an exact PHP patch version. '
            .'Dependabot cannot bump a floating tag, so this turns the docker watcher into a no-op '
            .'and the next PHP security release will land here unnoticed, exactly as 8.4.27 did.',
        );
    }

    /**
     * PHP puts its exact patch version in X-Powered-By on every response unless
     * told not to, including responses the application never sees. During the
     * 2026-10 PHP advisories a live deployment was found answering
     * `x-powered-by: PHP/8.4.<patch>` to unauthenticated requests, which told
     * anyone asking both which RCE to try and how far behind the host was.
     */
    public function test_php_does_not_announce_its_version_on_every_response(): void
    {
        self::assertStringContainsString(
            "echo 'expose_php = Off';",
            $this->dockerfile,
            'expose_php is not disabled, so every response advertises the exact PHP patch level',
        );
    }

    /**
     * The image doesn't copy php.ini-production, so without these PHP's
     * built-in display_errors=1 prints warnings, with file paths, into
     * response bodies (READINESS CE-20).
     */
    public function test_errors_are_logged_not_shown(): void
    {
        foreach (["echo 'display_errors = Off';", "echo 'display_startup_errors = Off';", "echo 'log_errors = On';"] as $line) {
            self::assertStringContainsString($line, $this->dockerfile, 'missing from the security ini: ' . $line);
        }
    }

    /**
     * The mitigation PHP published for CVE-2026-115560, which is still
     * unpatched and affects 8.5.12 as well — so it is not something a later
     * base-image bump quietly resolves.
     *
     * It has to be an ini setting. PHP runs the upload-progress handler from
     * the SAPI while parsing a multipart body and starts a session itself to do
     * it, so the path is reachable by an unauthenticated POST on a platform
     * that never calls session_start() anywhere — which is this one.
     */
    public function test_session_upload_progress_is_disabled(): void
    {
        self::assertStringContainsString(
            "echo 'session.upload_progress.enabled = 0';",
            $this->dockerfile,
            'session.upload_progress is still enabled; it is the documented mitigation for '
            .'CVE-2026-115560 and nothing in this platform reads upload progress',
        );
    }
}
