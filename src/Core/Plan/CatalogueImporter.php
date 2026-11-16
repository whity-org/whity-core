<?php

declare(strict_types=1);

namespace Whity\Core\Plan;

use PDO;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;

/**
 * Adds plans, prices and limits from a catalogue file.
 *
 * ADDS ONLY, like migration 152. Anything already there is reported as skipped
 * and left exactly as it is, because somebody may have edited it at
 * `/admin/billing` and a re-run must not reprice their product.
 *
 * A RETIRED PRICE COUNTS AS THERE. Retiring is how a price is withdrawn or
 * replaced on the billing screen, so bringing back a row on those terms would
 * undo that decision.
 *
 * THE WHOLE FILE IS CHECKED BEFORE ANYTHING IS WRITTEN, and the writes share one
 * transaction, so a file is applied completely or not at all.
 *
 * A LIMIT THAT WOULD TIGHTEN A TIER PEOPLE ARE ON IS SKIPPED. PlanService refuses
 * that without a confirmation which names the workspaces affected, and an import
 * is not the place to give it. The report sends the operator to the screen.
 */
final class CatalogueImporter
{
    private const PLAN_FIELDS = ['plan_key', 'name', 'description', 'sort_order', 'is_addon', 'prices', 'limits'];
    private const PRICE_FIELDS = ['currency', 'amount', 'period', 'per_seat'];

    public function __construct(
        private readonly PDO $db,
        private readonly PlanRepository $plans,
        private readonly PlanService $service,
        private readonly PlanPriceRepository $prices,
    ) {
    }

    public static function forDatabase(PDO $db): self
    {
        $plans = new PlanRepository($db);

        return new self(
            $db,
            $plans,
            new PlanService($plans, new EntitlementService(new TenantEntitlementRepository($db)), $db),
            new PlanPriceRepository($db),
        );
    }

    /**
     * Everything wrong with a decoded catalogue, or an empty list.
     *
     * @return list<string>
     */
    public static function problems(mixed $catalogue): array
    {
        if (!is_array($catalogue) || !is_array($catalogue['plans'] ?? null) || $catalogue['plans'] === []
            || !array_is_list($catalogue['plans'])) {
            return ['The file must be a JSON object with a non-empty "plans" list.'];
        }

        $problems = [];
        $seen = [];
        foreach ($catalogue['plans'] as $index => $plan) {
            $where = 'plans[' . $index . ']';
            if (!is_array($plan) || array_is_list($plan)) {
                $problems[] = "{$where} must be an object.";
                continue;
            }

            $key = is_string($plan['plan_key'] ?? null) ? self::planKey($plan) : '';
            $where = $key !== '' ? "plan \"{$key}\"" : $where;
            array_push($problems, ...self::planProblems($plan, $key, $where));

            if ($key !== '' && isset($seen[$key])) {
                $problems[] = "{$where} appears more than once.";
            }
            $seen[$key] = true;
        }

        return $problems;
    }

