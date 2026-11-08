<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Document\Render;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Render\RenderReadiness;
use Whity\Core\Document\Render\RenderServiceClient;

/**
 * The questions the print paths ask before they print (WHIT-1001), against a
 * scripted transport: configured, reachable, the same release, and accepting
 * the shared secret. Each failure must come back as its own state with a fix,
 * because "rendering is unavailable" was the whole complaint.
 */
final class RenderServiceClientReadinessTest extends TestCase
{
    private const SECRET = 'abcdefghijklmnopqrstuvwxyz0123456789';
    private const APP = '0.2.27';

    /** @var list<array{0: string, 1: array<string, string>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        RenderServiceClient::resetReadinessCache();
        $this->requests = [];
    }

    protected function tearDown(): void
    {
        RenderServiceClient::resetReadinessCache();
    }

    /**
     * @param array<string, array{0: int, 1: string}|null> $answers by path
     */
    private function client(array $answers, string $url = 'http://render:8130', string $secret = self::SECRET): RenderServiceClient
    {
        $get = function (string $requested, array $headers, int $timeout) use ($answers): ?array {
            $this->requests[] = [$requested, $headers];
            $path = (string) parse_url($requested, PHP_URL_PATH);

            return $answers[$path] ?? null;
        };

        return new RenderServiceClient($url, $secret, 30, 1024, \Closure::fromCallable($get), self::APP);
    }

    /** @return array{0: int, 1: string} */
    private static function health(?string $version): array
    {
        return [200, (string) json_encode(['status' => 'ok', 'core_version' => $version])];
    }

    public function testReadyWhenReachableMatchedAndTheSecretIsAccepted(): void
    {
        $readiness = $this->client(['/health' => self::health(self::APP), '/auth-check' => [204, '']])->readiness();

        self::assertTrue($readiness->isReady());
        self::assertSame(self::APP, $readiness->renderVersion);
        self::assertSame(self::SECRET, $this->requests[1][1]['X-Render-Secret']);
    }

    public function testNoUrlIsNotConfiguredAndAsksNothing(): void
    {
        $readiness = $this->client([], '')->readiness();

        self::assertSame(RenderReadiness::NOT_CONFIGURED, $readiness->state);
        self::assertSame([], $this->requests);
    }

    public function testAShortSecretIsMissingAndAsksNothing(): void
    {
        $readiness = $this->client([], 'http://render:8130', 'short')->readiness();

        self::assertSame(RenderReadiness::SECRET_MISSING, $readiness->state);
        self::assertStringContainsString('RENDER_SHARED_SECRET', $readiness->fix);
        self::assertSame([], $this->requests);
    }

    public function testNothingAnsweringIsUnreachable(): void
    {
        $readiness = $this->client([])->readiness();

        self::assertSame(RenderReadiness::UNREACHABLE, $readiness->state);
        self::assertStringContainsString('COMPOSE_PROFILES', $readiness->fix);
    }

    public function testAnotherReleaseIsAVersionMismatchNamingTheTagToRun(): void
    {
        $readiness = $this->client(['/health' => self::health('0.2.26'), '/auth-check' => [204, '']])->readiness();

        self::assertSame(RenderReadiness::VERSION_MISMATCH, $readiness->state);
        self::assertSame('0.2.26', $readiness->renderVersion);
        self::assertStringContainsString('render:v0.2.27', $readiness->fix);
        // The secret is never sent to a renderer that is not this release.
        self::assertCount(1, $this->requests);
    }

    public function testARendererThatCannotSayItsVersionIsAMismatch(): void
    {
        $readiness = $this->client(['/health' => [200, '{"status":"ok"}']])->readiness();

        self::assertSame(RenderReadiness::VERSION_MISMATCH, $readiness->state);
        self::assertNull($readiness->renderVersion);
    }

    public function testARefusedSecretIsASecretMismatch(): void
    {
        $readiness = $this->client(['/health' => self::health(self::APP), '/auth-check' => [401, '{"error":"x"}']])->readiness();

        self::assertSame(RenderReadiness::SECRET_MISMATCH, $readiness->state);
        self::assertStringNotContainsString(self::SECRET, $readiness->fix . $readiness->message);
    }

    public function testTheAnswerIsCachedUntilAskedAfresh(): void
    {
        $client = $this->client(['/health' => self::health(self::APP), '/auth-check' => [204, '']]);
        $client->readiness();
        $client->readiness();
        self::assertCount(2, $this->requests);

        $client->readiness(true);
        self::assertCount(4, $this->requests);
    }
}
