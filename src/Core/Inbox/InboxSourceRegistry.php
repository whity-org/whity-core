<?php

declare(strict_types=1);

namespace Whity\Core\Inbox;

use Whity\Core\Container\HostWiredService;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Support\SourceSlug;
use Whity\Sdk\Inbox\InboxSource;

/**
 * The catalogue of registered INBOX SOURCES (#881), and the reason routing's
 * recipients are not a surface of their own.
 *
 * `document_route_recipients` IS an inbox — an open row is an item awaiting
 * somebody. Shipping it behind its own endpoint would have been the exact
 * mistake #881 exists to prevent, and #947 says so in one line: "two inbox
 * surfaces would be the same mistake as two audit trails." So routing registers
 * HERE, as core's first source, and the inbox surface belongs to this registry
 * rather than to routing.
 *
 * Same construction as {@see ResourceTypeRegistry}, {@see \Whity\Core\Ou\OuTypeRegistry},
 * {@see \Whity\Core\Queue\JobRegistry} and the ten others. #881 makes exactly
 * that argument — "nothing about an inbox is different in kind… a per-plugin
 * inbox is the odd one out, not the aggregate" — so the shape is borrowed rather
 * than invented.
 *
 * THE THREE QUESTIONS #881 LEFT OPEN, AND THEIR ANSWERS (ADR 0016)
 * ----------------------------------------------------------------
 *   1. Ordering across sources: {@see \Whity\Sdk\Inbox\InboxOrder}. Urgent
 *      items first (a flag the source sets per item), then the newest.
 *   2. Failure isolation: a source that throws is named as unavailable and the
 *      rest is still served ({@see InboxAggregate}, and
 *      {@see \Whity\Api\MeWaitingApiHandler} before it).
 *   3. Pagination across sources: keyset, by {@see \Whity\Sdk\Inbox\InboxCursor},
 *      in {@see InboxAggregate}; never page 1 of each source concatenated.
 *
 * `GET /api/me/inbox?source=…` still reads one source at a time and still
 * requires `source`; the merged list is `GET /api/me/inbox/merged`.
 *
 * THE PLUGIN-FACING HALF (SDK 1.97)
 * ---------------------------------
 * The contract moved to `Whity\Sdk\Inbox` once question 1 was answered, so it
 * did not have to be published and then broken. Plugins register through
 * {@see register()}, under `<plugin>:<key>`, from the loader's
 * {@see \Whity\Sdk\PluginInboxSourcesInterface} block.
 *
 * {@see HostWiredService}: an improvised, empty instance would report the
 * caller's inbox as EMPTY rather than as unavailable — and "no items" is the
 * most ordinary answer an inbox has, so nothing would look wrong. That is the
 * silent-failure shape the marker exists for.
 */
final class InboxSourceRegistry implements HostWiredService
{
    /** Source name for inbox sources shipped by core. Reserved. */
    public const CORE_SOURCE = 'core';

    /**
     * Separates a plugin's namespace from its slug, when the plugin-facing half
     * lands: `acme:approvals`.
     *
     * Borrowed from {@see ResourceTypeRegistry} rather than restated, so the
     * catalogues cannot drift into spelling the same plugin's keys two different
     * ways.
     */
    public const NAMESPACE_SEPARATOR = ResourceTypeRegistry::NAMESPACE_SEPARATOR;

    /**
     * Core's routing inbox: the open `document_route_recipients` rows.
     *
     * Underscored rather than hyphenated because the slug grammar every other
     * registry in the platform enforces is `[a-z][a-z0-9_]*`, and a key that
     * only this catalogue could accept would be the start of a second grammar.
     */
    public const CORE_DOCUMENT_ROUTING = 'document_routing';

    /**
     * Registered sources, keyed by canonical key.
     *
     * @var array<string, InboxSource>
     */
    private array $sources = [];

    /**
     * Which SOURCE registered each key, kept so the catalogue endpoint can say
     * where a source came from — an operator deciding what is safe to uninstall
     * needs to tell core's from a plugin's.
     *
     * @var array<string, string>
     */
    private array $originByKey = [];

    /**
     * Register a source core owns, under its bare key.
     *
     * Bare, because the unprefixed namespace belongs to core — the same
     * reservation {@see ResourceTypeRegistry} makes, and what will stop a
     * plugin shadowing `document_routing` once plugins can register at all.
     *
     * @throws InvalidInboxSourceException On a malformed or duplicate key.
     */
    public function registerCoreSource(string $key, InboxSource $source): void
    {
        if (!self::isValidSlug($key)) {
            throw InvalidInboxSourceException::forSlug($key);
        }
        if (array_key_exists($key, $this->sources)) {
            throw InvalidInboxSourceException::forDuplicateKey($key);
        }

        $this->sources[$key] = $source;
        $this->originByKey[$key] = self::CORE_SOURCE;
    }

