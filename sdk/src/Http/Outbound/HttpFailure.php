<?php

declare(strict_types=1);

namespace Whity\Sdk\Http\Outbound;

/**
 * The request never got an answer: refused by policy, DNS, TLS, timeout,
 * connection refused, or an answer over the size cap (SDK 1.114).
 *
 * Its message names the host at most. It never carries the URL's path or
 * query, a header, credentials or a body, so it is safe to log.
 */
final class HttpFailure extends \RuntimeException implements \Whity\Sdk\SdkException
{
}
