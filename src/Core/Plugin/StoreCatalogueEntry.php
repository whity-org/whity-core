<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

/**
 * What a store's catalogue may say about a plugin (WHIT-870).
 *
 * A catalogue is unsigned JSON from an allowlisted host: good enough to display,
 * never good enough to decide anything. So only the fields below get through,
 * each checked for shape, and `trust` is not one of them: a store can't claim a
 * mark. It's the host's answer, and until the Verified design is approved
 * (docs/design/VERIFIED-PLUGINS-SECURITY.md) that answer is always "unverified".
 */
final class StoreCatalogueEntry
{
    public const TRUST_UNVERIFIED = 'unverified';

    private const MAX_LIST = 50;
    private const MAX_CHANGELOG = 20;

    /**
     * @param array<string, mixed> $raw One catalogue entry as the store sent it.
     * @return array<string, mixed>|null Null when it has no usable slug and name.
     */
    public static function normalize(array $raw): ?array
    {
        $slug = self::text($raw['slug'] ?? null, 128);
        $name = self::text($raw['name'] ?? null, 200);
        if ($slug === null || $name === null || preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $slug) !== 1) {
            return null;
        }

        return [
            'slug' => $slug,
            'name' => $name,
            'description' => self::text($raw['description'] ?? null, 2000) ?? '',
            'author' => self::text($raw['author'] ?? null, 200) ?? '',
            'tags' => self::textList($raw['tags'] ?? null, 64),
            'latest_version' => self::text($raw['latest_version'] ?? null, 64),
            'trust' => self::TRUST_UNVERIFIED,
            // Decides only which install path is offered; each path checks for itself.
            'kind' => ($raw['kind'] ?? null) === 'declarative' ? 'declarative' : 'code',
            'price' => self::price($raw['price'] ?? null),
            // Store licensing (PR-4): every way to buy it, and whether it needs a licence at all.
            'paid' => ($raw['paid'] ?? false) === true,
            'prices' => self::prices($raw['prices'] ?? null),
            'used_by' => is_int($raw['used_by'] ?? null) && $raw['used_by'] >= 0 ? $raw['used_by'] : null,
            'updated_at' => self::date($raw['updated_at'] ?? null),
            'adds' => self::adds($raw['adds'] ?? null),
            'permissions' => self::permissions($raw['permissions'] ?? null),
            'outside_domains' => self::domains($raw['outside_domains'] ?? null),
            'languages' => self::textList($raw['languages'] ?? null, 16),
            'support_url' => self::httpsUrl($raw['support_url'] ?? null),
            'changelog' => self::changelog($raw['changelog'] ?? null),
        ];
    }

    /** @return list<string> */
    public static function domains(mixed $value): array
    {
        $domains = [];
        foreach (self::textList($value, 253) as $domain) {
            $lower = strtolower($domain);
            if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $lower) === 1) {
                $domains[] = $lower;
            }
        }

        return array_values(array_unique($domains));
    }

    /** @return list<string> */
    private static function permissions(mixed $value): array
    {
        return array_values(array_filter(
            self::textList($value, 128),
            static fn (string $slug): bool => preg_match('/^[a-z][a-z0-9_-]*(:[a-z][a-z0-9_-]*)+$/', $slug) === 1
        ));
    }

    /**
     * @return list<array{plan: string, period: string, currency: string, amount: string, trial_days: int|null}>
     */
    private static function prices(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $prices = [];
        foreach (array_slice($value, 0, 12) as $price) {
            if (!is_array($price)) {
                continue;
            }
            $plan = $price['plan'] ?? null;
            $period = $price['period'] ?? null;
            $currency = $price['currency'] ?? null;
            $amount = $price['amount'] ?? null;
            $trial = $price['trial_days'] ?? null;
            if (!is_string($plan) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $plan) !== 1
                || !in_array($period, ['month', 'year', 'once'], true)
                || !is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1
                || !is_string($amount) || preg_match('/^\d{1,12}(\.\d{1,3})?$/', $amount) !== 1
            ) {
                continue;
            }
            $prices[] = [
                'plan' => $plan,
                'period' => $period,
                'currency' => $currency,
                'amount' => $amount,
                'trial_days' => is_int($trial) && $trial > 0 && $trial <= 365 ? $trial : null,
            ];
        }

        return $prices;
    }

    /** @return array{amount: string, currency: string, interval: string|null}|null */
    private static function price(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $amount = $value['amount'] ?? null;
        $currency = $value['currency'] ?? null;
        $interval = $value['interval'] ?? null;
        if (!is_string($amount) || preg_match('/^\d{1,12}(\.\d{1,3})?$/', $amount) !== 1) {
            return null;
        }
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return null;
        }

        return [
            'amount' => $amount,
            'currency' => $currency,
            'interval' => in_array($interval, ['month', 'year'], true) ? $interval : null,
        ];
    }

    /** @return array{screens: int, document_blocks: int, agent_tools: int, fields: int} */
    private static function adds(mixed $value): array
    {
        $count = static fn (string $key): int => is_array($value) && is_int($value[$key] ?? null) && $value[$key] > 0
            ? $value[$key]
            : 0;

        return [
            'screens' => $count('screens'),
            'document_blocks' => $count('document_blocks'),
            'agent_tools' => $count('agent_tools'),
            'fields' => $count('fields'),
        ];
    }

    /** @return list<array{version: string, date: string|null, notes: string}> */
    private static function changelog(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $entries = [];
        foreach (array_slice(array_values($value), 0, self::MAX_CHANGELOG) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $version = self::text($entry['version'] ?? null, 64);
            if ($version === null) {
                continue;
            }
            $entries[] = [
                'version' => $version,
                'date' => self::date($entry['date'] ?? null),
                'notes' => self::text($entry['notes'] ?? null, 2000) ?? '',
            ];
        }

        return $entries;
    }

    private static function httpsUrl(mixed $value): ?string
    {
        $url = self::text($value, 500);
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? $url : null;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? null : gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $max);
    }

    /** @return list<string> */
    private static function textList(mixed $value, int $max): array
    {
        if (!is_array($value)) {
            return [];
        }
        $items = [];
        foreach (array_slice(array_values($value), 0, self::MAX_LIST) as $item) {
            $text = self::text($item, $max);
            if ($text !== null) {
                $items[] = $text;
            }
        }

        return $items;
    }
}
