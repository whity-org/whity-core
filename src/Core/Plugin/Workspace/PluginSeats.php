<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Workspace;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Core\Billing\External\BillingActor;
use Whity\Core\Billing\External\BillingPortal;
use Whity\Core\Billing\External\BillingSubject;
use Whity\Core\Settings\PluginSettingsRegistry;
use Whity\Core\Support\SourceSlug;

/**
 * Paid plugins sold per seat (SDK 1.110, WHIT-1152): Whity for Salons per
 * chair. The plugin names one of its own integer settings as the count; the
 * workspace buys that many, and when the count changes the subscription's
 * quantity follows.
 */
final class PluginSeats
{
    /**
     * @param \Closure(int): array<string, mixed>              $effective  a workspace's effective settings
     * @param \Closure(): array<string, array<string, mixed>> $paidAccess plugin name => getAccess() declaration
     */
    public function __construct(
        private readonly \Closure $effective,
        private readonly \Closure $paidAccess,
        private readonly ?PDO $db = null,
        private readonly ?BillingPortal $portal = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * How many seats the workspace has of a plugin sold per seat, with the
     * unit's names; null for a plugin not sold per seat.
     *
     * @return array{count: int, unit: array{en: string, ar: string}, units: array{en: string, ar: string}}|null
     */
    public function of(string $pluginName, int $tenantId): ?array
    {
        $seats = $this->declaration($pluginName);
        if ($seats === null) {
            return null;
        }
        $key = PluginSettingsRegistry::canonicalKey($pluginName, $seats['setting']);
        $value = ($this->effective)($tenantId)[$key] ?? null;
        $count = is_numeric($value) ? max(1, (int) $value) : 1;

        return ['count' => $count, 'unit' => $seats['unit'], 'units' => $seats['units']];
    }

    /** How many to buy of an add-on plan `plugin.<slug>.<plan>`; null when it isn't sold per seat. */
    public function quantityForPlan(string $planKey, int $tenantId): ?int
    {
        foreach (($this->paidAccess)() as $name => $access) {
            $product = is_string($access['product'] ?? null) ? $access['product'] : '';
            if ($product !== '' && str_starts_with($planKey, $product . '.')) {
                return $this->of($name, $tenantId)['count'] ?? null;
            }
        }

        return null;
    }

    /**
     * `plugin.settings.changed`: when the changed key is a seat count, the
     * workspace's live subscription to that plugin's add-on gets the new
     * quantity. The provider prorates as it does. A failure is logged; the
     * next change, or the operator, tries again.
     *
     * @param array<string, mixed> $payload
     */
    public function onSettingChanged(array $payload): void
    {
        $tenantId = $payload['tenant_id'] ?? null;
        $slug = $payload['plugin'] ?? null;
        $key = $payload['key'] ?? null;
        if (!is_int($tenantId) || $tenantId <= 0 || !is_string($slug) || !is_string($key) || $this->db === null || $this->portal === null) {
            return;
        }
        foreach (($this->paidAccess)() as $name => $access) {
            $seats = $this->declaration((string) $name);
            if ($seats === null || SourceSlug::from((string) $name) !== $slug || $seats['setting'] !== $key) {
                continue;
            }
            $count = $this->of((string) $name, $tenantId)['count'] ?? 1;
            $this->resize($tenantId, (string) $access['product'], $count);
        }
    }

    private function resize(int $tenantId, string $product, int $count): void
    {
        assert($this->db !== null && $this->portal !== null);
        $stmt = $this->db->prepare(
            'SELECT pp.external_ref FROM plan_prices pp JOIN plans p ON p.id = pp.plan_id
              WHERE p.plan_key LIKE :product AND pp.external_ref IS NOT NULL'
        );
        $stmt->execute([':product' => $product . '.%']);
        $refs = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        try {
            foreach ($this->portal->subscriptionsFor(BillingSubject::forTenant($tenantId)) as $line) {
                if ($line->priceRef !== null && isset($refs[$line->priceRef]) && $line->grantsAccess && $line->quantity !== $count) {
                    $this->portal->changeQuantity(BillingActor::job('plugin-seats'), $line->id, $count);
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('A plugin subscription\'s seats could not follow the new count', [
                'tenant_id' => $tenantId,
                'product' => $product,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array{setting: string, unit: array{en: string, ar: string}, units: array{en: string, ar: string}}|null */
    private function declaration(string $pluginName): ?array
    {
        $seats = (($this->paidAccess)()[$pluginName] ?? [])['seats'] ?? null;
        if (!is_array($seats) || !is_string($seats['setting'] ?? null)) {
            return null;
        }
        $label = static fn (mixed $v): array => is_array($v) && is_string($v['en'] ?? null) && is_string($v['ar'] ?? null)
            ? ['en' => $v['en'], 'ar' => $v['ar']]
            : ['en' => 'seat', 'ar' => 'مقعد'];

        return ['setting' => $seats['setting'], 'unit' => $label($seats['unit'] ?? null), 'units' => $label($seats['units'] ?? null)];
    }
}
