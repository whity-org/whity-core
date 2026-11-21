<?php

declare(strict_types=1);

namespace Whity\Core\Sales;

use Whity\Core\Db\DbBool;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\Security\PublicToken;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\Inventory\Quantity;
use Whity\Sdk\Sales\QuoteSnapshot;
use Whity\Sdk\Sales\QuoteSnapshotLine;
use Whity\Sdk\Sql\SequenceAllocator;

/**
 * Quotes to customers (WHIT-1321, design section 34 §3, register QT.1-QT.12).
 *
 * 1. Draft: a customer (a contact marked as one), a valid-until date (today
 *    plus `sales.quote_valid_days`, 30, by default) and lines. A line is an
 *    item (any kind, services included; its price, name and tax category come
 *    from the item when not given) or a free line with only a description.
 * 2. Approval, when the workspace chose a quote route
 *    (`sales.quote_route_template_id`): `submit()` sends it on its route;
 *    approved, it is `ready`. With no route, `send()` takes a draft straight
 *    out.
 * 3. Send: `sales.quote.sending` may refuse it; the quote takes its number
 *    (`QUO-0318`) on first send and a fresh link for the customer. The raw
 *    link is answered once; only its hash is kept (QT.5).
 * 4. The customer accepts (typing their name) or declines on the public page,
 *    or a person records what they said. Past its valid-until date it is
 *    `expired` (a daily job, or the first look at it).
 * 5. A changed sent quote is a revision: the same number, the next revision,
 *    a draft until it is sent, when the one before becomes `replaced`.
 * 6. Accepted, it turns into whatever {@see QuoteConversions} offers (QT.6),
 *    each target once.
 *
 * MONEY is minor units in the quote's currency (CU.1-CU.4). A line is its
 * quantity times its price, less its discount. Tax is worked out per rate on
 * the sum of that rate's lines, so the printed tax is the tax on the total;
 * with prices that include tax the net is split out of each rate's sum.
 *
 * TENANT-OWNED: every statement binds `tenant_id`, except the one that
 * resolves a customer's link to its workspace ({@see byToken()}).
 */
final class Quotes
{
    public const STATUSES = ['draft', 'waiting_approval', 'ready', 'sent', 'accepted', 'declined', 'expired', 'converted', 'cancelled', 'replaced'];
    public const MAX_LINES = 300;

    private const COLUMNS = 'q.id, q.number, q.revision, q.replaces_id, q.status, q.contact_id, q.issue_date, q.valid_until, q.currency,
        q.prices_include_tax, q.subtotal_minor, q.tax_minor, q.total_minor, q.notes, q.terms, q.route_id, q.document_id, q.source_plugin,
        q.created_by_profile_id, q.agent_id, q.on_behalf_of_profile_id, q.approved_by_profile_id, q.decided_by_name, q.decided_by_profile_id,
        q.decided_online, q.decline_reason, q.submitted_at, q.approved_at, q.sent_at, q.decided_at, q.converted_at, q.cancelled_at,
        q.created_at, q.updated_at';

    /** Free lines keep up to three decimals ("2.5 hours"). */
    private const FREE_DECIMALS = 3;

    /**
     * @param \Closure(int): array<string, mixed> $settings the workspace's effective settings
     * @param \Closure(int): string $defaultCurrency the workspace's currency
     * @param \Closure(): \DateTimeImmutable|null $clock
     */
    public function __construct(
        private readonly InventoryDb $db,
        private readonly InventoryWorkspace $workspace,
        private readonly ItemService $items,
        private readonly UnitCatalog $units,
        private readonly SequenceAllocator $sequences,
        private readonly QuoteConversions $conversions,
        private readonly \Closure $settings,
        private readonly \Closure $defaultCurrency,
        private readonly ?HookManager $hooks = null,
        private ?QuoteApprovals $approvals = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /** Wired after construction: the routes need the route subject, which needs this. */
    public function useApprovals(QuoteApprovals $approvals): void
    {
        $this->approvals = $approvals;
    }

    // ── drafting ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data `contact_id`, `valid_until`, `currency`, `prices_include_tax`, `notes`, `terms`, `lines` (each `variant_id` or `description`, `quantity`, `unit`, `price_minor`, `discount_bp`, `tax_bp`, `tax_category`)
     * @param array{agent_id?: ?int, on_behalf_of?: ?int, idempotency_key?: ?string, source_plugin?: ?string} $meta
     * @return array{quote: array<string, mixed>, repeated: bool}
     */
    public function create(int $tenantId, ?int $actor, array $data, array $meta = []): array
    {
        $key = $meta['idempotency_key'] ?? null;
        if ($key !== null && ($key === '' || strlen($key) > 128)) {
            throw SalesRejectedException::field('idempotency_key', 'idempotency_key is text of up to 128 characters');
        }
        if (is_string($key)) {
            $done = $this->db->one('SELECT id FROM sales_quotes WHERE tenant_id = :tenant_id AND idempotency_key = :key', [':tenant_id' => $tenantId, ':key' => $key]);
            if ($done !== null) {
                return ['quote' => $this->get($tenantId, (int) $done['id']), 'repeated' => true];
            }
        }
        $head = $this->head($tenantId, $data);
        $lines = $this->lines($tenantId, $data['lines'] ?? null);

        $id = $this->db->transaction(function () use ($tenantId, $actor, $head, $lines, $meta, $key): int {
            $id = $this->db->insert(
                "INSERT INTO sales_quotes (tenant_id, status, contact_id, valid_until, currency, prices_include_tax, notes, terms,
                        idempotency_key, source_plugin, created_by_profile_id, agent_id, on_behalf_of_profile_id)
                 VALUES (:tenant_id, 'draft', :contact_id, :valid_until, :currency, :inclusive, :notes, :terms,
                        :key, :plugin, :actor, :agent_id, :on_behalf_of)",
                [
                    ':tenant_id' => $tenantId, ':contact_id' => $head['contact_id'], ':valid_until' => $head['valid_until'],
                    ':currency' => $head['currency'], ':inclusive' => $head['prices_include_tax'], ':notes' => $head['notes'],
                    ':terms' => $head['terms'], ':key' => $key,
                    ':plugin' => is_string($meta['source_plugin'] ?? null) ? $meta['source_plugin'] : null, ':actor' => $actor,
                    ':agent_id' => InventoryDb::int($meta['agent_id'] ?? null), ':on_behalf_of' => InventoryDb::int($meta['on_behalf_of'] ?? null),
                ]
            );
            $this->writeLines($tenantId, $id, $lines, $head['prices_include_tax']);
            $this->event(Events::SALES_QUOTE_CREATED, $tenantId, $id, $actor);

            return $id;
        });

        return ['quote' => $this->get($tenantId, $id), 'repeated' => false];
    }

