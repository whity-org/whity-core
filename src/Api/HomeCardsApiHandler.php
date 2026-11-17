<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Throwable;
use Whity\Auth\RoleChecker;
use Whity\Core\Dashboard\DashboardLayoutRepository;
use Whity\Core\Plugin\Tenant\TenantManifestHost;
use Whity\Core\Plugin\Tenant\TenantRecordRepository;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;

/**
 * The cards installed apps add to the caller's home (WHIT-872, design 2.9 H.4).
 *
 *   GET /api/me/home-cards                 the cards the caller may see, hidden or not
 *   PUT /api/me/home-cards/{key}/hidden    {"hidden": true|false}, for this person here
 *
 * A card is listed only when the caller holds its permission in this workspace, so
 * nobody learns about an app they can't use. It carries where its data is
 * (`source`), not the data: the browser asks that route, which applies its own
 * permission and the person's scope, and a slow app holds up only its own card.
 *
 * A workspace plugin's card counts one record type instead (`value`). It has no
 * route to ask, so the host answers, for this workspace only and after the same
 * permission check.
 *
 * Cards come in the person's own order (SDK 1.115, H.5), then in install order as far
 * as the host knows it: code plugins in the order they load, then this workspace's
 * plugins by key. Hidden, order and size are kept in `dashboard_layout`, shared with
 * the widgets ({@see DashboardApiHandler}).
 */
final class HomeCardsApiHandler
{
    public function __construct(
        private readonly PDO $db,
        private readonly RoleChecker $roleChecker,
        private readonly PluginLoader $loader,
        private readonly TenantManifestHost $tenantPlugins,
        private readonly TenantRecordRepository $records,
        private readonly Router $router,
    ) {
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params = []): Response
    {
        $caller = self::caller($request);
        if ($caller === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$tenantId, $profileId] = $caller;

        $rows = $this->layout()->rowsFor($tenantId, $profileId);
        $data = [];
        foreach ($this->visibleCards($tenantId, $profileId) as $card) {
            $row = $rows[$card['key']] ?? null;
            $data[] = $card + ['hidden' => $row['hidden'] ?? false, 'position' => $row['position'] ?? null];
        }

        return Response::json(['data' => DashboardLayoutRepository::ordered($data, $rows)], 200);
    }

    /** @param array<string, string> $params */
    public function setHidden(Request $request, array $params = []): Response
    {
        $caller = self::caller($request);
        if ($caller === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$tenantId, $profileId] = $caller;

        $body = json_decode($request->getBody(), true);
        if (!is_array($body) || !is_bool($body['hidden'] ?? null)) {
            return Response::error('hidden must be true or false', 422);
        }

        // Only a card the caller can see: an app they can't use answers as if it
        // doesn't exist, and nothing is stored for a key that names nothing.
        $key = (string) ($params['key'] ?? '');
        $known = array_column($this->visibleCards($tenantId, $profileId), 'key');
        if (!in_array($key, $known, true)) {
            return Response::error('Card not found', 404);
        }

        $this->layout()->setHidden($tenantId, $profileId, $key, $body['hidden']);

        return Response::json(['data' => ['key' => $key, 'hidden' => $body['hidden']]], 200);
    }

    /**
     * Every card the caller may see in this workspace, without the hidden flag.
     *
     * @return list<array<string, mixed>>
     */
    private function visibleCards(int $tenantId, int $profileId): array
    {
        $cards = [];
        foreach ($this->loader->getHomeCards() as $card) {
            if ($this->roleChecker->hasPermissionForProfile($profileId, $card['permission'], $tenantId)) {
                $cards[$card['plugin']] = self::present($card['plugin'], $card, $this->router->versionedPath($card['source']), null);
            }
        }

        foreach ($this->tenantPlugins->manifestsFor($tenantId) as $manifest) {
            $card = $manifest->homeCard();
            // A workspace plugin has no routes, so only a `record` card can answer.
            if ($card === null || !isset($card['record']) || isset($cards[$manifest->name()])) {
                continue;
            }
            if (!$this->roleChecker->hasPermissionForProfile($profileId, (string) $card['permission'], $tenantId)) {
                continue;
            }
            $cards[$manifest->name()] = self::present(
                $manifest->name(),
                $card,
                null,
                $this->countRecords($tenantId, $manifest->name(), (string) $card['record'])
            );
        }

        return array_values($cards);
    }

    private function countRecords(int $tenantId, string $pluginName, string $type): ?int
    {
        try {
            return $this->records->countByType($tenantId, $pluginName)[$type] ?? 0;
        } catch (Throwable $e) {
            error_log('[HomeCardsApiHandler] records could not be counted: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    private static function present(string $key, array $card, ?string $source, ?int $value): array
    {
        return [
            'key'       => $key,
            'label'     => $card['label']['en'],
            'labelAr'   => $card['label']['ar'],
            'caption'   => $card['caption']['en'] ?? null,
            'captionAr' => $card['caption']['ar'] ?? null,
            'icon'      => $card['icon'] ?? null,
            'href'      => isset($card['screen']) ? '/admin/x/' . $card['screen'] : null,
            'source'    => $source,
            'value'     => $value,
        ];
    }

    /**
     * The keys of the cards the caller may see in this workspace (SDK 1.115:
     * the dashboard layout orders them with the widgets).
     *
     * @return list<string>
     */
    public function visibleKeys(int $tenantId, int $profileId): array
    {
        return array_map(static fn (array $card): string => (string) $card['key'], $this->visibleCards($tenantId, $profileId));
    }

    /** Hidden, order and size live with the widgets' (SDK 1.115). */
    private function layout(): DashboardLayoutRepository
    {
        return new DashboardLayoutRepository($this->db);
    }

    /** @return array{0: int, 1: int}|null */
    private static function caller(Request $request): ?array
    {
        $tenantId = TenantContext::getTenantId();
        $actor    = $request->user;
        $profile  = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;

        return $tenantId === null || $profile === null ? null : [$tenantId, $profile];
    }
}
