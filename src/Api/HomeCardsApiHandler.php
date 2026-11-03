<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Throwable;
use Whity\Auth\RoleChecker;
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
 * Cards come in install order as far as the host knows it: code plugins in the order
 * they load, then this workspace's plugins by key. People can't rearrange them (H.4).
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

        $hidden = $this->hiddenKeys($tenantId, $profileId);
        $data = [];
        foreach ($this->visibleCards($tenantId, $profileId) as $card) {
            $data[] = $card + ['hidden' => isset($hidden[$card['key']])];
        }

        return Response::json(['data' => $data], 200);
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

        if ($body['hidden']) {
            $stmt = $this->db->prepare(
                'INSERT INTO home_card_hidden (tenant_id, profile_id, card_key)
                 VALUES (:tenant_id, :profile_id, :card_key)
                 ON CONFLICT (tenant_id, profile_id, card_key) DO NOTHING'
            );
        } else {
            $stmt = $this->db->prepare(
                'DELETE FROM home_card_hidden WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND card_key = :card_key'
            );
        }
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId, ':card_key' => $key]);

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

    /** @return array<string, true> */
    private function hiddenKeys(int $tenantId, int $profileId): array
    {
        $stmt = $this->db->prepare('SELECT card_key FROM home_card_hidden WHERE tenant_id = :tenant_id AND profile_id = :profile_id');
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        return array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
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
