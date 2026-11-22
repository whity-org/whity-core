<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Every exception the SDK defines implements this (SDK 1.123).
 *
 * Catch it for "the host or the SDK refused this" without listing each
 * service's own class:
 *
 *     try {
 *         \Whity\app(Contacts::class)->create($tenantId, $data, $profileId);
 *     } catch (SdkException $e) {
 *         return Response::json(['error' => $e->getMessage()], 422);
 *     }
 *
 * Each class still extends the SPL exception it always did (\RuntimeException
 * for all of them today), so existing catches are unchanged. Exceptions the SDK
 * throws but doesn't define (\InvalidArgumentException for a bad key,
 * \LogicException or \RuntimeException for "no workspace in scope") don't
 * implement it.
 */
interface SdkException extends \Throwable
{
}
