<?php

declare(strict_types=1);

namespace Whity\Core\Plan;

use PDO;
use Whity\Core\Db\DbBool;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Money\Currency;
use Whity\Core\Money\MoneyException;

/**
 * The plans as whity.dev shows them (WS.5, `docs/design/sites/README.md`).
 *
 * TWO SOURCES. What a plan IS comes from the catalogue the operator already
 * keeps (ADR 0010): its name, whether it is active, its order, its monthly
 * price and its limits. How the website TALKS about it is a small marketing
 * layer, the global setting `website.plans` (JSON): the Arabic name, a
 * tagline, the features shown, which plan is highlighted, the button, and
 * whether a plan is shown at all. So changing a price in Whity changes the
 * website without a redeploy, and nothing about the website lives in `plans`.
 *
 * THE PAGE CHECKS EVERYTHING (`assets/plans.js`) and throws the whole answer
 * away when one plan breaks a rule, falling back to the plans built into the
 * page. So this only ever emits plans it would accept: an English name and
 * tagline under 120 characters, a monthly price as a decimal string in normal
 * units or null, BOTH limits as whole numbers, at most six features, and an
 * https button. A plan that cannot meet that (no tagline, an unlimited or unset
 * limit with no override, no https link) is left out and logged, rather than
 * taking every other plan down with it. An unlimited limit is set for the
 * website with the layer's `limits` (for example `{"staff": 1000}`).
 *
 * Marketing layer shape (every field optional):
 *
 *     {
 *       "currency": "JOD",
 *       "plans": {
 *         "team": {
 *           "visible": true, "highlight": true, "order": 2,
 *           "name": {"en": "Team", "ar": "الفريق"},
 *           "tagline": {"en": "For growing teams", "ar": "للفرق المتنامية"},
 *           "features": [{"en": "Your own web address and sign-in", "ar": "…"}],
 *           "limits": {"staff": 25, "customersPerMonth": 5000},
 *           "cta": {"label": {"en": "Start free", "ar": "ابدأ مجاناً"}, "href": "https://…"}
 *         }
 *       },
 *       "dedicatedHosting": {"fromMonthly": "150.000"}
 *     }
 */
final class WebsitePlans
{
    public const VERSION = 1;
    public const MAX_PLANS = 4;
    public const MAX_FEATURES = 6;
    /** plans.js accepts text shorter than 120 characters. */
    private const MAX_TEXT = 119;

    /** Contract limit name => entitlement key. */
    private const LIMITS = [
        'staff' => EntitlementRegistry::MEMBERS_MAX,
        'customersPerMonth' => EntitlementRegistry::CUSTOMERS_PER_MONTH,
    ];

