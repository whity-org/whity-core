<?php

declare(strict_types=1);

namespace Tests\Sdk;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Storage\PluginFile;
use Whity\Sdk\Storage\PluginFileController;
use Whity\Sdk\Storage\PluginFileStore;

/**
 * The generic upload and download controller (SDK 1.63, WHIT-886). Its store is
 * an in-memory fake that enforces the same path rule as the host, so the test is
 * about the controller: status codes, the size cap, the content type it keeps,
 * and the headers that stop a stored file from running in a browser.
 */
final class PluginFileControllerTest extends TestCase
{
    public function testUploadStoresTheBodyUnderTheNamedFileAndReturnsItsDescription(): void
    {
        $store = new MemoryPluginFileStore();
        $controller = new PluginFileController($store);

        $response = $controller->upload(
            new Request('POST', '/api/reports/files/march.csv', ['Content-Type' => 'text/csv'], "a,b\n1,2"),
            ['name' => 'march.csv'],
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame("a,b\n1,2", $store->files['march.csv']->contents);
        $body = json_decode($response->getBody(), true);
        $this->assertSame(['name' => 'march.csv', 'size' => 7, 'content_type' => 'text/csv'], $body);
    }

    public function testAMalformedOrMissingContentTypeIsStoredAsOctetStream(): void
    {
        $store = new MemoryPluginFileStore();
        $controller = new PluginFileController($store);

        $controller->upload(new Request('POST', '/x', ['Content-Type' => 'text/<script>'], 'x'), ['name' => 'a.bin']);
        $controller->upload(new Request('POST', '/x', [], 'y'), ['name' => 'b.bin']);

        $this->assertSame('application/octet-stream', $store->files['a.bin']->contentType);
        $this->assertSame('application/octet-stream', $store->files['b.bin']->contentType);
    }

    public function testAnUploadOverTheSizeCapIsRefusedAndNothingIsStored(): void
    {
        $store = new MemoryPluginFileStore();
        $controller = new PluginFileController($store);
        $tooBig = str_repeat('a', PluginFileController::MAX_UPLOAD_BYTES + 1);

        $response = $controller->upload(new Request('POST', '/x', [], $tooBig), ['name' => 'big.bin']);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame([], $store->files);
    }

    public function testAPathTheStoreRefusesBecomesABadRequest(): void
    {
        $controller = new PluginFileController(new MemoryPluginFileStore());

        $response = $controller->upload(new Request('POST', '/x', [], 'x'), ['name' => '../ledger/secret']);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testDownloadSendsTheFileAsAnAttachmentThatCannotBeSniffed(): void
    {
        $store = new MemoryPluginFileStore();
        $store->put('report.html', '<p>hi</p>', 'text/html');
        $controller = new PluginFileController($store);

        $response = $controller->download(new Request('GET', '/x'), ['name' => 'report.html']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<p>hi</p>', $response->getBody());
        $headers = $response->getHeaders();
        $this->assertSame('text/html', $headers['content-type']);
        $this->assertSame('attachment; filename="report.html"', $headers['content-disposition']);
        $this->assertSame('nosniff', $headers['x-content-type-options']);
    }

    public function testDownloadOfAMissingFileIsNotFound(): void
    {
        $response = (new PluginFileController(new MemoryPluginFileStore()))
            ->download(new Request('GET', '/x'), ['name' => 'nope.pdf']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testListReturnsOnlyTheNamesTheStoreHolds(): void
    {
        $store = new MemoryPluginFileStore();
        $store->put('a.txt', 'a');
        $store->put('b.txt', 'b');

        $response = (new PluginFileController($store))->list(new Request('GET', '/x'), []);

        $this->assertSame(['data' => ['a.txt', 'b.txt']], json_decode($response->getBody(), true));
    }

    public function testDeleteOfAMissingFileIsNotAnError(): void
    {
        $response = (new PluginFileController(new MemoryPluginFileStore()))
            ->delete(new Request('DELETE', '/x'), ['name' => 'gone.txt']);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testWithNoWorkspaceInScopeEveryActionIsForbidden(): void
    {
        $store = new MemoryPluginFileStore();
        $store->noWorkspace = true;
        $controller = new PluginFileController($store);

        $this->assertSame(403, $controller->list(new Request('GET', '/x'), [])->getStatusCode());
        $this->assertSame(403, $controller->upload(new Request('POST', '/x', [], 'x'), ['name' => 'a'])->getStatusCode());
    }
}

/**
 * An in-memory store that refuses the same paths the host does.
 */
final class MemoryPluginFileStore implements PluginFileStore
{
    /** @var array<string, PluginFile> */
    public array $files = [];

    public bool $noWorkspace = false;

    public function put(string $path, string $contents, string $contentType = 'application/octet-stream'): void
    {
        $this->check($path);
        $this->files[$path] = new PluginFile($path, $contents, $contentType);
    }

    public function get(string $path): ?PluginFile
    {
        $this->check($path);

        return $this->files[$path] ?? null;
    }

    public function exists(string $path): bool
    {
        $this->check($path);

        return isset($this->files[$path]);
    }

    public function list(string $prefix = ''): array
    {
        $this->workspace();
        $names = array_keys($this->files);
        sort($names);

        return $names;
    }

    public function delete(string $path): void
    {
        $this->check($path);
        unset($this->files[$path]);
    }

    private function check(string $path): void
    {
        $this->workspace();
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,127}$/', $path) !== 1) {
            throw new InvalidArgumentException('Not a plain file name.');
        }
    }

    private function workspace(): void
    {
        if ($this->noWorkspace) {
            throw new LogicException('No workspace.');
        }
    }
}
