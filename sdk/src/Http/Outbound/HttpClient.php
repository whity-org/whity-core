<?php

declare(strict_types=1);

namespace Whity\Sdk\Http\Outbound;

/**
 * The one way plugin code reaches another server (SDK 1.114, WHIT-1058).
 *
 * Code that calls out takes this interface, so its tests answer from a script
 * ({@see \Whity\Sdk\Testing\RecordedHttpClient}) and nothing in a test opens a
 * socket. In production pass {@see StreamHttpClient}.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @param string|null           $body    Already encoded (form or JSON); the caller sets Content-Type.
     *
     * @throws HttpFailure When the request could not be completed at all. Any
     *                     answer, 4xx and 5xx included, is a response.
     */
    public function send(string $method, string $url, array $headers, ?string $body = null): HttpResponse;
}