    /**
     * Changes a draft: any head field, and its lines (the whole list).
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function update(int $tenantId, int $id, array $changes): array
    {
        $quote = $this->row($tenantId, $id) ?? throw SalesRejectedException::notFound('Quote');
        if ($quote['status'] !== 'draft') {
            throw new SalesRejectedException('Only a draft can be changed. To change a sent quote, make a new revision.', 409);
        }
        $head = $this->head($tenantId, $changes + [
            'contact_id' => (int) $quote['contact_id'], 'valid_until' => substr((string) $quote['valid_until'], 0, 10),
            'currency' => $quote['currency'], 'prices_include_tax' => DbBool::of($quote['prices_include_tax']),
            'notes' => $quote['notes'], 'terms' => $quote['terms'],
        ]);
        $lines = array_key_exists('lines', $changes) ? $this->lines($tenantId, $changes['lines']) : null;

        $this->db->transaction(function () use ($tenantId, $id, $head, $lines): void {
            $this->db->run(
                'UPDATE sales_quotes SET contact_id = :contact_id, valid_until = :valid_until, currency = :currency, prices_include_tax = :inclusive,
                        notes = :notes, terms = :terms, updated_at = CURRENT_TIMESTAMP
                  WHERE tenant_id = :tenant_id AND id = :id',
                [
                    ':contact_id' => $head['contact_id'], ':valid_until' => $head['valid_until'], ':currency' => $head['currency'],
                    ':inclusive' => $head['prices_include_tax'], ':notes' => $head['notes'], ':terms' => $head['terms'],
                    ':tenant_id' => $tenantId, ':id' => $id,
                ]
            );
            if ($lines === null) {
                $lines = $this->storedLines($tenantId, $id);
            }
            $this->db->run('DELETE FROM sales_quote_lines WHERE tenant_id = :tenant_id AND quote_id = :id', [':tenant_id' => $tenantId, ':id' => $id]);
            $this->writeLines($tenantId, $id, $lines, $head['prices_include_tax']);
        });

        return $this->get($tenantId, $id);
    }

    /**
     * A new revision of a sent, declined or expired quote: a draft copy with
     * the same number and the next revision. The one before stays as it was
     * until the revision is sent. Only one draft revision at a time.
     *
     * @return array<string, mixed>
     */
    public function revise(int $tenantId, int $id, ?int $actor): array
    {
        $quote = $this->row($tenantId, $id) ?? throw SalesRejectedException::notFound('Quote');
        $this->expireIfDue($tenantId, $quote);
        $quote = $this->row($tenantId, $id) ?? throw SalesRejectedException::notFound('Quote');
        if (!in_array($quote['status'], ['sent', 'declined', 'expired'], true)) {
            throw new SalesRejectedException(match ($quote['status']) {
                'draft', 'waiting_approval', 'ready' => 'This quote has not been sent yet; change it as it is',
                'accepted', 'converted' => 'The customer has accepted this quote; it can no longer change',
                default => 'This quote can no longer change',
            }, 409);
        }
        $open = $this->db->one(
            "SELECT id FROM sales_quotes WHERE tenant_id = :tenant_id AND number = :number AND status IN ('draft', 'waiting_approval', 'ready')",
            [':tenant_id' => $tenantId, ':number' => $quote['number']]
        );
        if ($open !== null) {
            return $this->get($tenantId, (int) $open['id']);
        }
        $next = (int) ($this->db->one(
            'SELECT MAX(revision) AS r FROM sales_quotes WHERE tenant_id = :tenant_id AND number = :number',
            [':tenant_id' => $tenantId, ':number' => $quote['number']]
        )['r'] ?? 1) + 1;
        $validUntil = $this->today()->modify('+' . $this->validDays($tenantId) . ' days')->format('Y-m-d');
        $lines = $this->storedLines($tenantId, $id);

        $newId = $this->db->transaction(function () use ($tenantId, $actor, $quote, $next, $validUntil, $lines): int {
            $newId = $this->db->insert(
                "INSERT INTO sales_quotes (tenant_id, number, revision, replaces_id, status, contact_id, valid_until, currency, prices_include_tax,
                        notes, terms, created_by_profile_id)
                 VALUES (:tenant_id, :number, :revision, :replaces, 'draft', :contact_id, :valid_until, :currency, :inclusive, :notes, :terms, :actor)",
                [
                    ':tenant_id' => $tenantId, ':number' => $quote['number'], ':revision' => $next, ':replaces' => (int) $quote['id'],
                    ':contact_id' => (int) $quote['contact_id'], ':valid_until' => $validUntil, ':currency' => $quote['currency'],
                    ':inclusive' => DbBool::of($quote['prices_include_tax']), ':notes' => $quote['notes'], ':terms' => $quote['terms'],
                    ':actor' => $actor,
                ]
            );
            $this->writeLines($tenantId, $newId, $lines, DbBool::of($quote['prices_include_tax']));
            $this->event(Events::SALES_QUOTE_CREATED, $tenantId, $newId, $actor);

            return $newId;
        });

