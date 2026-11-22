<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use Whity\Core\Db\DbBool;
use Whity\Core\i18n\LocalizedText;

/**
 * Tax codes and the arithmetic of a document line (WHIT-1318 A2a, design
 * section 32 §7.1, register AC.17–AC.20).
 *
 * A code carries a rate in basis points (1600 = 16%), a direction (`output`
 * on sales, `input` on purchases), a treatment (`standard`, `reduced`,
 * `zero`, `exempt`, ...) and the account its tax posts to. Items say their
 * `tax_category` (empty or `standard`, `reduced_8`, `zero`, `exempt`...);
 * {@see self::codeFor()} turns that into the code valid on the date.
 *
 * ROUNDING happens once per line, half away from zero, to whole minor units
 * (JoFotara's rule, to be confirmed in the review, Q11): a line's tax is
 * round(net × rate / 10000); with prices that include tax, net is
 * round(gross × 10000 / (10000 + rate)) and tax is gross − net, so the line
 * total is always exactly what was typed.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class Taxes
{
    public const DIRECTIONS = ['output', 'input'];

    private const COLUMNS = 'id, code, name, names, pack, direction, rate_bp, treatment, category, account_id, return_box,
        einvoice_category, valid_from, valid_to, is_seeded, owner_plugin, archived_at';

    public function __construct(
        private readonly AccountingDb $db,
        private readonly AccountingWorkspace $workspace,
    ) {
    }

    /**
     * @param array{direction?: string, include_archived?: bool} $filter
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, array $filter = []): array
    {
        $this->workspace->ensure($tenantId);
        $where = 'tenant_id = :tenant_id';
        $params = [':tenant_id' => $tenantId];
        if (isset($filter['direction'])) {
            if (!in_array($filter['direction'], self::DIRECTIONS, true)) {
                throw AccountingRejectedException::field('direction', 'direction is output or input');
            }
            $where .= ' AND direction = :direction';
            $params[':direction'] = $filter['direction'];
        }
        if (($filter['include_archived'] ?? false) !== true) {
            $where .= ' AND archived_at IS NULL';
        }

        return array_map(
            self::present(...),
            $this->db->all('SELECT ' . self::COLUMNS . ' FROM acc_tax_codes WHERE ' . $where . ' ORDER BY direction DESC, rate_bp DESC, code', $params)
        );
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $id): array
    {
        $this->workspace->ensure($tenantId);
        $row = $this->db->one('SELECT ' . self::COLUMNS . ' FROM acc_tax_codes WHERE tenant_id = :tenant_id AND id = :id', [':tenant_id' => $tenantId, ':id' => $id]);
        if ($row === null) {
            throw AccountingRejectedException::notFound('Tax code');
        }

        return self::present($row);
    }

    /**
     * The code for an item's tax category, in a direction, valid on a date. An
     * empty category is the standard rate. Null when the pack has none.
     *
     * @return array<string, mixed>|null
     */
    public function codeFor(int $tenantId, string $category, string $direction, string $date): ?array
    {
        $this->workspace->ensure($tenantId);
        $category = strtolower(trim($category));
        if ($category === '') {
            $category = 'standard';
        }
        $row = $this->db->one(
            'SELECT ' . self::COLUMNS . ' FROM acc_tax_codes
              WHERE tenant_id = :tenant_id AND direction = :direction AND category = :category AND archived_at IS NULL
                AND (valid_from IS NULL OR valid_from <= :date) AND (valid_to IS NULL OR valid_to >= :date2)
              ORDER BY valid_from DESC, id LIMIT 1',
            [':tenant_id' => $tenantId, ':direction' => $direction, ':category' => $category, ':date' => $date, ':date2' => $date]
        );

        return $row === null ? null : self::present($row);
    }

    /**
     * A usable code by id or by code, in a direction, valid on a date.
     *
     * @return array<string, mixed>
     */
    public function usable(int $tenantId, mixed $ref, string $direction, string $date, string $field): array
    {
        $this->workspace->ensure($tenantId);
        $row = null;
        if (is_int($ref)) {
            $row = $this->db->one('SELECT ' . self::COLUMNS . ' FROM acc_tax_codes WHERE tenant_id = :tenant_id AND id = :id', [':tenant_id' => $tenantId, ':id' => $ref]);
        } elseif (is_string($ref) && $ref !== '') {
            $row = $this->db->one('SELECT ' . self::COLUMNS . ' FROM acc_tax_codes WHERE tenant_id = :tenant_id AND code = :code', [':tenant_id' => $tenantId, ':code' => $ref]);
        }
        if ($row === null || $row['archived_at'] !== null) {
            throw AccountingRejectedException::field($field, $field . ' is not a tax code here');
        }
        if ($row['direction'] !== $direction) {
            throw AccountingRejectedException::field($field, $field . ' is a tax code for ' . ($direction === 'output' ? 'purchases' : 'sales') . ', not for this document');
        }
        $from = is_string($row['valid_from']) ? substr($row['valid_from'], 0, 10) : null;
        $to = is_string($row['valid_to']) ? substr($row['valid_to'], 0, 10) : null;
        if (($from !== null && $from > $date) || ($to !== null && $to < $date)) {
            throw AccountingRejectedException::field($field, $field . ' (' . $row['code'] . ') is not valid on ' . $date);
        }

        return self::present($row);
    }

    // ── changing the codes (accounting:settings) ─────────────────────────

    public const TREATMENTS = ['standard', 'reduced', 'zero', 'exempt', 'out_of_scope', 'reverse_charge'];

    /**
     * Adds a code. A rate that changes is a new code from its `valid_from`
     * date, never an edited rate (AC.19), so documents already issued keep
     * the rate they were taxed at.
     *
     * @param array<string, mixed> $body `code`, `name` (text or `{en, ar}`), `direction`, `rate_bp`, `treatment`, `category`, `account_id`, `valid_from`, `valid_to`
     * @return array<string, mixed>
     */
    public function create(int $tenantId, array $body): array
    {
        $this->workspace->ensure($tenantId);
        $code = is_string($body['code'] ?? null) ? strtoupper(trim($body['code'])) : '';
        if (preg_match('/^[A-Z0-9][A-Z0-9_.-]{0,29}$/', $code) !== 1) {
            throw AccountingRejectedException::field('code', 'code is up to 30 letters, digits, dots, dashes or underscores, such as JO-GST-8');
        }
        [$name, $names] = self::name($body['name'] ?? null);
        $direction = $body['direction'] ?? null;
        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw AccountingRejectedException::field('direction', 'direction is output (sales) or input (purchases)');
        }
        $rate = $body['rate_bp'] ?? null;
        if (!is_int($rate) || $rate < 0 || $rate > 10000) {
            throw AccountingRejectedException::field('rate_bp', 'rate_bp is basis points, 0 to 10000 (1600 is 16%)');
        }
        $treatment = $body['treatment'] ?? ($rate > 0 ? 'standard' : 'zero');
        if (!in_array($treatment, self::TREATMENTS, true)) {
            throw AccountingRejectedException::field('treatment', 'treatment is one of ' . implode(', ', self::TREATMENTS));
        }
        $category = is_string($body['category'] ?? null) ? strtolower(trim($body['category'])) : '';
        if ($category !== '' && preg_match('/^[a-z0-9_]{1,40}$/', $category) !== 1) {
            throw AccountingRejectedException::field('category', 'category is a tax category such as standard, reduced_8, zero or exempt');
        }
        $from = isset($body['valid_from']) ? Periods::date($body['valid_from'], 'valid_from') : null;
        $to = isset($body['valid_to']) ? Periods::date($body['valid_to'], 'valid_to') : null;
        if ($from !== null && $to !== null && $to < $from) {
            throw AccountingRejectedException::field('valid_to', 'valid_to cannot be before valid_from');
        }
        $accountId = $this->taxAccount($tenantId, $body['account_id'] ?? null, (string) $direction);
        try {
            $id = $this->db->insert(
                'INSERT INTO acc_tax_codes (tenant_id, code, name, names, pack, direction, rate_bp, treatment, category, account_id,
                        valid_from, valid_to)
                 VALUES (:tenant_id, :code, :name, :names, :pack, :direction, :rate, :treatment, :category, :account_id, :from, :to)',
                [
                    ':tenant_id' => $tenantId, ':code' => $code, ':name' => $name, ':names' => $names,
                    ':pack' => $this->workspace->books($tenantId)['tax_pack'], ':direction' => $direction, ':rate' => $rate,
                    ':treatment' => $treatment, ':category' => $category, ':account_id' => $accountId, ':from' => $from, ':to' => $to,
                ]
            );
        } catch (\PDOException $e) {
            if (AccountingDb::isUniqueViolation($e)) {
                throw AccountingRejectedException::coded('conflict', 'There is already a tax code ' . $code, 409, 'code');
            }
            throw $e;
        }

        return $this->get($tenantId, $id);
    }

    /**
     * Changes what a code is called, where its tax goes, its category and
     * when it stops: never its rate, direction or treatment, which issued
     * documents were taxed at.
     *
     * @param array<string, mixed> $body `name`, `account_id`, `category`, `valid_to`
     * @return array<string, mixed>
     */
    public function update(int $tenantId, int $id, array $body): array
    {
        $current = $this->get($tenantId, $id);
        foreach (['rate_bp', 'direction', 'treatment', 'code'] as $frozen) {
            if (array_key_exists($frozen, $body) && $body[$frozen] !== $current[$frozen]) {
                throw AccountingRejectedException::field(
                    $frozen,
                    $frozen === 'rate_bp'
                        ? 'A rate never changes: add a new code from the date the new rate starts, and end this one with valid_to'
                        : $frozen . ' cannot change; add a new code instead'
                );
            }
        }
        $sets = [];
        $params = [':tenant_id' => $tenantId, ':id' => $id];
        if (array_key_exists('name', $body)) {
            [$params[':name'], $params[':names']] = self::name($body['name']);
            $sets[] = 'name = :name, names = :names';
        }
        if (array_key_exists('account_id', $body)) {
            $params[':account_id'] = $this->taxAccount($tenantId, $body['account_id'], (string) $current['direction']);
            $sets[] = 'account_id = :account_id';
        }
        if (array_key_exists('category', $body)) {
            $category = is_string($body['category']) ? strtolower(trim($body['category'])) : null;
            if ($category === null || ($category !== '' && preg_match('/^[a-z0-9_]{1,40}$/', $category) !== 1)) {
                throw AccountingRejectedException::field('category', 'category is a tax category such as standard, reduced_8, zero or exempt');
            }
            $params[':category'] = $category;
            $sets[] = 'category = :category';
        }
        if (array_key_exists('valid_to', $body)) {
            $to = $body['valid_to'] === null ? null : Periods::date($body['valid_to'], 'valid_to');
            if ($to !== null && $current['valid_from'] !== null && $to < $current['valid_from']) {
                throw AccountingRejectedException::field('valid_to', 'valid_to cannot be before valid_from');
            }
            $params[':valid_to'] = $to;
            $sets[] = 'valid_to = :valid_to';
        }
        if ($sets !== []) {
            $this->db->run('UPDATE acc_tax_codes SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tenant_id AND id = :id', $params);
        }

        return $this->get($tenantId, $id);
    }

    /**
     * Archives a code: new lines can't use it; documents that did keep it.
     *
     * @return array<string, mixed>
     */
    public function archive(int $tenantId, int $id, bool $archived = true): array
    {
        $this->get($tenantId, $id);
        $this->db->run(
            'UPDATE acc_tax_codes SET archived_at = ' . ($archived ? 'CURRENT_TIMESTAMP' : 'NULL') . ' WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        );

        return $this->get($tenantId, $id);
    }

    /** A tax account: given, or the role account for the direction. */
    private function taxAccount(int $tenantId, mixed $id, string $direction): int
    {
        if ($id === null) {
            return $this->workspace->roleAccountId($tenantId, $direction === 'output' ? 'sales_tax_output' : 'sales_tax_input');
        }
        $row = is_int($id) ? $this->db->one(
            'SELECT id, is_group, archived_at, type FROM acc_accounts WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        ) : null;
        if ($row === null || $row['archived_at'] !== null || DbBool::of($row['is_group']) || !in_array($row['type'], ['asset', 'liability'], true)) {
            throw AccountingRejectedException::field('account_id', 'account_id is an asset or liability account here that takes lines');
        }

        return (int) $row['id'];
    }

    /** @return array{0: string, 1: string} the original text and the LocalizedText JSON */
    private static function name(mixed $raw): array
    {
        $texts = is_string($raw) ? ['en' => $raw] : (is_array($raw) ? $raw : []);
        $clean = [];
        foreach ($texts as $lang => $text) {
            if (is_string($lang) && is_string($text) && trim($text) !== '' && preg_match('/^[a-z]{2}$/', $lang) === 1) {
                $clean[$lang] = mb_substr(trim($text), 0, 120);
            }
        }
        if ($clean === []) {
            throw AccountingRejectedException::field('name', 'name is text, or {"en": ..., "ar": ...}');
        }

        return [$clean['en'] ?? (string) reset($clean), LocalizedText::encode($clean)];
    }

    // ── arithmetic ───────────────────────────────────────────────────────

    /**
     * A line's amount before tax treatment: quantity × price, less its
     * discount, each rounded half away from zero.
     */
    public static function lineAmount(int $steps, int $decimals, int $priceMinor, int $discountBp): int
    {
        $gross = self::divRound($steps * $priceMinor, 10 ** $decimals);

        return $gross - self::divRound($gross * $discountBp, 10000);
    }

    /**
     * Net, tax and total of a line amount at a rate.
     *
     * @return array{net: int, tax: int, total: int}
     */
    public static function split(int $amount, int $rateBp, bool $inclusive): array
    {
        if ($inclusive) {
            $net = self::divRound($amount * 10000, 10000 + $rateBp);

            return ['net' => $net, 'tax' => $amount - $net, 'total' => $amount];
        }
        $tax = self::divRound($amount * $rateBp, 10000);

        return ['net' => $amount, 'tax' => $tax, 'total' => $amount + $tax];
    }

    /** $n / $d rounded half away from zero; $d above 0. */
    public static function divRound(int $n, int $d): int
    {
        $q = intdiv(abs($n) * 2 + $d, $d * 2);

        return $n < 0 ? -$q : $q;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'names' => LocalizedText::decode((string) $row['names']),
            'pack' => (string) $row['pack'],
            'direction' => (string) $row['direction'],
            'rate_bp' => (int) $row['rate_bp'],
            'treatment' => (string) $row['treatment'],
            'category' => (string) $row['category'],
            'account_id' => AccountingDb::int($row['account_id']),
            'return_box' => (string) $row['return_box'],
            'einvoice_category' => (string) $row['einvoice_category'],
            'valid_from' => is_string($row['valid_from']) ? substr($row['valid_from'], 0, 10) : null,
            'valid_to' => is_string($row['valid_to']) ? substr($row['valid_to'], 0, 10) : null,
            'is_seeded' => DbBool::of($row['is_seeded']),
            'owner_plugin' => $row['owner_plugin'] === null ? null : (string) $row['owner_plugin'],
            'archived' => $row['archived_at'] !== null,
        ];
    }
}
