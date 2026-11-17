<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecipientProfiles;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\MyPhoneApiHandler;
use Whity\Auth\TokenValidator;
use Whity\Core\Identity\PersonalPhone;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * A person's own phone number (WHIT-1181): E.164 on their profile, set only
 * by themselves through GET and PUT /api/v1/me/phone.
 */
final class MyPhoneApiHandlerRealEngineTest extends TestCase
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
    private function handler(?array $claims = ['profile_id' => self::CALLER, 'active_tenant_id' => 1]): MyPhoneApiHandler
    {
        $tv = $this->createStub(TokenValidator::class);
        $tv->method('validateAccessToken')->willReturn($claims);

        return new MyPhoneApiHandler($tv, new PersonalPhone($this->pdo));
    }

    private function put(string $json): Response
    {
        return $this->handler()->update(new Request('PUT', '/api/me/phone', [], $json));
    }

    private static function phone(Response $response): ?string
    {
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body, $response->getBody());
        self::assertIsArray($body['data']);

        return $body['data']['phone'];
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function written(): iterable
    {
        yield 'E.164' => ['+962791234567', '+962791234567'];
        yield 'grouped as read' => ['+962 79 123 4567', '+962791234567'];
        yield 'dashes and brackets' => ['+1 (201) 555-0123', '+12015550123'];
        yield 'international 00' => ['00447400123456', '+447400123456'];
        yield 'Arabic-Indic digits' => ['+٩٦٢٧٩١٢٣٤٥٦٧', '+962791234567'];
        yield 'no country code' => ['0791234567', null];
        yield 'letters' => ['+962 79 CALL ME', null];
        yield 'too long' => ['+9627912345678901', null];
        yield 'leading zero country' => ['+0791234567', null];
    }

    #[DataProvider('written')]
    public function testNormalizesToE164OrRefuses(string $raw, ?string $expected): void
    {
        self::assertSame($expected, PersonalPhone::normalize($raw));
    }

    public function testNobodyHasAPhoneUntilTheySetOne(): void
    {
        $response = $this->handler()->get(new Request('GET', '/api/me/phone'));
        self::assertSame(200, $response->getStatusCode());
        self::assertNull(self::phone($response));
        self::assertSame('private, no-store', array_change_key_case($response->getHeaders())['cache-control'] ?? null);
    }

    public function testThePersonSetsTheirOwnPhoneAsE164AndNobodyElsesChanges(): void
    {
        $response = $this->put('{"phone":"+962 79 123 4567"}');
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame('+962791234567', self::phone($response));
        self::assertSame('+962791234567', self::phone($this->handler()->get(new Request('GET', '/api/me/phone'))));
        self::assertSame('person', $this->column('SELECT phone_source FROM profiles WHERE id = ' . self::CALLER));

        $other = $this->handler(['profile_id' => self::OTHER, 'active_tenant_id' => 1])->get(new Request('GET', '/api/me/phone'));
        self::assertNull(self::phone($other));
    }

    public function testNullOrEmptyRemovesIt(): void
    {
        $this->put('{"phone":"+962791234567"}');
        self::assertNull(self::phone($this->put('{"phone":null}')));
        $this->put('{"phone":"+962791234567"}');
        self::assertNull(self::phone($this->put('{"phone":"  "}')));
        self::assertNull($this->column('SELECT phone FROM profiles WHERE id = ' . self::CALLER));
    }

    private function column(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }

    public function testAnUnreadableNumberIsRefusedAndNothingIsWritten(): void
    {
        $this->put('{"phone":"+962791234567"}');
        foreach (['{"phone":"0791234567"}', '{"phone":12345}', '{}', 'not json'] as $body) {
            $response = $this->put($body);
            self::assertSame(422, $response->getStatusCode(), $body);
        }
        self::assertSame('+962791234567', self::phone($this->handler()->get(new Request('GET', '/api/me/phone'))));
    }

    public function testSignedOutIsRefused(): void
    {
        self::assertSame(401, $this->handler(null)->get(new Request('GET', '/api/me/phone'))->getStatusCode());
        self::assertSame(401, $this->handler(null)->update(new Request('PUT', '/api/me/phone', [], '{"phone":"+962791234567"}'))->getStatusCode());
    }
}
