<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database;

use Closure;
use PDO;
use PDOStatement;
use Psr\Log\LoggerInterface;
use Puff\Database\Contract\ConnectionInterface;

final class Connection implements ConnectionInterface
{
    private int $transactions = 0;

    public function __construct(
        private readonly ConnectionConfig $configuration,
        private ?PDO $pdo,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function name(): string
    {
        return $this->configuration->name;
    }

    public function driver(): Driver
    {
        return $this->configuration->driver;
    }

    public function fingerprint(): string
    {
        return \hash('sha256', $this->name() . '|' . $this->configuration->dsn());
    }

    public function pdo(): PDO
    {
        return $this->pdo ?? throw new \LogicException("Database connection [{$this->name()}] is disconnected.");
    }

    public function table(string $table): Query
    {
        return new Query($this, $table);
    }

    /**
     * @param array<array-key, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $parameters = []): array
    {
        $rows = $this->run($sql, $parameters)->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            if (\is_array($row)) {
                $result[] = $this->normalizeRow($row);
            }
        }
        return $result;
    }

    /**
     * @param array<array-key, mixed> $parameters
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $parameters = []): ?array
    {
        $row = $this->run($sql, $parameters)->fetch();
        return \is_array($row) ? $this->normalizeRow($row) : null;
    }

    /** @param array<array-key, mixed> $parameters */
    public function execute(string $sql, array $parameters = []): int
    {
        return $this->run($sql, $parameters)->rowCount();
    }

    /** @param Closure(Connection): mixed $callback */
    public function transaction(Closure $callback): mixed
    {
        $level = $this->transactions++;
        $savepoint = 'puff_' . $level;
        try {
            $level === 0 ? $this->pdo()->beginTransaction() : $this->pdo()->exec("SAVEPOINT {$savepoint}");
            $result = $callback($this);
            $level === 0 ? $this->pdo()->commit() : $this->pdo()->exec("RELEASE SAVEPOINT {$savepoint}");
            --$this->transactions;
            return $result;
        } catch (\Throwable $exception) {
            --$this->transactions;
            if ($level === 0 && $this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            } elseif ($level > 0) {
                $this->pdo()->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            }
            throw $exception;
        }
    }

    public function lastInsertId(): string|false
    {
        return $this->pdo()->lastInsertId();
    }

    public function disconnect(): void
    {
        if ($this->pdo?->inTransaction() === true) {
            $this->pdo->rollBack();
        }
        $this->transactions = 0;
        $this->pdo = null;
    }

    /** @param array<array-key, mixed> $parameters */
    private function run(string $sql, array $parameters): PDOStatement
    {
        if (\trim($sql) === '') {
            throw new \InvalidArgumentException('SQL must not be empty.');
        }
        $started = \microtime(true);
        try {
            $statement = $this->pdo()->prepare($sql);
            $statement->execute($parameters);
            $this->logger?->info($sql, [
                'bindings' => $this->bindingTypes($parameters),
                'connection' => $this->name(),
                'duration_ms' => (\microtime(true) - $started) * 1000,
                'rows' => $statement->rowCount(),
            ]);
            return $statement;
        } catch (\PDOException $exception) {
            $this->logger?->error($sql, [
                'bindings' => $this->bindingTypes($parameters),
                'connection' => $this->name(),
                'duration_ms' => (\microtime(true) - $started) * 1000,
                'exception' => $exception,
            ]);
            throw $exception;
        }
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            if (\is_string($key)) {
                $normalized[$key] = $value;
            }
        }
        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $parameters
     * @return list<string>
     */
    private function bindingTypes(array $parameters): array
    {
        return \array_values(\array_map(\get_debug_type(...), $parameters));
    }
}
