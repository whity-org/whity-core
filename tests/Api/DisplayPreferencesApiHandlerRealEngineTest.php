<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecipientProfiles;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DisplayPreferencesApiHandler;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;

/**
 * Real-engine tests for {@see DisplayPreferencesApiHandler}: defaults, changes
 * stored per person, validation with no write, and the 401 fail-closed.
 */
final class DisplayPreferencesApiHandlerRealEngineTest extends TestCase
{
    private const CALLER = 101;
    private const OTHER = 202;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        RecipientProfiles::seed($this->pdo);
    }

    /** @param array<string, mixed>|null $claims */
    private function handler(?array $claims): DisplayPreferencesApiHandler
    {
        $tv = $this->createMock(TokenValidator::class);
        $tv->method('validateAccessToken')->willReturn($claims);

        return new DisplayPreferencesApiHandler($tv, $this->pdo);
    }

    private function authed(): DisplayPreferencesApiHandler
    {
        return $this->handler(['profile_id' => self::CALLER, 'active_tenant_id' => 1]);
    }

    private function put(string $json): Request
    {
        return new Request('PUT', '/api/me/display-preferences', [], $json);
    }

    /** @return array<string, mixed> */
    private function data(\Whity\Core\Response $response): array
    {
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['data']);

        return $body['data'];
    }

    public function testDefaultsAreStandardWithTechnicalDetailsOff(): void
    {
        $response = $this->authed()->get(new Request('GET', '/api/me/display-preferences'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['textSize' => 'standard', 'showTechnicalDetails' => false], $this->data($response));
    }

    public function testAChangeIsStoredAndReachesOnlyThatPerson(): void
    {
        $response = $this->authed()->update($this->put('{"textSize":"larger","showTechnicalDetails":true}'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['textSize' => 'larger', 'showTechnicalDetails' => true], $this->data($response));

        $other = $this->handler(['profile_id' => self::OTHER, 'active_tenant_id' => 1])
            ->get(new Request('GET', '/api/me/display-preferences'));
        self::assertSame(['textSize' => 'standard', 'showTechnicalDetails' => false], $this->data($other));
    }

    public function testChangingOneLeavesTheOther(): void
    {
        $this->authed()->update($this->put('{"textSize":"larger"}'));
        $response = $this->authed()->update($this->put('{"showTechnicalDetails":true}'));

        self::assertSame(['textSize' => 'larger', 'showTechnicalDetails' => true], $this->data($response));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBodies')]
    public function testAnInvalidBodyIsRefusedWithNoWrite(string $json): void
    {
        $response = $this->authed()->update($this->put($json));

        self::assertSame(422, $response->getStatusCode());
        $current = $this->authed()->get(new Request('GET', '/api/me/display-preferences'));
        self::assertSame(['textSize' => 'standard', 'showTechnicalDetails' => false], $this->data($current));
    }

    /** @return array<string, array{string}> */
    public static function invalidBodies(): array
    {
        return [
            'unknown size'      => ['{"textSize":"huge"}'],
            'non-boolean'       => ['{"showTechnicalDetails":"yes"}'],
            'one bad, one good' => ['{"textSize":"huge","showTechnicalDetails":true}'],
            'nothing'           => ['{}'],
            'not json'          => ['nope'],
        ];
    }

    public function testNoSessionIsRefused(): void
    {
        self::assertSame(401, $this->handler(null)->get(new Request('GET', '/api/me/display-preferences'))->getStatusCode());
        self::assertSame(401, $this->handler(null)->update($this->put('{"textSize":"larger"}'))->getStatusCode());
    }
}