    /**
     * A registered source, or null when nothing registered the key.
     *
     * Null is what turns an unknown `?source=` into a 422 naming the registered
     * keys, rather than an empty list that reads as "you have no items".
     */
    public function get(string $key): ?InboxSource
    {
        return $this->sources[$key] ?? null;
    }

    /**
     * Register a plugin's sources under `<plugin>:<key>` (SDK 1.97, #881).
     *
     * Attributed by the plugin's own name, passed by the loader, never by
     * anything the plugin returns. The whole batch is checked before any of it
     * is stored. A plugin registering again (a reload) replaces its own keys;
     * it can never take another source's key, because the prefix is its own.
     *
     * @param array<mixed, mixed> $sources bare key => {@see InboxSource}
     *
     * @throws InvalidInboxSourceException On a malformed key, a non-source, or
     *                                     a plugin calling itself core.
     */
    public function register(string $pluginName, array $sources): void
    {
        if ($pluginName === self::CORE_SOURCE || SourceSlug::from($pluginName) === null) {
            throw InvalidInboxSourceException::forSlug($pluginName);
        }

        $batch = [];
        foreach ($sources as $key => $source) {
            if (!is_string($key) || !self::isValidSlug($key)) {
                throw InvalidInboxSourceException::forSlug(is_string($key) ? $key : (string) $key);
            }
            if (!$source instanceof InboxSource) {
                throw InvalidInboxSourceException::forSlug($key);
            }
            $batch[self::canonicalKey($pluginName, $key)] = $source;
        }

        foreach ($this->originByKey as $key => $origin) {
            if ($origin === $pluginName) {
                unset($this->sources[$key], $this->originByKey[$key]);
            }
        }
        foreach ($batch as $key => $source) {
            $this->sources[$key] = $source;
            $this->originByKey[$key] = $pluginName;
        }
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->sources);
    }

    /**
     * Every registered key, sorted.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->sources);
        sort($keys);

        return $keys;
    }

    /**
     * The catalogue a client reads to know what it may ask for.
     *
     * Carries the FIELD MAPPING as well as the key, so a screen wiring an
     * `inbox` block does not have to hardcode the field names
     * {@see InboxItem::toArray()} emits — and so a future source that shapes its
     * items differently can say so rather than silently breaking every block
     * pointed at it. The mapping is identical for every source today, which is
     * the point: it is the contract, published rather than assumed.
     *
     * @return list<array{key: string, label: string, origin: string, item_fields: array<string, string>}>
     */
    public function catalogue(): array
    {
        return array_map(
            fn (string $key): array => [
                'key' => $key,
                'label' => $this->sources[$key]->label(),
                'origin' => $this->originByKey[$key] ?? self::CORE_SOURCE,
                'item_fields' => self::itemFields(),
            ],
            $this->keys()
        );
    }

    /**
     * The `inbox` block prop => item field mapping every source emits.
     *
     * Exactly the props the block type declares (#868): a screen can copy this
     * into the block's configuration and the two cannot disagree, which is the
     * "conforms by construction" property #881 relies on.
     *
     * @return array<string, string>
     */
    public static function itemFields(): array
    {
        return [
            'idField' => 'id',
            'titleField' => 'title',
            'subtitleField' => 'subtitle',
            'timestampField' => 'timestamp',
            'statusField' => 'status',
        ];
    }

    /**
     * A valid bare key: lowercase, starts with a letter, then letters, digits
     * and underscores. No colon — that is the namespace separator the host
     * applies.
     */
    public static function isValidSlug(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/', $key) === 1 && strlen($key) <= 128;
    }

    /**
     * The canonical key a source's bare slug resolves to.
     *
     * Used by {@see register()} since SDK 1.97, and stated here beside every
     * other registry's rule so the catalogues spell a plugin's keys one way.
     */
    public static function canonicalKey(string $origin, string $slug): string
    {
        if ($origin === self::CORE_SOURCE) {
            return $slug;
        }

        $prefix = SourceSlug::from($origin);

        return $prefix === null ? $slug : $prefix . self::NAMESPACE_SEPARATOR . $slug;
    }
}
