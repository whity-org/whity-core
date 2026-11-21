<?php

declare(strict_types=1);

namespace Whity\Sdk\Database;

/**
 * A plugin's own tables, bound to the workspace the request or job runs in
 * (SDK 1.151, #1693). From `$ctx->database()`; see
 * {@see \Whity\Sdk\Contribute\PluginCapabilitiesInterface}.
 *
 * Every call reads or writes only rows of the context's workspace: the host adds
 * `tenant_id = <workspace>` to every condition and sets it on every row it
 * inserts. The plugin never names `tenant_id` itself; doing so is refused, as
 * is a table the plugin didn't declare or any call with no workspace in scope.
 * So a missing `WHERE tenant_id = ?` can't happen here, which is the mistake
 * lint and tests otherwise have to catch.
 *
 * Conditions are `column => value`: a scalar means equals, `null` means IS NULL,
 * and a list means IN (an empty list matches nothing). Anything richer (ranges,
 * joins, aggregates) still uses the plugin's own SQL, with its own
 * `tenant_id` predicate, as before.
 *
 * A call this handle refuses throws {@see TableRefused}; database errors are the
 * driver's.
 */
interface WorkspaceTables
{
    /**
     * @param array<string, scalar|null|list<scalar>> $where
     * @param array<string, 'asc'|'desc'> $orderBy
     * @return list<array<string, mixed>>
     */
    public function select(string $table, array $where = [], array $orderBy = [], ?int $limit = null, int $offset = 0): array;

    /**
     * @param array<string, scalar|null|list<scalar>> $where
     * @return array<string, mixed>|null
     */
    public function first(string $table, array $where): ?array;

    /** @param array<string, scalar|null|list<scalar>> $where */
    public function count(string $table, array $where = []): int;

    /**
     * Insert one row in this workspace.
     *
     * @param array<string, scalar|null> $row
     * @return string|null The new row's id when the driver reports one.
     */
    public function insert(string $table, array $row): ?string;

    /**
     * @param array<string, scalar|null> $set
     * @param array<string, scalar|null|list<scalar>> $where Must not be empty.
     * @return int Rows changed.
     */
    public function update(string $table, array $set, array $where): int;

    /**
     * @param array<string, scalar|null|list<scalar>> $where Must not be empty.
     * @return int Rows deleted.
     */
    public function delete(string $table, array $where): int;

    /**
     * Run $work in one transaction (joined if one is open) and return its result.
     *
     * @template T
     * @param callable(self): T $work
     * @return T
     */
    public function transaction(callable $work): mixed;
}
