<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\TokenValidator;
use Whity\Core\Notification\Push\PushEndpoint;
use Whity\Core\Notification\Push\PushSubscriptionRepository;
use Whity\Core\Notification\Push\VapidKeys;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * A person's devices for web push (WHIT-1073):
 *
 *   GET    /api/me/push-subscriptions        — the instance's public key and this person's devices
 *   POST   /api/me/push-subscriptions        — turn this browser on (the browser's PushSubscription JSON)
 *   DELETE /api/me/push-subscriptions/{id}   — turn one of their devices off
 *
 * Session-gated and self-scoped to the caller's (workspace, person), like the
 * notification preferences: no RBAC permission, and a person only ever sees or
 * removes their own devices. A device is never listed with its endpoint (a
 * bearer-like URL); the browser recognises itself by the endpoint's SHA-256.
 */
final class PushSubscriptionsApiHandler
{
    /**
     * @param \Closure(): VapidKeys $keys Built lazily: the key ring is read only when a key is needed.
     * @param list<string> $extraHosts PUSH_SERVICE_HOSTS.
     */
    public function __construct(
        private readonly TokenValidator $tokenValidator,
        private readonly PushSubscriptionRepository $repo,
        private readonly \Closure $keys,
        private readonly array $extraHosts = [],
    ) {
    }

    /** @param array<string, mixed> $params */
    public function list(Request $request, array $params = []): Response
    {
        $ctx = $this->resolveClaims($request);
        if ($ctx === null) {
            return Response::error('Unauthenticated', 401);
        }

        return Response::json($this->payload($ctx[1], $ctx[0]), 200);
    }

    /** @param array<string, mixed> $params */
    public function subscribe(Request $request, array $params = []): Response
    {
        $ctx = $this->resolveClaims($request);
        if ($ctx === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId] = $ctx;
        if ($tenantId <= 0) {
            return Response::error('Open a workspace to turn on notifications', 422);
        }

        $body = json_decode($request->getBody(), true);
        $endpoint = is_array($body) && is_string($body['endpoint'] ?? null) ? trim($body['endpoint']) : '';
        $keys = is_array($body) && is_array($body['keys'] ?? null) ? $body['keys'] : [];
        $p256dh = is_string($keys['p256dh'] ?? null) ? trim($keys['p256dh']) : '';
        $auth = is_string($keys['auth'] ?? null) ? trim($keys['auth']) : '';

        $problem = PushEndpoint::problem($endpoint, $p256dh, $auth, $this->extraHosts);
        if ($problem !== null) {
            return Response::error($problem, 422);
        }

        $id = $this->repo->upsert($tenantId, $profileId, $endpoint, $p256dh, $auth, (string) ($request->getHeader('User-Agent') ?? ''));

        return Response::json(['id' => $id] + $this->payload($tenantId, $profileId), 201);
    }

    /** @param array<string, mixed> $params */
    public function remove(Request $request, array $params = []): Response
    {
        $ctx = $this->resolveClaims($request);
        if ($ctx === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId] = $ctx;
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0 || !$this->repo->deleteForProfile($tenantId, $profileId, $id)) {
            return Response::error('Not found', 404);
        }

        return Response::json($this->payload($tenantId, $profileId), 200);
    }

    /** @return array{public_key: string, data: list<array<string, mixed>>} */
    private function payload(int $tenantId, int $profileId): array
    {
        $devices = [];
        foreach ($this->repo->listForProfile($tenantId, $profileId) as $row) {
            $devices[] = [
                'id'              => $row['id'],
                'endpoint_hash'   => PushEndpoint::hash($row['endpoint']),
                'user_agent'      => $row['user_agent'],
                'created_at'      => $row['created_at'],
                'last_success_at' => $row['last_success_at'],
                'failing'         => $row['failures'] > 0,
            ];
        }

        return ['public_key' => ($this->keys)()->publicKey(), 'data' => $devices];
    }

    /**
     * (profileId, tenantId) from the session cookie, else a Bearer token. Fail closed.
     *
     * @return array{0: int, 1: int}|null
     */
    private function resolveClaims(Request $request): ?array
    {
        $claims = $this->tokenValidator->validateAccessToken();
        if ($claims === null) {
            $header = $request->getHeader('Authorization') ?? '';
            if (stripos($header, 'Bearer ') === 0) {
                $token = trim(substr($header, 7));
                if ($token !== '') {
                    $claims = $this->tokenValidator->validateAccessTokenFromBearer($token);
                }
            }
        }
        if ($claims === null) {
            return null;
        }
        $profileId = $claims['profile_id'] ?? null;
        $tenantId = $claims['active_tenant_id'] ?? null;
        if (!is_int($profileId) || $profileId <= 0 || !is_int($tenantId) || $tenantId < 0) {
            return null;
        }

        return [$profileId, $tenantId];
    }
}
