<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Delegation\AwayService;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * "I'm away" (OR.1):
 *
 *   GET    /api/me/away        the caller's open away period, or null
 *   PUT    /api/me/away        hand over: dates, who decides, what goes to them, a message
 *   DELETE /api/me/away        back early: ends it at once
 *   GET    /api/away-periods   everyone's, for the Delegations list (delegation:manage)
 *
 * The /api/me routes are session-gated and self-scoped: a person sets only
 * their own, from their own account. Nobody can make someone else away.
 */
final class AwayApiHandler
{
    public function __construct(
        private readonly AwayService $away,
        private readonly ?AuditLoggerInterface $audit = null,
    ) {
    }

    public function show(Request $request): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }

        return Response::json(['data' => $this->away->current($caller[0], $caller[1])], 200);
    }

    public function set(Request $request): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;

        $body = JsonBody::parsed($request);
        $delegate = isset($body['delegateProfileId']) && is_numeric($body['delegateProfileId']) ? (int) $body['delegateProfileId'] : 0;
        $covers = is_array($body['covers'] ?? null) ? array_values(array_filter($body['covers'], 'is_string')) : [];

        $result = $this->away->start(
            $tenantId,
            $profileId,
            $delegate,
            is_string($body['startsOn'] ?? null) ? $body['startsOn'] : '',
            is_string($body['backOn'] ?? null) ? $body['backOn'] : '',
            $covers,
            is_string($body['message'] ?? null) ? $body['message'] : null,
        );

        if (is_string($result)) {
            return Response::error(match ($result) {
                'self'     => 'Choose someone else to decide for you.',
                'delegate' => 'That person isn\'t in this workspace.',
                'covers'   => 'Choose at least one thing to hand over.',
                default    => 'Choose a start day and a later day you\'re back, within a year.',
            }, 422, ['reason' => $result]);
        }

        $this->audit?->record('away.started', [
            'tenant_id'   => $tenantId,
            'target_type' => 'away_period',
            'target_id'   => $result['id'],
            'metadata'    => [
                'delegate_profile_id' => $delegate,
                'starts_on'           => $result['startsOn'],
                'back_on'             => $result['backOn'],
                'covers'              => $result['covers'],
            ],
        ]);

        return Response::json(['data' => $result], 200);
    }

    public function end(Request $request): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;

        if (!$this->away->end($tenantId, $profileId)) {
            return Response::error('You aren\'t away', 404);
        }
        $this->audit?->record('away.ended', [
            'tenant_id'   => $tenantId,
            'target_type' => 'profile',
            'target_id'   => $profileId,
        ]);

        return Response::json(['data' => null], 200);
    }

    public function list(Request $request): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }

        return Response::json(['data' => $this->away->listForTenant($caller[0])], 200);
    }

    /** @return array{0: int, 1: int}|Response */
    private function caller(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        $actor    = $request->user;
        $profile  = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
        if ($tenantId === null || $profile === null) {
            return Response::error('Unauthenticated', 401);
        }

        return [$tenantId, $profile];
    }
}
