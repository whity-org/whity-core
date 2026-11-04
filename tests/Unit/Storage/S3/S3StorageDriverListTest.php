<?php

declare(strict_types=1);

namespace Tests\Unit\Storage\S3;

use PHPUnit\Framework\TestCase;
use Whity\Storage\S3\ObjectHttpResponse;
use Whity\Storage\S3\ObjectHttpTransport;
use Whity\Storage\S3\S3Config;
use Whity\Storage\S3\S3StorageDriver;

/** S3StorageDriver::list() (WHIT-886): ListObjectsV2 with pagination, signed like every other request. */
final class S3StorageDriverListTest extends TestCase
{
    public function testFollowsContinuationTokensAndReturnsAllKeysSorted(): void
    {
        $transport = new ListPagesTransport([
            $this->page(['tenants/1/reports/b.txt', 'tenants/1/reports/a/deep.txt'], 'TOKEN-2'),
            $this->page(['tenants/1/reports/c.txt'], null),
        ]);
        $driver = $this->driver($transport);

        $keys = $driver->list('tenants/1/reports/');

        $this->assertSame(
            ['tenants/1/reports/a/deep.txt', 'tenants/1/reports/b.txt', 'tenants/1/reports/c.txt'],
            $keys,
        );
        $this->assertCount(2, $transport->requests);
        $this->assertStringContainsString('list-type=2', $transport->requests[0]['url']);
        $this->assertStringContainsString('prefix=tenants%2F1%2Freports%2F', $transport->requests[0]['url']);
        $this->assertStringContainsString('continuation-token=TOKEN-2', $transport->requests[1]['url']);
    }

    public function testEveryListRequestIsSigned(): void
    {
        $transport = new ListPagesTransport([$this->page([], null)]);

        $this->driver($transport)->list('tenants/1/reports/');

        $auth = $transport->requests[0]['headers']['Authorization'] ?? '';
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIDTEST/', $auth);
        $this->assertArrayHasKey('x-amz-date', $transport->requests[0]['headers']);
    }

    public function testATruncatedPageWithoutATokenIsAnError(): void
    {
        $transport = new ListPagesTransport([
            new ObjectHttpResponse(200, [], '<ListBucketResult><IsTruncated>true</IsTruncated></ListBucketResult>'),
        ]);

        $this->expectException(\Whity\Storage\StorageException::class);

        $this->driver($transport)->list('tenants/1/reports/');
    }

    public function testAnUnreadableBodyIsAnError(): void
    {
        $transport = new ListPagesTransport([new ObjectHttpResponse(200, [], 'not xml <<<')]);

        $this->expectException(\Whity\Storage\StorageException::class);

        $this->driver($transport)->list('tenants/1/reports/');
    }

    private function driver(ObjectHttpTransport $transport): S3StorageDriver
    {
        return new S3StorageDriver(
            new S3Config('https://s3.example.test', 'eu-west-1', 'whity', 'AKIDTEST', 'secret', true),
            $transport,
        );
    }

    /** @param list<string> $keys */
    private function page(array $keys, ?string $nextToken): ObjectHttpResponse
    {
        $contents = '';
        foreach ($keys as $key) {
            $contents .= '<Contents><Key>' . htmlspecialchars($key) . '</Key></Contents>';
        }
        $truncated = $nextToken === null ? 'false' : 'true';
        $token = $nextToken === null ? '' : '<NextContinuationToken>' . $nextToken . '</NextContinuationToken>';

        return new ObjectHttpResponse(
            200,
            [],
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<IsTruncated>' . $truncated . '</IsTruncated>' . $token . $contents
            . '</ListBucketResult>',
        );
    }
}

/** Answers ListObjectsV2 pages in order and records each request. */
final class ListPagesTransport implements ObjectHttpTransport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @param list<ObjectHttpResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body): ObjectHttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return array_shift($this->responses) ?? new ObjectHttpResponse(500, [], '');
    }
}