        return $this->get($tenantId, $newId);
    }

    // ── approval ─────────────────────────────────────────────────────────

    /**
     * Sends a draft on its route. With no route chosen there is nothing to
     * approve: it is ready at once.
     *
     * @return array<string, mixed>
     */
    public function submit(int $tenantId, int $id, ?int $actor): array
    {
        $quote = $this->row($tenantId, $id) ?? throw SalesRejectedException::notFound('Quote');
        if ($quote['status'] !== 'draft') {
            throw new SalesRejectedException('Only a draft can be sent for approval', 409);
        }

        return $this->db->transaction(function () use ($tenantId, $id, $actor, $quote): array {
            $this->db->run(
                "UPDATE sales_quotes SET status = 'waiting_approval', submitted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                  WHERE tenant_id = :tenant_id AND id = :id AND status = 'draft'",
                [':tenant_id' => $tenantId, ':id' => $id]
            );
            $routeId = $this->approvals?->issue($tenantId, $actor, [
                'id' => $id, 'number' => $quote['number'], 'contact_name' => $this->contactName($tenantId, (int) $quote['contact_id']),
            ]);
            if ($routeId === null) {
                $this->approve($tenantId, $id, $actor);
            } else {
                $this->db->run('UPDATE sales_quotes SET route_id = :route_id WHERE tenant_id = :tenant_id AND id = :id', [':route_id' => $routeId, ':tenant_id' => $tenantId, ':id' => $id]);
            }

            return $this->get($tenantId, $id);
        });
    }

    /** The route approved it (or there was none). */
    public function approve(int $tenantId, int $id, ?int $by): void
    {
        $this->db->run(
            "UPDATE sales_quotes SET status = 'ready', approved_at = CURRENT_TIMESTAMP, approved_by_profile_id = :by, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id AND status = 'waiting_approval'",
            [':by' => $by, ':tenant_id' => $tenantId, ':id' => $id]
        );
        if (($this->row($tenantId, $id)['status'] ?? null) !== 'ready') {
            throw new SalesRejectedException('This quote has already been decided', 409);
        }
    }

    /** The route turned it down or sent it back: a draft again. */
    public function rejected(int $tenantId, int $id): void
    {
        $this->db->run(
            "UPDATE sales_quotes SET status = 'draft', route_id = NULL, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id AND status = 'waiting_approval'",
            [':tenant_id' => $tenantId, ':id' => $id]
        );
    }

    // ── sending ──────────────────────────────────────────────────────────

    /**
     * Sends a quote to its customer: a ready quote, or a draft when the
     * workspace has no quote route. Takes the number on first send, a fresh
     * link, and makes the revision before it `replaced`.
     *
     * @return array{quote: array<string, mixed>, token: string}
     */
    public function send(int $tenantId, int $id, ?int $actor): array
    {
        $quote = $this->row($tenantId, $id) ?? throw SalesRejectedException::notFound('Quote');
        if ($quote['status'] === 'draft' && $this->routeTemplateId($tenantId) > 0) {
            throw new SalesRejectedException('Quotes are approved before they go out here. Send it for approval first.', 409, ['code' => 'route_needed']);
        }
        if (!in_array($quote['status'], ['draft', 'ready'], true)) {
            throw new SalesRejectedException(
                $quote['status'] === 'waiting_approval' ? 'This quote is waiting for approval' : 'This quote has already gone out; make a new revision to change it',
                409
            );
        }
        if ((string) $quote['valid_until'] < $this->today()->format('Y-m-d')) {
            throw SalesRejectedException::field('valid_until', 'The valid-until date has passed; choose a later one before sending', 409);
        }
        $view = $this->get($tenantId, $id);
        try {
            $this->hooks?->dispatch(Events::SALES_QUOTE_SENDING, [
                'tenant_id' => $tenantId, 'quote_id' => $id, 'number' => $view['number'], 'revision' => $view['revision'],
                'contact_id' => $view['contact_id'], 'currency' => $view['currency'], 'total_minor' => $view['total_minor'],
                'valid_until' => $view['valid_until'], 'acting_profile_id' => $actor,
                'lines' => array_map(static fn (array $l): array => [
                    'variant_id' => $l['variant_id'], 'description' => $l['description'], 'quantity' => $l['quantity'],
                    'price_minor' => $l['price_minor'], 'line_total_minor' => $l['line_total_minor'],
                ], $view['lines']),
            ]);
        } catch (HookVetoException $e) {
            throw new SalesRejectedException($e->reason(), 409, ['code' => 'vetoed']);
        }

        $token = PublicToken::make(32);
        $this->db->transaction(function () use ($tenantId, $id, $actor, $quote, $token): void {
            $number = $quote['number'] ?? null;
            if (!is_string($number) || $number === '') {
                $number = sprintf('QUO-%04d', $this->sequences->next($tenantId, 'sales:quote'));
            }
            $this->db->run(
                "UPDATE sales_quotes SET status = 'sent', number = :number, issue_date = :today, public_token_hash = :hash,
                        sent_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                  WHERE tenant_id = :tenant_id AND id = :id AND status IN ('draft', 'ready')",
                [':number' => $number, ':today' => $this->today()->format('Y-m-d'), ':hash' => PublicToken::hash($token), ':tenant_id' => $tenantId, ':id' => $id]
            );
            $this->db->run(
                "UPDATE sales_quotes SET status = 'replaced', updated_at = CURRENT_TIMESTAMP
                  WHERE tenant_id = :tenant_id AND number = :number AND id <> :id AND status IN ('sent', 'declined', 'expired')",
                [':tenant_id' => $tenantId, ':number' => $number, ':id' => $id]
            );
            $this->event(Events::SALES_QUOTE_SENT, $tenantId, $id, $actor);
        });

        return ['quote' => $this->get($tenantId, $id), 'token' => $token];
    }

    /**
     * A new link for a sent quote; the old one stops working. Only the hash of
     * a link is kept, so this is how a person gets one to copy again.
     */
    public function newLink(int $tenantId, int $id): string
    {
        $quote = $this->row($tenantId, $id) ?? throw SalesRejectedException::notFound('Quote');
        if (!in_array($quote['status'], ['sent', 'accepted', 'declined', 'expired', 'converted'], true)) {
            throw new SalesRejectedException('Only a quote that has gone out has a link', 409);
        }
        $token = PublicToken::make(32);
        $this->db->run(
            'UPDATE sales_quotes SET public_token_hash = :hash, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND id = :id',
            [':hash' => PublicToken::hash($token), ':tenant_id' => $tenantId, ':id' => $id]
        );

        return $token;
    }

    // ── deciding ─────────────────────────────────────────────────────────

    /**
     * The customer's answer: accepted or declined, by name. `$actor` is the
     * person who recorded it, or null when the customer answered online.
     *
     * @return array<string, mixed>
     */
    public function decide(int $tenantId, int $id, bool $accepted, string $name, string $reason, ?int $actor, bool $online): array
    {
        $quote = $this->row($tenantId, $id) ?? throw SalesRejectedException::notFound('Quote');
        $this->expireIfDue($tenantId, $quote);
        $quote = $this->row($tenantId, $id) ?? throw SalesRejectedException::notFound('Quote');
        if ($quote['status'] !== 'sent') {
            throw new SalesRejectedException(match ($quote['status']) {
                'accepted', 'converted' => 'This quote has already been accepted',
                'declined' => 'This quote has already been declined',
                'expired' => 'This quote has expired',
                'replaced' => 'A newer version of this quote has been sent',
                'cancelled' => 'This quote was withdrawn',
                default => 'This quote has not been sent yet',
            }, 409, ['code' => $quote['status']]);
        }
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($accepted && ($name === '' || mb_strlen($name) > 200)) {
            throw SalesRejectedException::field('name', 'Type the name of the person accepting, up to 200 characters');
        }
        $reason = mb_substr(trim($reason), 0, 1000);
        $this->db->run(
            "UPDATE sales_quotes SET status = :status, decided_by_name = :name, decided_by_profile_id = :actor, decided_online = :online,
                    decline_reason = :reason, decided_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id AND status = 'sent'",
            [
                ':status' => $accepted ? 'accepted' : 'declined', ':name' => mb_substr($name, 0, 200), ':actor' => $actor, ':online' => $online,
                ':reason' => $accepted ? '' : $reason, ':tenant_id' => $tenantId, ':id' => $id,
            ]
        );
        $this->event($accepted ? Events::SALES_QUOTE_ACCEPTED : Events::SALES_QUOTE_DECLINED, $tenantId, $id, $actor, [
            'decided_by_name' => $name, 'online' => $online,
        ] + ($accepted ? [] : ['reason' => $reason]));

        return $this->get($tenantId, $id);
    }

    /**
     * Withdraws a quote before the customer decided.
     *
     * @return array<string, mixed>
     */
    public function cancel(int $tenantId, int $id, ?int $actor): array
    {
        $quote = $this->row($tenantId, $id) ?? throw SalesRejectedException::notFound('Quote');
        if (!in_array($quote['status'], ['draft', 'waiting_approval', 'ready', 'sent'], true)) {
            throw new SalesRejectedException('This quote is already ' . str_replace('_', ' ', (string) $quote['status']), 409);
        }
        $this->db->run(
            "UPDATE sales_quotes SET status = 'cancelled', public_token_hash = NULL, cancelled_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id",
            [':tenant_id' => $tenantId, ':id' => $id]
        );
        $this->event(Events::SALES_QUOTE_CANCELLED, $tenantId, $id, $actor);

        return $this->get($tenantId, $id);
    }

    /**
     * Marks every sent quote past its valid-until date as expired, in every
     * workspace; the daily job calls this. Answers how many it expired.
     */
    public function expireDue(int $limit = 1000): int
    {
        // @tenant-guard-ignore: the daily expiry sweeps every workspace; each row it finds is then changed with its own tenant_id bound
        $rows = $this->db->all(
            "SELECT tenant_id, id FROM sales_quotes WHERE status = 'sent' AND valid_until < :today ORDER BY id LIMIT :limit",
            [':today' => $this->today()->format('Y-m-d'), ':limit' => max(1, $limit)]
        );
        $n = 0;
        foreach ($rows as $r) {
            if ($this->markExpired((int) $r['tenant_id'], (int) $r['id'])) {
                $n++;
            }
        }

        return $n;
    }

    // ── turning into ─────────────────────────────────────────────────────

    /**
     * The targets this quote could turn into now, with why not.
     *
     * @return list<array{key: string, label: array{en: string, ar: string}, possible: bool, reason: string, done: ?array{id: int, label: string}}>
     */
    public function targets(int $tenantId, int $id, int $actor): array
    {
        $quote = $this->row($tenantId, $id) ?? throw SalesRejectedException::notFound('Quote');
        $done = $this->conversionsOf($tenantId, $id);
        $snapshot = in_array($quote['status'], ['accepted', 'converted'], true) ? $this->snapshot($tenantId, $id) : null;
        $out = [];
        foreach ($this->conversions->all() as $key => $target) {
            $check = null;
            if ($snapshot !== null && !isset($done[$key])) {
                try {
                    $check = $target->canConvert($snapshot, $actor);
                } catch (\Throwable $e) {
                    error_log('[Quotes] target ' . $key . ' could not answer: ' . $e->getMessage());
                }
            }
            $out[] = [
                'key' => $key,
                'label' => $target->label(),
                'possible' => $check !== null && $check->possible,
                'reason' => $snapshot === null ? 'The customer has to accept the quote first'
                    : (isset($done[$key]) ? '' : ($check === null ? 'Not possible right now' : $check->reason)),
                'done' => $done[$key] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Turns an accepted quote into a target, once per target.
     *
     * @return array{quote: array<string, mixed>, target: array{key: string, id: int, label: string, link: string}, repeated: bool}
     */
    public function convert(int $tenantId, int $id, string $key, int $actor): array
    {
        $quote = $this->row($tenantId, $id) ?? throw SalesRejectedException::notFound('Quote');
        $target = $this->conversions->get($key) ?? throw SalesRejectedException::field('target', 'There is nothing called "' . $key . '" to turn a quote into');
        $done = $this->conversionsOf($tenantId, $id)[$key] ?? null;
        if ($done !== null) {
            return ['quote' => $this->get($tenantId, $id), 'target' => ['key' => $key] + $done + ['link' => ''], 'repeated' => true];
        }
        if (!in_array($quote['status'], ['accepted', 'converted'], true)) {
            throw new SalesRejectedException('The customer has to accept the quote first', 409);
        }
        $snapshot = $this->snapshot($tenantId, $id);
        $check = $target->canConvert($snapshot, $actor);
        if (!$check->possible) {
            throw new SalesRejectedException($check->reason !== '' ? $check->reason : 'This quote can\'t be turned into that now', 409);
        }

        try {
            $result = $this->db->transaction(function () use ($tenantId, $id, $key, $target, $snapshot, $actor) {
                $result = $target->convert($snapshot, $actor);
                $this->db->run(
                    'INSERT INTO sales_quote_conversions (tenant_id, quote_id, target, target_id, target_label, converted_by_profile_id)
                     VALUES (:tenant_id, :quote_id, :target, :target_id, :label, :actor)',
                    [':tenant_id' => $tenantId, ':quote_id' => $id, ':target' => $key, ':target_id' => $result->id, ':label' => mb_substr($result->label, 0, 100), ':actor' => $actor]
                );
                $this->db->run(
                    "UPDATE sales_quotes SET status = 'converted', converted_at = COALESCE(converted_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP
                      WHERE tenant_id = :tenant_id AND id = :id",
                    [':tenant_id' => $tenantId, ':id' => $id]
                );
                $this->event(Events::SALES_QUOTE_CONVERTED, $tenantId, $id, $actor, ['target' => $key, 'target_id' => $result->id]);

                return $result;
            });
        } catch (HookVetoException $e) {
            throw new SalesRejectedException($e->reason(), 409, ['code' => 'vetoed']);
        } catch (InventoryRejectedException $e) {
            throw new SalesRejectedException($e->clientMessage, $e->status, $e->extra);
        } catch (\PDOException $e) {
            if (!InventoryDb::isUniqueViolation($e)) {
                throw $e;
            }
            $done = $this->conversionsOf($tenantId, $id)[$key] ?? throw $e;

            return ['quote' => $this->get($tenantId, $id), 'target' => ['key' => $key] + $done + ['link' => ''], 'repeated' => true];
        }

        return [
            'quote' => $this->get($tenantId, $id),
            'target' => ['key' => $key, 'id' => $result->id, 'label' => $result->label, 'link' => $result->link],
            'repeated' => false,
        ];
    }

    // ── reading ──────────────────────────────────────────────────────────

    /**
     * Newest first, without lines; one row per quote number (its latest revision).
     *
     * @param array{status?: ?string, contact_id?: ?int} $filter
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, array $filter = [], int $limit = 50, int $offset = 0): array
    {
        $where = ["q.status <> 'replaced'"];
        $params = [':tenant_id' => $tenantId];
        if (($filter['status'] ?? null) !== null) {
            if (!in_array($filter['status'], self::STATUSES, true)) {
                throw SalesRejectedException::field('status', 'status is one of ' . implode(', ', self::STATUSES));
            }
            $where[] = 'q.status = :status';
            $params[':status'] = $filter['status'];
        }
        if (($filter['contact_id'] ?? null) !== null) {
            $where[] = 'q.contact_id = :contact_id';
            $params[':contact_id'] = (int) $filter['contact_id'];
        }
        // A draft revision sits beside the sent one it will replace; the list shows the sent one.
        $where[] = "NOT (q.status IN ('draft', 'waiting_approval', 'ready') AND q.replaces_id IS NOT NULL)";
        $rows = $this->db->all(
            'SELECT ' . self::COLUMNS . ', c.name AS contact_name
               FROM sales_quotes q JOIN contacts c ON c.tenant_id = q.tenant_id AND c.id = q.contact_id
              WHERE q.tenant_id = :tenant_id AND ' . implode(' AND ', $where) . '
              ORDER BY q.id DESC LIMIT :limit OFFSET :offset',
            $params + [':limit' => max(1, min(200, $limit)), ':offset' => max(0, $offset)]
        );

        return array_map(fn (array $r): array => $this->present($tenantId, $r), $rows);
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $id): array
    {
        $row = $this->db->one(
            'SELECT ' . self::COLUMNS . ', c.name AS contact_name, c.email AS contact_email
               FROM sales_quotes q JOIN contacts c ON c.tenant_id = q.tenant_id AND c.id = q.contact_id
              WHERE q.tenant_id = :tenant_id AND q.id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        ) ?? throw SalesRejectedException::notFound('Quote');
        $quote = $this->present($tenantId, $row);
        $quote['contact_email'] = (string) ($row['contact_email'] ?? '');
        $quote['lines'] = array_map(static fn (array $l): array => [
            'id' => $l['id'], 'variant_id' => $l['variant_id'], 'item_id' => $l['item_id'], 'sku' => $l['sku'], 'unit' => $l['unit'],
            'description' => $l['description'], 'quantity' => (new Quantity($l['quantity'], $l['quantity_decimals']))->toDecimal(),
            'price_minor' => $l['price_minor'], 'discount_bp' => $l['discount_bp'], 'tax_category' => $l['tax_category'],
            'tax_bp' => $l['tax_bp'], 'line_total_minor' => $l['line_total_minor'],
        ], $this->lineRows($tenantId, $id));
        $quote['conversions'] = [];
        foreach ($this->conversionsOf($tenantId, $id) as $key => $c) {
            $quote['conversions'][] = ['target' => $key, 'id' => $c['id'], 'label' => $c['label']];
        }
        $quote['revisions'] = $quote['number'] === null ? [] : array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'revision' => (int) $r['revision'], 'status' => (string) $r['status'],
            'sent_at' => is_string($r['sent_at']) ? $r['sent_at'] : null,
        ], $this->db->all(
            'SELECT id, revision, status, sent_at FROM sales_quotes WHERE tenant_id = :tenant_id AND number = :number ORDER BY revision',
            [':tenant_id' => $tenantId, ':number' => $quote['number']]
        ));

        return $quote;
    }

    /** @return array<string, mixed>|null */
    public function row(int $tenantId, int $id): ?array
    {
        return $this->db->one('SELECT ' . self::COLUMNS . ' FROM sales_quotes q WHERE q.tenant_id = :tenant_id AND q.id = :id', [':tenant_id' => $tenantId, ':id' => $id]);
    }

    /**
     * The workspace and quote a customer's link names, or null. When the
     * quote has been replaced, its newest sent revision instead, with
     * `replaced` true.
     *
     * @return array{tenant_id: int, quote_id: int, replaced: bool}|null
     */
    public function byToken(string $raw): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $raw) !== 1) {
            return null;
        }
        // @tenant-guard-ignore: the customer's quote link — this read is what RESOLVES the tenant, from a 256-bit token stored only as its hash under a global unique index (uq_sales_quotes_token), on a path where the caller has no account. Every later read and write binds the tenant_id it returns. Mirrors ManageLinks::find().
        $row = $this->db->one('SELECT tenant_id, id, number, status FROM sales_quotes WHERE public_token_hash = :hash LIMIT 1', [':hash' => PublicToken::hash($raw)]);
        if ($row === null) {
            return null;
        }
        $tenantId = (int) $row['tenant_id'];
        if ($row['status'] !== 'replaced') {
            return ['tenant_id' => $tenantId, 'quote_id' => (int) $row['id'], 'replaced' => false];
        }
        $latest = $this->db->one(
            "SELECT id FROM sales_quotes WHERE tenant_id = :tenant_id AND number = :number
                AND status NOT IN ('draft', 'waiting_approval', 'ready', 'replaced') ORDER BY revision DESC LIMIT 1",
            [':tenant_id' => $tenantId, ':number' => $row['number']]
        );

        return $latest === null ? null : ['tenant_id' => $tenantId, 'quote_id' => (int) $latest['id'], 'replaced' => true];
    }

    /**
     * What the customer sees: no ids of people, no notes kept for the team.
     *
     * @return array<string, mixed>
     */
    public function publicView(int $tenantId, int $id): array
    {
        $row = $this->row($tenantId, $id) ?? throw SalesRejectedException::notFound('Quote');
        $this->expireIfDue($tenantId, $row);
        $q = $this->get($tenantId, $id);

        return [
            'number' => $q['number'], 'revision' => $q['revision'], 'status' => $q['status'], 'customer' => $q['contact_name'],
            'issue_date' => $q['issue_date'], 'valid_until' => $q['valid_until'], 'currency' => $q['currency'],
            'prices_include_tax' => $q['prices_include_tax'], 'subtotal_minor' => $q['subtotal_minor'], 'tax_minor' => $q['tax_minor'],
            'total_minor' => $q['total_minor'], 'terms' => $q['terms'],
            'decided_by_name' => $q['status'] === 'accepted' || $q['status'] === 'converted' || $q['status'] === 'declined' ? $q['decided_by_name'] : null,
            'decided_at' => $q['decided_at'],
            'lines' => array_map(static fn (array $l): array => array_intersect_key($l, array_flip([
                'description', 'quantity', 'unit', 'price_minor', 'discount_bp', 'tax_bp', 'line_total_minor',
            ])), $q['lines']),
        ];
    }

    /** The quote as a conversion target reads it. */
    public function snapshot(int $tenantId, int $id): QuoteSnapshot
    {
        $q = $this->get($tenantId, $id);
        $lines = [];
        foreach ($this->lineRows($tenantId, $id) as $l) {
            $lines[] = new QuoteSnapshotLine(
                $l['id'], $l['variant_id'], $l['unit_id'], $l['description'],
                (new Quantity($l['quantity'], $l['quantity_decimals']))->toDecimal(),
                $l['base_quantity'] === null || $l['base_decimals'] === null ? null : (new Quantity($l['base_quantity'], $l['base_decimals']))->toDecimal(),
                $l['price_minor'], $l['discount_bp'], $l['tax_category'], $l['tax_bp'], $l['line_total_minor'],
            );
        }

        return new QuoteSnapshot(
            $id, (string) $q['number'], (int) $q['revision'], (int) $q['contact_id'], (string) $q['contact_name'], (string) $q['currency'],
            $q['prices_include_tax'] === true, (int) $q['subtotal_minor'], (int) $q['tax_minor'], (int) $q['total_minor'],
            (string) $q['valid_until'], (string) $q['notes'], (string) $q['terms'], $lines,
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     * @return array{contact_id: int, valid_until: string, currency: string, prices_include_tax: bool, notes: string, terms: string}
     */
    private function head(int $tenantId, array $data): array
    {
        $contactId = $data['contact_id'] ?? null;
        $contact = is_int($contactId) ? $this->db->one(
            'SELECT id, name, is_customer, archived_at FROM contacts WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $contactId]
        ) : null;
        if ($contact === null || $contact['archived_at'] !== null) {
            throw SalesRejectedException::field('contact_id', 'contact_id is a customer here');
        }
        if (!DbBool::of($contact['is_customer'])) {
            throw SalesRejectedException::field('contact_id', $contact['name'] . ' is not marked as a customer; mark them as one on their contact page first');
        }
        $valid = $data['valid_until'] ?? null;
        if ($valid === null || $valid === '') {
            $valid = $this->today()->modify('+' . $this->validDays($tenantId) . ' days')->format('Y-m-d');
        }
        if (!is_string($valid) || \DateTimeImmutable::createFromFormat('!Y-m-d', $valid) === false) {
            throw SalesRejectedException::field('valid_until', 'valid_until is a date such as 2026-12-21');
        }
        $currency = is_string($data['currency'] ?? null) && $data['currency'] !== '' ? strtoupper($data['currency']) : ($this->defaultCurrency)($tenantId);
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw SalesRejectedException::field('currency', 'currency is a 3-letter code');
        }
        $inclusive = $data['prices_include_tax'] ?? false;
        if (!is_bool($inclusive)) {
            throw SalesRejectedException::field('prices_include_tax', 'prices_include_tax is true or false');
        }

        return [
            'contact_id' => (int) $contact['id'],
            'valid_until' => substr($valid, 0, 10),
            'currency' => $currency,
            'prices_include_tax' => $inclusive,
            'notes' => is_string($data['notes'] ?? null) ? mb_substr(trim($data['notes']), 0, 2000) : '',
            'terms' => is_string($data['terms'] ?? null) ? mb_substr(trim($data['terms']), 0, 4000) : '',
        ];
    }

    /**
     * Lines as given, checked, converted and priced.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(int $tenantId, mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > self::MAX_LINES) {
            throw SalesRejectedException::field('lines', 'lines is a list of 1 to ' . self::MAX_LINES . ' {variant_id or description, quantity, price_minor}');
        }
        $out = [];
        foreach ($raw as $i => $line) {
            $field = 'lines[' . $i . ']';
            if (!is_array($line)) {
                throw SalesRejectedException::field($field, $field . ' must be an object');
            }
            $quantity = is_string($line['quantity'] ?? null) || is_int($line['quantity'] ?? null) ? trim((string) $line['quantity']) : '1';
            $description = is_string($line['description'] ?? null) ? mb_substr(trim($line['description']), 0, 500) : '';
            $variantId = $line['variant_id'] ?? null;
            if ($variantId !== null) {
                $out[] = $this->itemLine($tenantId, $field, $variantId, $line, $quantity, $description);
                continue;
            }
            if ($description === '') {
                throw SalesRejectedException::field($field, $field . ' needs an item (variant_id) or a description');
            }
            try {
                $q = Quantity::parse($quantity, self::FREE_DECIMALS);
            } catch (\InvalidArgumentException) {
                throw SalesRejectedException::field($field, $field . '.quantity is a number with up to 3 decimals, such as 2.5');
            }
            if ($q->steps <= 0) {
                throw SalesRejectedException::field($field, $field . '.quantity must be more than 0');
            }
            $out[] = [
                'variant_id' => null, 'unit_id' => null, 'description' => $description, 'quantity' => $q->steps,
                'quantity_decimals' => self::FREE_DECIMALS, 'base_quantity' => null,
                'price_minor' => self::price($field, $line['price_minor'] ?? null, 0),
            ] + self::rates($field, $line, '');
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function itemLine(int $tenantId, string $field, mixed $variantId, array $line, string $quantity, string $description): array
    {
        $this->workspace->ensure($tenantId);
        $found = is_int($variantId) ? $this->items->variantForStock($tenantId, $variantId) : null;
        if ($found === null || $found['item']['archived_at'] !== null || $found['variant']['archived_at'] !== null) {
            throw SalesRejectedException::field($field, $field . '.variant_id is not an item here');
        }
        $itemId = (int) $found['item']['id'];
        $unitCode = is_string($line['unit'] ?? null) && $line['unit'] !== '' ? $line['unit'] : (string) $found['unit']['code'];
        try {
            $steps = $this->units->toBaseSteps($tenantId, $itemId, $found['unit'], $quantity, $unitCode, $field . '.quantity');
        } catch (InventoryRejectedException $e) {
            throw new SalesRejectedException($e->clientMessage, $e->status, $e->extra);
        }
        if ($steps['steps'] <= 0) {
            throw SalesRejectedException::field($field, $field . '.quantity must be more than 0');
        }
        $variantPrice = $this->db->one(
            'SELECT sale_price_minor FROM inv_variants WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => (int) $variantId]
        )['sale_price_minor'] ?? null;
        $default = $steps['unit_id'] === (int) $found['unit']['id']
            ? (InventoryDb::int($variantPrice) ?? InventoryDb::int($found['item']['sale_price_minor']) ?? 0)
            : 0;

        return [
            'variant_id' => (int) $variantId, 'unit_id' => $steps['unit_id'],
            'description' => $description !== '' ? $description : (string) $found['item']['name'],
            'quantity' => $steps['entered_steps'],
            'quantity_decimals' => (int) ($this->units->row($tenantId, $steps['unit_id'])['decimals'] ?? 0),
            'base_quantity' => $steps['steps'],
            'price_minor' => self::price($field, $line['price_minor'] ?? null, $default),
        ] + self::rates($field, $line, (string) ($found['item']['tax_category'] ?? ''));
    }

    private static function price(string $field, mixed $price, int $default): int
    {
        $price ??= $default;
        if (!is_int($price) || $price < 0) {
            throw SalesRejectedException::field($field, $field . '.price_minor is the price of one, in minor units, 0 or more');
        }

        return $price;
    }

    /**
     * @param array<string, mixed> $line
     * @return array{discount_bp: int, tax_bp: int, tax_category: string}
     */
    private static function rates(string $field, array $line, string $category): array
    {
        foreach (['discount_bp', 'tax_bp'] as $bp) {
            $v = $line[$bp] ?? 0;
            if (!is_int($v) || $v < 0 || $v > 10000) {
                throw SalesRejectedException::field($field, $field . '.' . $bp . ' is basis points, 0 to 10000');
            }
        }
        $given = $line['tax_category'] ?? null;

        return [
            'discount_bp' => (int) ($line['discount_bp'] ?? 0),
            'tax_bp' => (int) ($line['tax_bp'] ?? 0),
            'tax_category' => is_string($given) ? mb_substr(trim($given), 0, 40) : $category,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(int $tenantId, int $quoteId, array $lines, bool $inclusive): void
    {
        $byRate = [];
        foreach ($lines as $i => $l) {
            $amount = self::lineAmount((int) $l['quantity'], (int) $l['quantity_decimals'], (int) $l['price_minor'], (int) $l['discount_bp']);
            $byRate[(int) $l['tax_bp']] = ($byRate[(int) $l['tax_bp']] ?? 0) + $amount;
            $this->db->run(
                'INSERT INTO sales_quote_lines (tenant_id, quote_id, position, variant_id, unit_id, description, quantity, quantity_decimals, base_quantity,
                        price_minor, discount_bp, tax_category, tax_bp, line_total_minor)
                 VALUES (:tenant_id, :quote_id, :position, :variant_id, :unit_id, :description, :quantity, :decimals, :base_quantity,
                        :price, :discount, :category, :tax, :total)',
                [
                    ':tenant_id' => $tenantId, ':quote_id' => $quoteId, ':position' => $i + 1, ':variant_id' => $l['variant_id'], ':unit_id' => $l['unit_id'],
                    ':description' => $l['description'], ':quantity' => (int) $l['quantity'], ':decimals' => (int) $l['quantity_decimals'],
                    ':base_quantity' => $l['base_quantity'], ':price' => (int) $l['price_minor'], ':discount' => (int) $l['discount_bp'],
                    ':category' => (string) $l['tax_category'], ':tax' => (int) $l['tax_bp'], ':total' => $amount,
                ]
            );
        }
        [$subtotal, $tax] = self::totals($byRate, $inclusive);
        $this->db->run(
            'UPDATE sales_quotes SET subtotal_minor = :subtotal, tax_minor = :tax, total_minor = :total, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id',
            [':subtotal' => $subtotal, ':tax' => $tax, ':total' => $subtotal + $tax, ':tenant_id' => $tenantId, ':id' => $quoteId]
        );
    }

    /** A line's amount: quantity times price, less its discount, rounded half up. */
    public static function lineAmount(int $steps, int $decimals, int $priceMinor, int $discountBp): int
    {
        $scale = 10 ** $decimals;
        $gross = intdiv($steps * $priceMinor * 2 + $scale, $scale * 2);

        return $gross - intdiv($gross * $discountBp + 5000, 10000);
    }

    /**
     * Subtotal and tax from each rate's sum of lines. With prices that
     * include tax the net is split out of each rate's sum once.
     *
     * @param array<int, int> $byRate tax basis points => sum of line amounts
     * @return array{0: int, 1: int}
     */
    public static function totals(array $byRate, bool $inclusive): array
    {
        $subtotal = 0;
        $tax = 0;
        foreach ($byRate as $rate => $amount) {
            if ($inclusive) {
                $net = intdiv($amount * 10000 * 2 + (10000 + $rate), (10000 + $rate) * 2);
                $subtotal += $net;
                $tax += $amount - $net;
            } else {
                $subtotal += $amount;
                $tax += intdiv($amount * $rate + 5000, 10000);
            }
        }

        return [$subtotal, $tax];
    }

    /** @return list<array<string, mixed>> lines as stored, ready to write again */
    private function storedLines(int $tenantId, int $quoteId): array
    {
        return array_map(static fn (array $l): array => array_intersect_key($l, array_flip([
            'variant_id', 'unit_id', 'description', 'quantity', 'quantity_decimals', 'base_quantity', 'price_minor', 'discount_bp', 'tax_bp', 'tax_category',
        ])), $this->lineRows($tenantId, $quoteId));
    }

    /** @return list<array<string, mixed>> */
    private function lineRows(int $tenantId, int $quoteId): array
    {
        $rows = $this->db->all(
            'SELECT l.id, l.variant_id, l.unit_id, l.description, l.quantity, l.quantity_decimals, l.base_quantity, l.price_minor, l.discount_bp,
                    l.tax_category, l.tax_bp, l.line_total_minor, v.item_id, v.sku, u.code AS unit, bu.decimals AS base_decimals
               FROM sales_quote_lines l
               LEFT JOIN inv_variants v ON v.tenant_id = l.tenant_id AND v.id = l.variant_id
               LEFT JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
               LEFT JOIN inv_units bu ON bu.tenant_id = i.tenant_id AND bu.id = i.base_unit_id
               LEFT JOIN inv_units u ON u.tenant_id = l.tenant_id AND u.id = l.unit_id
              WHERE l.tenant_id = :tenant_id AND l.quote_id = :quote_id
              ORDER BY l.position, l.id',
            [':tenant_id' => $tenantId, ':quote_id' => $quoteId]
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'variant_id' => InventoryDb::int($r['variant_id']), 'unit_id' => InventoryDb::int($r['unit_id']),
            'item_id' => InventoryDb::int($r['item_id']), 'sku' => is_string($r['sku']) ? $r['sku'] : null,
            'unit' => is_string($r['unit']) ? $r['unit'] : null, 'description' => (string) $r['description'],
            'quantity' => (int) $r['quantity'], 'quantity_decimals' => (int) $r['quantity_decimals'],
            'base_quantity' => InventoryDb::int($r['base_quantity']), 'base_decimals' => InventoryDb::int($r['base_decimals']),
            'price_minor' => (int) $r['price_minor'], 'discount_bp' => (int) $r['discount_bp'], 'tax_category' => (string) $r['tax_category'],
            'tax_bp' => (int) $r['tax_bp'], 'line_total_minor' => (int) $r['line_total_minor'],
        ], $rows);
    }

    /** @return array<string, array{id: int, label: string}> by target key */
    private function conversionsOf(int $tenantId, int $quoteId): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT target, target_id, target_label FROM sales_quote_conversions WHERE tenant_id = :tenant_id AND quote_id = :quote_id ORDER BY id',
            [':tenant_id' => $tenantId, ':quote_id' => $quoteId]
        ) as $r) {
            $out[(string) $r['target']] = ['id' => (int) $r['target_id'], 'label' => (string) $r['target_label']];
        }

        return $out;
    }

    /** @param array<string, mixed> $quote */
    private function expireIfDue(int $tenantId, array $quote): void
    {
        if ($quote['status'] === 'sent' && (string) $quote['valid_until'] < $this->today()->format('Y-m-d')) {
            $this->markExpired($tenantId, (int) $quote['id']);
        }
    }

    private function markExpired(int $tenantId, int $id): bool
    {
        $changed = $this->db->run(
            "UPDATE sales_quotes SET status = 'expired', updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id AND status = 'sent' AND valid_until < :today",
            [':tenant_id' => $tenantId, ':id' => $id, ':today' => $this->today()->format('Y-m-d')]
        )->rowCount() > 0;
        if ($changed) {
            $this->event(Events::SALES_QUOTE_EXPIRED, $tenantId, $id, null);
        }

        return $changed;
    }

    private function contactName(int $tenantId, int $contactId): string
    {
        return (string) ($this->db->one('SELECT name FROM contacts WHERE tenant_id = :tenant_id AND id = :id', [':tenant_id' => $tenantId, ':id' => $contactId])['name'] ?? '');
    }

    private function validDays(int $tenantId): int
    {
        $days = (int) (($this->settings)($tenantId)[SettingsRegistry::SALES_QUOTE_VALID_DAYS] ?? SettingsRegistry::defaults()[SettingsRegistry::SALES_QUOTE_VALID_DAYS] ?? 30);

        return max(1, min(365, $days));
    }

    private function routeTemplateId(int $tenantId): int
    {
        return (int) (($this->settings)($tenantId)[SettingsRegistry::SALES_QUOTE_ROUTE_TEMPLATE_ID] ?? 0);
    }

    private function today(): \DateTimeImmutable
    {
        return ($this->clock !== null ? ($this->clock)() : new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTime(0, 0);
    }

    /** @param array<string, mixed> $extra */
    private function event(string $event, int $tenantId, int $id, ?int $actor, array $extra = []): void
    {
        $row = $this->row($tenantId, $id);
        if ($row === null) {
            return;
        }
        $payload = [
            'tenant_id' => $tenantId, 'quote_id' => $id, 'number' => is_string($row['number']) ? $row['number'] : null,
            'revision' => (int) $row['revision'], 'contact_id' => (int) $row['contact_id'], 'status' => (string) $row['status'],
            'total_minor' => (int) $row['total_minor'], 'currency' => (string) $row['currency'], 'acting_profile_id' => $actor,
        ] + $extra;
        $this->hooks?->dispatch($event, $payload);
        try {
            $this->hooks?->dispatchAsync($event . '.async', $payload);
        } catch (\Throwable $e) {
            error_log('[sales] ' . $event . '.async could not be queued: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function present(int $tenantId, array $r): array
    {
        $name = function (mixed $profileId): ?string {
            if (!is_numeric($profileId)) {
                return null;
            }
            $row = $this->db->one('SELECT display_name FROM profiles WHERE id = :id', [':id' => (int) $profileId]);

            return is_string($row['display_name'] ?? null) ? $row['display_name'] : null;
        };
        $date = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? substr($v, 0, 10) : null;
        $at = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;

        return [
            'id' => (int) $r['id'],
            'number' => is_string($r['number']) && $r['number'] !== '' ? $r['number'] : null,
            'revision' => (int) $r['revision'],
            'replaces_id' => InventoryDb::int($r['replaces_id']),
            'status' => (string) $r['status'],
            'contact_id' => (int) $r['contact_id'],
            'contact_name' => (string) ($r['contact_name'] ?? $this->contactName($tenantId, (int) $r['contact_id'])),
            'issue_date' => $date($r['issue_date']),
            'valid_until' => (string) $date($r['valid_until']),
            'currency' => (string) $r['currency'],
            'prices_include_tax' => DbBool::of($r['prices_include_tax']),
            'subtotal_minor' => (int) $r['subtotal_minor'],
            'tax_minor' => (int) $r['tax_minor'],
            'total_minor' => (int) $r['total_minor'],
            'notes' => (string) $r['notes'],
            'terms' => (string) $r['terms'],
            'route_id' => InventoryDb::int($r['route_id']),
            'document_id' => InventoryDb::int($r['document_id']),
            'source_plugin' => is_string($r['source_plugin']) ? $r['source_plugin'] : null,
            'created_by_profile_id' => InventoryDb::int($r['created_by_profile_id']),
            'created_by_name' => $name($r['created_by_profile_id']),
            'agent_id' => InventoryDb::int($r['agent_id']),
            'on_behalf_of_profile_id' => InventoryDb::int($r['on_behalf_of_profile_id']),
            'approved_by_name' => $name($r['approved_by_profile_id']),
            'decided_by_name' => (string) $r['decided_by_name'],
            'decided_by_profile_name' => $name($r['decided_by_profile_id']),
            'decided_online' => DbBool::of($r['decided_online']),
            'decline_reason' => (string) $r['decline_reason'],
            'submitted_at' => $at($r['submitted_at']),
            'approved_at' => $at($r['approved_at']),
            'sent_at' => $at($r['sent_at']),
            'decided_at' => $at($r['decided_at']),
            'converted_at' => $at($r['converted_at']),
            'cancelled_at' => $at($r['cancelled_at']),
            'created_at' => (string) $r['created_at'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }
}
