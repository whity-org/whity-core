<?php

declare(strict_types=1);

namespace Tests\Sdk\Testing;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Http\Outbound\HttpFailure;
use Whity\Sdk\Testing\RecordedHttpClient;

/** Recorded answers for code that calls out (SDK 1.114, WHIT-1058). */
final class RecordedHttpClientTest extends TestCase
{
    public function testAnswersFromTheScriptAndRecordsWhatWasSent(): void
    {
        $http = (new RecordedHttpClient())
            ->on('POST', 'https://api.example.com/v1/sessions', ['id' => 'cs_1'])
            ->on('GET', 'https://api.example.com/v1/sessions/cs_1', ['status' => 'open'])
            ->on('GET', 'https://api.example.com/v1/sessions/cs_1', ['status' => 'complete']);

        $created = $http->send('post', 'https://api.example.com/v1/sessions', ['Content-Type' => 'application/x-www-form-urlencoded'], 'amount=4900&currency=usd');
        $read = $http->send('GET', 'https://api.example.com/v1/sessions/cs_1', []);
        $unknown = $http->send('GET', 'https://api.example.com/v1/other', []);

        self::assertSame(['id' => 'cs_1'], $created->json());
        self::assertSame(['status' => 'complete'], $read->json(), 'the last matching answer wins');
        self::assertSame(404, $unknown->status);
        self::assertCount(1, $http->to('POST', 'https://api.example.com/v1/sessions'));
        self::assertSame(['amount' => '4900', 'currency' => 'usd'], $http->lastBody('POST', 'https://api.example.com/v1/sessions'));
    }

    public function testAFailureIsThrown(): void
    {
        $http = (new RecordedHttpClient())->failOn('GET', 'https://api.example.com/');

        $this->expectException(HttpFailure::class);
        $http->send('GET', 'https://api.example.com/v1/events', []);
    }
}
