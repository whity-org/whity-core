<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Response;
use Whity\Core\Security\GatedApproval;

/**
 * The HTTP side of {@see GatedApproval}, shared by every handler with a gated
 * approve, so each one answers the same way:
 *
 *  - starting the check: 200 `{method, email_hint}`, 429 when too many codes
 *    were sent, 503 when the gate isn't available;
 *  - approving: the code comes as `code` in the body; a missing, wrong or used
 *    one is 403 with `reason: identity_check_failed`, and nothing changes.
 */
final class GatedApprovalHttp
{
    public const REASON_FAILED = 'identity_check_failed';

    private function __construct()
    {
    }

    public static function start(?GatedApproval $gate, int $tenantId, int $approverId, string $kind, string $subject): Response
    {
        if ($gate === null) {
            return self::unavailable();
        }
        $started = $gate->start($tenantId, $approverId, $kind, $subject);
        if ($started === null) {
            return Response::error('Too many codes were sent, or your account has no email address. Wait a few minutes and try again.', 429);
        }

        return Response::json(['data' => $started], 200);
    }

    /**
     * The method that proved it, or the refusal to send back.
     *
     * @param array<string, mixed> $body the request body; the code is `code`
     */
    public static function confirm(?GatedApproval $gate, int $tenantId, int $approverId, string $kind, string $subject, array $body): string|Response
    {
        if ($gate === null) {
            return self::unavailable();
        }
        $code = is_string($body['code'] ?? null) ? $body['code'] : null;
        $method = $gate->confirm($tenantId, $approverId, $kind, $subject, $code);
        if ($method === null) {
            return Response::error('Confirm it is you first: that code is not correct or has expired.', 403, ['reason' => self::REASON_FAILED]);
        }

        return $method;
    }

    private static function unavailable(): Response
    {
        return Response::error('Confirming it is you is not available, so nothing can be approved', 503);
    }
}
