<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use PDO;
use PDOStatement;

/**
 * The few database helpers accounting's services share (WHIT-1318, the same
 * shape as inventory's): running a statement with typed binds, a transaction
 * that joins one already open, and telling the engines apart. Every
 * statement passed here binds `tenant_id` itself; this class adds no scoping
 * of its own.
 */
final class AccountingDb
{
    public function __construct(public readonly PDO $pdo)
    {
    }

    /** @param array<string, int|string|bool|null> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            });
        }
        $stmt->execute();

        return $stmt;
    }

    /**
     * @param array<string, int|string|bool|null> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, int|string|bool|null> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /** @param array<string, int|string|bool|null> $params */
    public function insert(string $sql, array $params = []): int
    {
        return (int) $this->run($sql . ' RETURNING id', $params)->fetchColumn();
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function isPostgres(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }

    public static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** Whether a PDO error is a unique-key violation, on either engine. */
    public static function isUniqueViolation(\PDOException $e): bool
    {
        $state = (string) $e->getCode();

        return $state === '23505' || ($state === '23000' && str_contains(strtolower($e->getMessage()), 'unique'));
    }
}
