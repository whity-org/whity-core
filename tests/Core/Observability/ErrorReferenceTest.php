<?php

declare(strict_types=1);

namespace Tests\Core\Observability;

use PHPUnit\Framework\TestCase;
use Whity\Core\Observability\ErrorReference;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Http\StreamedResponse;

final class ErrorReferenceTest extends TestCase
{
    public function testAServerErrorCarriesAReferenceInBodyAndHeader(): void
    {
        $out = ErrorReference::attach(Response::error('Failed to fetch', 500), new Request('GET', '/api/x?token=secret'));

        $body = json_decode($out->getBody(), true);
        self::assertSame('Failed to fetch', $body['error']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{4}-[0-9a-f]{4}$/', $body['reference']);
        self::assertSame($body['reference'], array_change_key_case($out->getHeaders())[strtolower(ErrorReference::HEADER)]);
        self::assertSame(500, $out->getStatusCode());
    }

    public function testASuccessOrARefusalIsUntouched(): void
    {
        foreach ([Response::json(['ok' => true]), Response::error('No', 403), Response::error('Missing', 404)] as $response) {
            self::assertSame($response, ErrorReference::attach($response, new Request('GET', '/x')));
        }
    }

    public function testAStreamIsNotBuffered(): void
    {
        $stream = new StreamedResponse(500, static function (): void {
        });

        self::assertSame($stream, ErrorReference::attach($stream, new Request('GET', '/x')));
    }

    public function testAnEmptyObjectInTheBodyStaysOne(): void
    {
        $out = ErrorReference::attach(Response::json(['error' => 'x', 'details' => new \stdClass()], 500), new Request('GET', '/x'));

        self::assertStringContainsString('"details":{}', $out->getBody());
    }

    public function testABodyThatIsNotJsonStillGetsTheHeader(): void
    {
        $out = ErrorReference::attach(new Response(502, 'Bad gateway'), new Request('GET', '/x'));

        self::assertSame('Bad gateway', $out->getBody());
        self::assertArrayHasKey(strtolower(ErrorReference::HEADER), array_change_key_case($out->getHeaders()));
    }
}
