<?php

declare(strict_types=1);

namespace App\Shared\Db;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper with convenience helpers for prepared queries.
 * Always uses prepared statements (PDO::ATTR_EMULATE_PREPARES = false).
 */
final class Connection
{
    public function __construct(public readonly PDO $pdo) {}

    /**
     * Run a query with bound params, return the statement (for fetching).
     * @param array<string,mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row as assoc or null.
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Fetch all rows as list of assocs.
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> */
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch a single scalar (first column of first row) or null.
     * @param array<string,mixed> $params
     */
    public function fetchScalar(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /**
     * Execute a non-SELECT and return affected rows.
     * @param array<string,mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Run callable in a transaction; rollback on exception.
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transactional(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
