<?php

declare(strict_types=1);

namespace Whity\Core\Observability;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Http\StreamedResponse;

/**
 * The reference a 500 gives a person to quote to support (TplSystemStates).
 *
 * Many handlers catch their own failure and answer 5xx themselves, so the kernel
 * adds the reference to whatever comes out rather than each one doing it. It goes
 * in the JSON body and a header, and in one log line beside the handler's own
 * message, so support can find the request from what the person copied.
 *
 * The body says nothing new: a short random value that means something only next
 * to the server's log.
 */
final class ErrorReference
{
    public const HEADER = 'X-Error-Reference';

    /** Four bytes, so it reads like `7f3a-91c2` on the phone to support. */
    public static function mint(): string
    {
        $hex = bin2hex(random_bytes(4));

        return substr($hex, 0, 4) . '-' . substr($hex, 4);
    }

    public static function attach(Response $response, Request $request): Response
    {
        if ($response->getStatusCode() < 500 || $response instanceof StreamedResponse) {
            return $response;
        }

        $reference = self::mint();
        // The path only: a query string can carry a token.
        error_log(sprintf(
            '[%s] %d %s %s',
            $reference,
            $response->getStatusCode(),
            $request->getMethod(),
            (string) parse_url($request->getPath(), PHP_URL_PATH)
        ));

        $withHeader = $response->withHeaders([self::HEADER => $reference]);

        // Decoded as objects, so an empty `{}` inside stays one.
        $decoded = json_decode($response->getBody());
        if (!$decoded instanceof \stdClass) {
            return $withHeader;
        }
        $decoded->reference = $reference;

        return new Response(
            $response->getStatusCode(),
            (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $withHeader->getHeaders()
        );
    }
}