    /**
     * Apply a catalogue that {@see self::problems()} has passed.
     *
     * @param array<string, mixed> $catalogue
     * @return array{added: list<string>, skipped: list<string>}
     * @throws PlanValidationException When the catalogue has problems.
     */
    public function apply(array $catalogue): array
    {
        $problems = self::problems($catalogue);
        if ($problems !== []) {
            throw new PlanValidationException('catalogue', $problems[0]);
        }

        $report = ['added' => [], 'skipped' => []];

        $this->db->beginTransaction();
        try {
            foreach ($catalogue['plans'] as $plan) {
                $this->applyPlan($plan, $report);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $plan
     * @param array{added: list<string>, skipped: list<string>} $report
     */
    private function applyPlan(array $plan, array &$report): void
    {
        $key = self::planKey($plan);
        $existing = $this->plans->findByKey($key);

        if ($existing !== null) {
            $planId = (int) $existing['id'];
            $report['skipped'][] = "plan {$key}: already exists";
        } else {
            $planId = $this->service->createPlan(
                $key,
                (string) $plan['name'],
                $plan['description'] ?? null,
                true,
                (int) ($plan['sort_order'] ?? 0),
                ($plan['is_addon'] ?? false) === true,
            );
            $report['added'][] = "plan {$key}";
        }

        foreach ($plan['prices'] ?? [] as $price) {
            $this->applyPrice($key, $planId, $price, $report);
        }

        $bundle = $this->plans->getEntitlements($planId);
        foreach ($plan['limits'] ?? [] as $limit => $value) {
            if (array_key_exists($limit, $bundle)) {
                $report['skipped'][] = "limit {$key} {$limit}: already set";
                continue;
            }

            try {
                $this->service->setPlanEntitlement($planId, $limit, self::limitText($value) ?? '');
                $report['added'][] = "limit {$key} {$limit}";
            } catch (PlanValidationException $e) {
                $report['skipped'][] = "limit {$key} {$limit}: {$e->getMessage()}";
            }
        }
    }

    /**
     * @param array<string, mixed> $price
     * @param array{added: list<string>, skipped: list<string>} $report
     */
    private function applyPrice(string $key, int $planId, array $price, array &$report): void
    {
        $currency = PlanPriceRepository::normalizeCurrency((string) $price['currency']);
        $period = (string) $price['period'];
        $perSeat = ($price['per_seat'] ?? false) === true;
        $label = sprintf('price %s %s/%s%s', $key, $currency, $period, $perSeat ? ' per seat' : '');

        foreach ($this->prices->listForPlan($planId) as $row) {
            if ($row['currency'] === $currency && $row['billing_period'] === $period
                && $row['is_per_seat'] === $perSeat && !$row['is_per_device']) {
                $report['skipped'][] = "{$label}: already exists";

                return;
            }
        }

        $this->prices->create($planId, $currency, (int) $price['amount'], $period, $perSeat);
        $report['added'][] = $label;
    }

    /**
     * @param array<string, mixed> $plan
     * @return list<string>
     */
    private static function planProblems(array $plan, string $key, string $where): array
    {
        $problems = [];

        foreach (array_diff(array_keys($plan), self::PLAN_FIELDS) as $unknown) {
            $problems[] = "{$where} has an unknown field \"{$unknown}\".";
        }

        try {
            PlanService::assertValidKey($key);
        } catch (PlanValidationException $e) {
            $problems[] = "{$where}: {$e->getMessage()}";
        }

        if (!is_string($plan['name'] ?? null) || trim($plan['name']) === '') {
            $problems[] = "{$where} needs a name.";
        }
        if (isset($plan['description']) && !is_string($plan['description'])) {
            $problems[] = "{$where}: description must be text.";
        }
        if (isset($plan['sort_order']) && !is_int($plan['sort_order'])) {
            $problems[] = "{$where}: sort_order must be a whole number.";
        }
        if (isset($plan['is_addon']) && !is_bool($plan['is_addon'])) {
            $problems[] = "{$where}: is_addon must be true or false.";
        }

        return array_merge(
            $problems,
            self::priceProblems($plan['prices'] ?? [], $where),
            self::limitProblems($plan['limits'] ?? [], $where),
        );
    }

    /**
     * @return list<string>
     */
    private static function priceProblems(mixed $prices, string $where): array
    {
        if (!is_array($prices) || !array_is_list($prices)) {
            return ["{$where}: prices must be a list."];
        }

        $problems = [];
        $terms = [];
        foreach ($prices as $index => $price) {
            $at = "{$where} prices[{$index}]";
            if (!is_array($price) || array_is_list($price)) {
                $problems[] = "{$at} must be an object.";
                continue;
            }

            foreach (array_diff(array_keys($price), self::PRICE_FIELDS) as $unknown) {
                $problems[] = "{$at} has an unknown field \"{$unknown}\".";
            }
            if (isset($price['per_seat']) && !is_bool($price['per_seat'])) {
                $problems[] = "{$at}: per_seat must be true or false.";
            }
            if (!is_int($price['amount'] ?? null)) {
                $problems[] = "{$at}: amount must be a whole number of minor units.";
                continue;
            }
            if (!is_string($price['currency'] ?? null) || !is_string($price['period'] ?? null)) {
                $problems[] = "{$at} needs a currency and a period.";
                continue;
            }

            $currency = PlanPriceRepository::normalizeCurrency($price['currency']);
            try {
                PlanPriceRepository::assertValid($currency, $price['amount'], $price['period']);
            } catch (PlanValidationException $e) {
                $problems[] = "{$at}: {$e->getMessage()}";
            }

            $term = $currency . '/' . $price['period'] . '/' . (($price['per_seat'] ?? false) === true ? 'seat' : 'flat');
            if (isset($terms[$term])) {
                $problems[] = "{$at} repeats the terms {$term}.";
            }
            $terms[$term] = true;
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function limitProblems(mixed $limits, string $where): array
    {
        if (!is_array($limits) || ($limits !== [] && array_is_list($limits))) {
            return ["{$where}: limits must be an object of entitlement key to value."];
        }

        $problems = [];
        foreach ($limits as $key => $value) {
            $key = (string) $key;
            if (!EntitlementRegistry::isKnown($key)) {
                $problems[] = "{$where}: unknown entitlement key \"{$key}\".";
                continue;
            }

            $text = self::limitText($value);
            if ($text === null) {
                $problems[] = "{$where}: {$key} must be a number or true/false.";
                continue;
            }

            $reason = EntitlementRegistry::validate($key, $text);
            if ($reason !== null) {
                $problems[] = "{$where}: {$reason}";
            }
        }

        return $problems;
    }

    /** @param array<string, mixed> $plan */
    private static function planKey(array $plan): string
    {
        return strtolower(trim((string) $plan['plan_key']));
    }

    private static function limitText(mixed $value): ?string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            default => null,
        };
    }
}
