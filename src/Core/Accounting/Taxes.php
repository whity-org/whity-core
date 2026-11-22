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
 * `tax_category` (empty or `standard`, `reduced_10`, `zero`, `exempt`...);
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
