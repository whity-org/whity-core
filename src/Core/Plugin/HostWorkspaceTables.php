<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use PDO;
use Whity\Sdk\Database\TableRefused;
use Whity\Sdk\Database\WorkspaceTables;

/**
 * The host's {@see WorkspaceTables} (SDK 1.151, #1693): one plugin's declared
 * tables, every statement held to the workspace of the moment.
 *
 * SQL is built here from checked names only. Table names come from the plugin's
 * declaration (checked by {@see PluginCapabilities}); column names must be plain
 * (`[a-z_][a-z0-9_]*`) and are quoted; every value is a bound parameter. The
 * `tenant_id` predicate and column are added by this class and may not be
 * written by the plugin, so no call can read or write another workspace's rows.
 */
final class HostWorkspaceTables implements WorkspaceTables
{
    private const NAME = '/^[a-z_][a-z0-9_]{0,62}$/';
    private const TENANT = 'tenant_id';

    /**
     * @param list<string> $tables The plugin's declared tables.
     * @param \Closure(): PDO $pdo
     * @param \Closure(): ?int $workspace The workspace of the moment, or null.
     */
    public function __construct(
        private readonly string $plugin,
        private readonly array $tables,
        private readonly \Closure $pdo,
        private readonly \Closure $workspace,
    ) {
    }

    public function select(string $table, array $where = [], array $orderBy = [], ?int $limit = null, int $offset = 0): array
    {
        [$sql, $params] = $this->whereClause($table, $where);
        $order = [];
        foreach ($orderBy as $column => $direction) {
            $dir = strtolower((string) $direction) === 'desc' ? 'DESC' : 'ASC';
            $order[] = $this->column((string) $column) . ' ' . $dir;
        }
        $query = 'SELECT * FROM ' . $this->quote($table) . $sql;
        if ($order !== []) {
            $query .= ' ORDER BY ' . implode(', ', $order);
        }
        if ($limit !== null) {
            $query .= ' LIMIT ' . max(0, $limit);
        }
        if ($offset > 0) {
            // SQLite needs a LIMIT before OFFSET and PostgreSQL refuses a negative one.
            $query .= ($limit === null ? ' LIMIT ' . PHP_INT_MAX : '') . ' OFFSET ' . $offset;
        }

        $statement = $this->run($query, $params);
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    public function first(string $table, array $where): ?array
    {
        return $this->select($table, $where, [], 1)[0] ?? null;
    }

    public function count(string $table, array $where = []): int
    {
        [$sql, $params] = $this->whereClause($table, $where);

        return (int) $this->run('SELECT COUNT(*) FROM ' . $this->quote($table) . $sql, $params)->fetchColumn();
    }

    public function insert(string $table, array $row): ?string
    {
        $this->table($table);
        if ($row === []) {
            throw TableRefused::because(TableRefused::EMPTY_WRITE, 'An insert needs at least one column.');
        }
        $columns = [];
        $params = [];
        foreach ($row as $column => $value) {
            $columns[] = $this->column((string) $column);
            $params[] = $value;
        }
        $columns[] = $this->quote(self::TENANT);
        $params[] = $this->workspaceId();

        $this->run(
            'INSERT INTO ' . $this->quote($table) . ' (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_fill(0, count($params), '?')) . ')',
            $params
        );

        try {
            $id = ($this->pdo)()->lastInsertId();
        } catch (\Throwable) {
            return null;
        }

        return is_string($id) && $id !== '' && $id !== '0' ? $id : null;
    }

    public function update(string $table, array $set, array $where): int
    {
        if ($set === [] || $where === []) {
            throw TableRefused::because(TableRefused::EMPTY_WRITE, 'An update needs columns to set and a condition.');
        }
        $this->table($table);
        $assignments = [];
        $params = [];
        foreach ($set as $column => $value) {
            $assignments[] = $this->column((string) $column) . ' = ?';
            $params[] = $value;
        }
        [$sql, $whereParams] = $this->whereClause($table, $where);

        return $this->run(
            'UPDATE ' . $this->quote($table) . ' SET ' . implode(', ', $assignments) . $sql,
            array_merge($params, $whereParams)
        )->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw TableRefused::because(TableRefused::EMPTY_WRITE, 'A delete needs a condition.');
        }
        [$sql, $params] = $this->whereClause($table, $where);

        return $this->run('DELETE FROM ' . $this->quote($table) . $sql, $params)->rowCount();
    }

    public function transaction(callable $work): mixed
    {
        $pdo = ($this->pdo)();
        if ($pdo->inTransaction()) {
            return $work($this);
        }
        $pdo->beginTransaction();
        try {
            $result = $work($this);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * ` WHERE "tenant_id" = ? AND …` and its parameters.
     *
     * @param array<array-key, mixed> $where
     * @return array{string, list<mixed>}
     */
    private function whereClause(string $table, array $where): array
    {
        $this->table($table);
        $parts = [$this->quote(self::TENANT) . ' = ?'];
        $params = [$this->workspaceId()];
        foreach ($where as $column => $value) {
            $quoted = $this->column((string) $column);
            if ($value === null) {
                $parts[] = "{$quoted} IS NULL";
            } elseif (is_array($value)) {
                if ($value === []) {
                    $parts[] = '1 = 0';
                    continue;
                }
                $parts[] = "{$quoted} IN (" . implode(', ', array_fill(0, count($value), '?')) . ')';
                foreach ($value as $item) {
                    $params[] = $item;
                }
            } else {
                $parts[] = "{$quoted} = ?";
                $params[] = $value;
            }
        }

        return [' WHERE ' . implode(' AND ', $parts), $params];
    }

    /** @param list<mixed> $params */
    private function run(string $sql, array $params): \PDOStatement
    {
        $statement = ($this->pdo)()->prepare($sql);
        foreach ($params as $i => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            if (!is_scalar($value) && $value !== null) {
                throw TableRefused::because(TableRefused::BAD_NAME, 'Values must be scalars or null.');
            }
            $statement->bindValue($i + 1, $value, $type);
        }
        $statement->execute();

        return $statement;
    }

    private function table(string $table): void
    {
        if (!in_array($table, $this->tables, true)) {
            throw TableRefused::because(
                TableRefused::UNDECLARED_TABLE,
                "The {$this->plugin} plugin did not declare the table '{$table}' in getTables()."
            );
        }
    }

    private function column(string $column): string
    {
        if (preg_match(self::NAME, $column) !== 1) {
            throw TableRefused::because(TableRefused::BAD_NAME, "'{$column}' is not a plain column name.");
        }
        if ($column === self::TENANT) {
            throw TableRefused::because(
                TableRefused::TENANT_COLUMN,
                'tenant_id comes from the context; leave it out.'
            );
        }

        return $this->quote($column);
    }

    private function quote(string $name): string
    {
        return '"' . $name . '"';
    }

    private function workspaceId(): int
    {
        $workspace = ($this->workspace)();
        if ($workspace === null || $workspace <= 0) {
            throw TableRefused::because(TableRefused::NO_WORKSPACE, 'There is no workspace in scope for this call.');
        }

        return $workspace;
    }
}
