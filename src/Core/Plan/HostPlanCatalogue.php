<?php

declare(strict_types=1);

namespace Whity\Core\Plan;

use PDO;
use Whity\Core\Container\HostWiredService;
use Whity\Core\Db\DbBool;
use Whity\Sdk\Billing\PlanCatalogue;
use Whity\Sdk\Billing\PlanPriceTerms;

/**
 * {@see PlanCatalogue} for plugins: read-only views of `plans` and
 * `plan_prices` (SDK 1.84, WHIT-1011). Both are global catalogue tables, the
 * operator's, so there is no tenant to bind.
 */
final class HostPlanCatalogue implements PlanCatalogue, HostWiredService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function activePrice(string $planKey, string $period, string $currency): ?PlanPriceTerms
    {
        $statement = $this->db->prepare(
            'SELECT pp.id, pp.unit_amount, pp.currency, pp.billing_period, pp.is_per_seat, pp.is_per_device, p.plan_key, p.name
               FROM plan_prices pp JOIN plans p ON p.id = pp.plan_id
              WHERE p.plan_key = :plan_key AND p.is_active = :on AND pp.is_active = :on2
                AND pp.billing_period = :period AND pp.currency = :currency
              ORDER BY pp.id ASC LIMIT 1'
        );
        $statement->bindValue(':plan_key', $planKey);
        $statement->bindValue(':on', true, PDO::PARAM_BOOL);
        $statement->bindValue(':on2', true, PDO::PARAM_BOOL);
        $statement->bindValue(':period', $period);
        $statement->bindValue(':currency', strtoupper($currency));
        $statement->execute();

        return self::terms($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function price(int $priceId): ?PlanPriceTerms
    {
        $statement = $this->db->prepare(
            'SELECT pp.id, pp.unit_amount, pp.currency, pp.billing_period, pp.is_per_seat, pp.is_per_device, p.plan_key, p.name
               FROM plan_prices pp JOIN plans p ON p.id = pp.plan_id
              WHERE pp.id = :id'
        );
        $statement->execute([':id' => $priceId]);

        return self::terms($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function isAddon(string $planKey): bool
    {
        $statement = $this->db->prepare('SELECT is_addon FROM plans WHERE plan_key = :plan_key');
        $statement->execute([':plan_key' => $planKey]);
        $value = $statement->fetchColumn();

        return $value !== false && DbBool::of($value);
    }

    private static function terms(mixed $row): ?PlanPriceTerms
    {
        if (!is_array($row)) {
            return null;
        }

        return new PlanPriceTerms(
            (int) $row['id'],
            (string) $row['plan_key'],
            (string) $row['name'],
            (string) $row['currency'],
            (int) $row['unit_amount'],
            (string) $row['billing_period'],
            DbBool::of($row['is_per_seat']),
            DbBool::of($row['is_per_device'] ?? false),
        );
    }
}