    private const DEFAULT_CTA_LABEL = ['en' => 'Start free', 'ar' => 'ابدأ مجاناً'];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param string $marketing   The `website.plans` setting ('' when unset).
     * @param string $currency    The fallback currency (billing.default_currency).
     * @param string $appUrl      Where "Start free" leads (`{appUrl}/register?plan=…`).
     * @return array<string, mixed>
     */
    public function build(string $marketing, string $currency, string $appUrl): array
    {
        $layer = self::decode($marketing) ?? [];
        $currency = strtoupper(self::text($layer['currency'] ?? null) ?? $currency);
        try {
            $decimals = Currency::exponentFor($currency);
        } catch (MoneyException) {
            $currency = 'JOD';
            $decimals = Currency::exponentFor($currency);
        }
        $perPlan = is_array($layer['plans'] ?? null) ? $layer['plans'] : [];

        $stmt = $this->db->prepare(
            'SELECT id, plan_key, name, description, sort_order, is_addon, updated_at
               FROM plans WHERE is_active = :on
              ORDER BY sort_order ASC, id ASC'
        );
        $stmt->bindValue(':on', true, PDO::PARAM_BOOL);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $candidates = [];
        $updated = '';
        foreach ($rows as $row) {
            if (DbBool::of($row['is_addon'] ?? false)) {
                continue;
            }
            $key = (string) $row['plan_key'];
            $m = is_array($perPlan[$key] ?? null) ? $perPlan[$key] : [];
            if (($m['visible'] ?? true) === false) {
                continue;
            }
            $candidates[] = [
                'row' => $row,
                'm' => $m,
                'order' => is_int($m['order'] ?? null) ? $m['order'] : (int) $row['sort_order'],
            ];
            $updated = max($updated, (string) $row['updated_at']);
        }
        usort($candidates, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $plans = [];
        foreach ($candidates as $c) {
            $plan = $this->plan($c['row'], $c['m'], $currency, $appUrl);
            if ($plan === null) {
                error_log('[WebsitePlans] plan "' . $c['row']['plan_key'] . '" left out: it needs a tagline, both limits and an https link');
                continue;
            }
            $plans[] = $plan;
            if (count($plans) === self::MAX_PLANS) {
                break;
            }
        }

        $fromMonthly = self::amount(
            is_array($layer['dedicatedHosting'] ?? null) ? ($layer['dedicatedHosting']['fromMonthly'] ?? null) : null,
            $currency
        );

        return [
            'version' => self::VERSION,
            'currency' => $currency,
            'decimals' => $decimals,
            'updatedAt' => $updated !== '' ? substr($updated, 0, 10) : gmdate('Y-m-d'),
            'plans' => $plans,
            'dedicatedHosting' => ['fromMonthly' => $fromMonthly],
        ];
    }

    /**
     * Why a `website.plans` value cannot be saved, or null when it can. Only the
     * shape is refused; a plan key that does not exist yet is allowed, so the
     * website can be prepared before the plan is.
     */
    public static function problem(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }
        $layer = self::decode($value);
        if ($layer === null) {
            return 'website.plans must be a JSON object.';
        }
        if (isset($layer['plans']) && !is_array($layer['plans'])) {
            return 'website.plans: "plans" must be an object keyed by plan key.';
        }
        foreach ((array) ($layer['plans'] ?? []) as $key => $m) {
            if (!is_array($m)) {
                return "website.plans: plan \"{$key}\" must be an object.";
            }
            if (isset($m['features']) && (!is_array($m['features']) || count($m['features']) > self::MAX_FEATURES)) {
                return "website.plans: plan \"{$key}\" may show up to " . self::MAX_FEATURES . ' features.';
            }
            foreach ((array) ($m['features'] ?? []) as $feature) {
                if (self::localized($feature) === null) {
                    return "website.plans: every feature of \"{$key}\" needs an English text.";
                }
            }
            foreach ((array) ($m['limits'] ?? []) as $name => $n) {
                if (!isset(self::LIMITS[$name]) || !is_int($n) || $n < 0) {
                    return "website.plans: limits of \"{$key}\" are staff and customersPerMonth, as whole numbers.";
                }
            }
            $href = $m['cta']['href'] ?? null;
            if ($href !== null && self::httpsUrl($href) === null) {
                return "website.plans: the link of \"{$key}\" must start with https://.";
            }
        }
        $from = $layer['dedicatedHosting']['fromMonthly'] ?? null;
        if ($from !== null && (!is_string($from) || preg_match('/^\d+(\.\d+)?$/', $from) !== 1)) {
            return 'website.plans: dedicatedHosting.fromMonthly must be an amount like "150.000".';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $m
     * @return array<string, mixed>|null Null when the page would refuse it.
     */
    private function plan(array $row, array $m, string $currency, string $appUrl): ?array
    {
        $key = (string) $row['plan_key'];
        $name = self::localized($m['name'] ?? null) ?? ['en' => (string) $row['name']];
        $plan = [
            'id' => $key,
            'highlight' => ($m['highlight'] ?? false) === true,
            'name' => $name,
        ];
        $tagline = self::localized($m['tagline'] ?? null);
        if ($tagline === null && is_string($row['description'] ?? null) && trim($row['description']) !== '') {
            $tagline = ['en' => mb_substr(trim($row['description']), 0, self::MAX_TEXT)];
        }
        if ($tagline === null) {
            return null;
        }
        $plan['tagline'] = $tagline;
        $plan['price'] = ['monthly' => $this->monthlyPrice((int) $row['id'], $currency)];
        $limits = $this->limits((int) $row['id'], is_array($m['limits'] ?? null) ? $m['limits'] : []);
        if ($limits === null) {
            return null;
        }
        $plan['limits'] = $limits;

        $features = [];
        foreach (is_array($m['features'] ?? null) ? $m['features'] : [] as $feature) {
            $text = self::localized($feature);
            if ($text !== null) {
                $features[] = $text;
            }
        }
        $plan['features'] = array_slice($features, 0, self::MAX_FEATURES);

        $cta = is_array($m['cta'] ?? null) ? $m['cta'] : [];
        $href = self::httpsUrl($cta['href'] ?? null)
            ?? self::httpsUrl(rtrim($appUrl, '/') . '/register?plan=' . rawurlencode($key));
        if ($href === null) {
            return null;
        }
        $plan['cta'] = [
            'label' => self::localized($cta['label'] ?? null) ?? self::DEFAULT_CTA_LABEL,
            'href' => $href,
        ];

        return $plan;
    }

    /** The plan's active monthly price in normal units, or null when it has none. */
    private function monthlyPrice(int $planId, string $currency): ?string
    {
        // A flat price before a per-seat one: the card shows one number.
        $stmt = $this->db->prepare(
            "SELECT unit_amount FROM plan_prices
              WHERE plan_id = :plan AND currency = :cur AND billing_period = 'month' AND is_active = :on
              ORDER BY is_per_seat ASC, id ASC LIMIT 1"
        );
        $stmt->bindValue(':plan', $planId, PDO::PARAM_INT);
        $stmt->bindValue(':cur', $currency);
        $stmt->bindValue(':on', true, PDO::PARAM_BOOL);
        $stmt->execute();
        $minor = $stmt->fetchColumn();
        if ($minor === false || $minor === null) {
            return null;
        }

        return self::normalUnits((int) $minor, $currency);
    }

    /**
     * Both limits, from the plan's bundle or the layer's override; null when one
     * is unlimited or unset with no override.
     *
     * @param array<string, mixed> $override
     * @return array<string, int>|null
     */
    private function limits(int $planId, array $override): ?array
    {
        $stmt = $this->db->prepare('SELECT entitlement_key, value FROM plan_entitlements WHERE plan_id = :plan');
        $stmt->execute([':plan' => $planId]);
        $values = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $values[(string) $r['entitlement_key']] = trim((string) $r['value']);
        }
        $out = [];
        foreach (self::LIMITS as $name => $entitlement) {
            if (is_int($override[$name] ?? null) && $override[$name] >= 0) {
                $out[$name] = $override[$name];
                continue;
            }
            $v = $values[$entitlement] ?? null;
            if ($v === null || !ctype_digit($v)) {
                return null;
            }
            $out[$name] = (int) $v;
        }

        return $out;
    }

    private static function normalUnits(int $minor, string $currency): string
    {
        // Currency::format() appends the code; the contract wants the number only.
        return explode(' ', Currency::format($minor, $currency))[0];
    }

    /** A typed amount ("150", "150.000") as normal units with the currency's decimals. */
    private static function amount(mixed $value, string $currency): ?string
    {
        if (!is_string($value) || preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            return null;
        }
        try {
            return self::normalUnits(Currency::parse($value, $currency), $currency);
        } catch (MoneyException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $value): ?array
    {
        if (trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        // An object; `{}` decodes to [] and is fine, a JSON list is not.
        return is_array($decoded) && ($decoded === [] || !array_is_list($decoded)) ? $decoded : null;
    }

    /** @return array{en: string, ar?: string}|null */
    private static function localized(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = ['en' => $value];
        }
        if (!is_array($value)) {
            return null;
        }
        $en = self::text($value['en'] ?? null);
        if ($en === null) {
            return null;
        }
        $out = ['en' => $en];
        $ar = self::text($value['ar'] ?? null);
        if ($ar !== null) {
            $out['ar'] = $ar;
        }

        return $out;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, self::MAX_TEXT);
    }

    private static function httpsUrl(mixed $value): ?string
    {
        if (!is_string($value) || !str_starts_with($value, 'https://') || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $value;
    }
}
