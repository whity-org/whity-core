<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\TokenValidator;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Notification\Push\PushSubscriptionRepository;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * Your notifications in every workspace you belong to, read-only (WHIT-1081).
 *
 * A person in several workspaces could not tell where they would hear from:
 * notification choices and push devices are stored per workspace. This answers
 * `GET /api/me/notification-summary` with one row per workspace the caller is
 * an ACTIVE member of: how many of their devices have push on there, and
 * whether email and push are on, off, or on for some kinds.
 *
 * Isolation: the workspace list is the caller's own active memberships, and
 * every read goes through the repositories' (tenant_id, profile_id) methods
 * with one of those ids, so nothing outside the caller's own rows is touched.
 * Changing anything still happens in the workspace itself, after switching.
 */
final class NotificationSummaryApiHandler
{
    public function __construct(
        private readonly TokenValidator $tokenValidator,
        private readonly PDO $db,
        private readonly NotificationPreferenceRepository $preferences,
        private readonly PushSubscriptionRepository $pushSubscriptions,
    ) {}

    /**
     * @param array<string, string> $params Route parameters (none).
     */
    public function summary(Request $request, array $params = []): Response
    {
        $ctx = $this->resolveClaims($request);
        if ($ctx === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $activeTenantId] = $ctx;

        $rows = [];
        foreach ($this->activeWorkspaceIds($profileId) as $tenantId) {
            $choices = $this->preferences->listForProfile($tenantId, $profileId);
            $rows[] = [
                'tenant_id'    => $tenantId,
                'current'      => $tenantId === $activeTenantId,
                'push_devices' => count($this->pushSubscriptions->listForProfile($tenantId, $profileId)),
                'email'        => self::channelState($choices, 'email'),
                'push'         => self::channelState($choices, 'push'),
            ];
        }

        return Response::json(['data' => $rows], 200);
    }

    /**
     * `on`, `off` or `some` for one channel, from a person's (type, channel)
     * toggles as the resolver reads them: an exact type beats `*`, and nothing
     * stored means on. Transactional kinds always come and are not counted.
     *
     * @param list<array<string, mixed>> $choices
     * @return 'on'|'off'|'some'
     */
    public static function channelState(array $choices, string $channel): string
    {
        $wildcardOff = false;
        $anyOn = false;
        $anyOff = false;
        foreach ($choices as $choice) {
            if (($choice['channel'] ?? null) !== $channel) {
                continue;
            }
            $enabled = (bool) ($choice['enabled'] ?? true);
            if (($choice['type'] ?? null) === '*') {
                $wildcardOff = !$enabled;
                continue;
            }
            if ($enabled) {
                $anyOn = true;
            } else {
                $anyOff = true;
            }
        }

        if ($wildcardOff) {
            return $anyOn ? 'some' : 'off';
        }

        return $anyOff ? 'some' : 'on';
    }

    /**
     * The workspaces the caller is an active member of, once each.
     *
     * @return list<int>
     */
    private function activeWorkspaceIds(int $profileId): array
    {
        // @tenant-guard-ignore: the caller's own memberships across workspaces, by profile (as the login picker reads them, ADR 0005 §6)
        $stmt = $this->db->prepare(
            "SELECT DISTINCT tenant_id FROM memberships WHERE profile_id = ? AND status = 'active' ORDER BY tenant_id ASC"
        );
        $stmt->execute([$profileId]);

        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * @return array{0: int, 1: int}|null The caller's profile and active workspace.
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
