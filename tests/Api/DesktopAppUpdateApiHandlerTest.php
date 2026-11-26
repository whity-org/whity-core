<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Api\DesktopAppUpdateApiHandler;
use Whity\Core\Request;

/**
 * The desktop app's update check decides on the server, per platform and
 * channel (WC-app-self-update; whity-desktop ADR 0001, decision 6, WHIT-1431):
 * a stable device sees stable releases only, a beta device sees both, a
 * current device gets 204, and a request the server does not understand gets
 * 204 too, never "update".
 */
final class DesktopAppUpdateApiHandlerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE desktop_app_releases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                version TEXT NOT NULL, target TEXT NOT NULL, channel TEXT NOT NULL DEFAULT \'stable\',
                url TEXT NOT NULL, signature TEXT NOT NULL, notes TEXT,
                pub_date TEXT NOT NULL, released_at TEXT NOT NULL,
                UNIQUE (version, target)
            )'
        );
        $this->register('0.2.0', 'stable', '2026-11-20 10:00:00');
        $this->register('0.3.0-beta.1', 'beta', '2026-11-25 10:00:00');
    }

    private function register(string $version, string $channel, string $releasedAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO desktop_app_releases (version, target, channel, url, signature, notes, pub_date, released_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$version, 'windows-x86_64', $channel, "https://example.test/{$version}.msi", "sig-{$version}", null, $releasedAt, $releasedAt]);
    }

    /** @return array{int, array<string, mixed>|null} */
    private function ask(string $query): array
    {
        $response = (new DesktopAppUpdateApiHandler($this->pdo))->latest(new Request('GET', '/api/desktop-app-updates/latest?' . $query));
        $body = $response->getBody();
        /** @var array<string, mixed>|null $decoded */
        $decoded = $body === '' ? null : json_decode($body, true);

        return [$response->getStatusCode(), $decoded];
    }

    public function testAStableDeviceIsOfferedTheStableReleaseOnly(): void
    {
        [$status, $body] = $this->ask('target=windows-x86_64&current_version=0.1.0');
        self::assertSame(200, $status);
        self::assertSame('0.2.0', $body['version'] ?? null);
        self::assertSame('sig-0.2.0', $body['signature'] ?? null);

        [$status, $body] = $this->ask('target=windows-x86_64&current_version=0.1.0&channel=stable');
        self::assertSame(200, $status);
        self::assertSame('0.2.0', $body['version'] ?? null);
    }

    public function testABetaDeviceIsOfferedTheNewestOfBothChannels(): void
    {
        [$status, $body] = $this->ask('target=windows-x86_64&current_version=0.2.0&channel=beta');
        self::assertSame(200, $status);
        self::assertSame('0.3.0-beta.1', $body['version'] ?? null);

        $this->register('0.3.0', 'stable', '2026-11-26 10:00:00');
        [$status, $body] = $this->ask('target=windows-x86_64&current_version=0.2.0&channel=beta');
        self::assertSame(200, $status);
        self::assertSame('0.3.0', $body['version'] ?? null, 'a newer stable release reaches beta devices too');
    }

    public function testACurrentDeviceAndARequestNotUnderstoodGetNothing(): void
    {
        self::assertSame(204, $this->ask('target=windows-x86_64&current_version=0.2.0')[0], 'current on stable');
        self::assertSame(204, $this->ask('target=windows-x86_64&current_version=0.9.0&channel=beta')[0], 'ahead');
        self::assertSame(204, $this->ask('target=windows-x86_64&current_version=0.1.0&channel=nightly')[0], 'unknown channel');
        self::assertSame(204, $this->ask('target=linux-x86_64&current_version=0.1.0')[0], 'no release for the target');
        self::assertSame(204, $this->ask('target=windows-x86_64&current_version=latest')[0], 'not a version');
    }
}
