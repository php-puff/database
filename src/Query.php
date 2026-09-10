<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database;

use Puff\Database\Contract\ConnectionInterface;

final class Query
{
    /** @var list<string> */
    private array $columns = ['*'];
    /** @var list<array{boolean: string, column: string, operator: string, value: mixed}> */
    private array $wheres = [];
    /** @var list<array{column: string, direction: string}> */
    private array $orders = [];
    private ?int $limit = null;
    private ?int $offset = null;

    public function __construct(private readonly ConnectionInterface $connection, private readonly string $table)
    {
        self::identifier($table);
    }

    public function select(string ...$columns): self
    {
        $clone = clone $this;
        $clone->columns = $columns === [] ? ['*'] : \array_values(\array_map(self::identifier(...), $columns));
        return $clone;
    }

    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        $arguments = \func_num_args();
        $operator = $arguments === 2 ? '=' : \strtoupper((string) $operatorOrValue);
        $actual = $arguments === 2 ? $operatorOrValue : $value;
        if (!\in_array($operator, ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE'], true)) {
            throw new \InvalidArgumentException("Unsupported where operator [{$operator}].");
        }
        $clone = clone $this;
        $clone->wheres[] = ['boolean' => 'AND', 'column' => self::identifier($column), 'operator' => $operator, 'value' => $actual];
        return $clone;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = \strtoupper($direction);
        if (!\in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('Order direction must be ASC or DESC.');
        }
        $clone = clone $this;
        $clone->orders[] = ['column' => self::identifier($column), 'direction' => $direction];
        return $clone;
    }

    public function limit(int $limit, int $offset = 0): self
    {
        if ($limit < 1 || $offset < 0) {
            throw new \InvalidArgumentException('Query limit must be positive and offset must not be negative.');
        }
        $clone = clone $this;
        $clone->limit = $limit;
        $clone->offset = $offset;
        return $clone;
    }

    /** @return list<array<string, mixed>> */
    public function get(): array
    {
        [$where, $bindings] = $this->compileWhere();
        $sql = 'SELECT ' . \implode(', ', $this->columns) . " FROM {$this->table}{$where}" . $this->compileOrderLimit();
        return $this->connection->fetchAll($sql, $bindings);
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        $rows = $this->limit(1)->get();
        return $rows[0] ?? null;
    }

    public function count(string $column = '*'): int
    {
        $column = $column === '*' ? '*' : self::identifier($column);
        [$where, $bindings] = $this->compileWhere();
        $row = $this->connection->fetchOne("SELECT COUNT({$column}) AS aggregate FROM {$this->table}{$where}", $bindings);
        return (int) ($row['aggregate'] ?? 0);
    }

    /** @param array<string, mixed> $values */
    public function insert(array $values): int
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Insert values must not be empty.');
        }
        $columns = \array_map(self::identifier(...), \array_keys($values));
        $sql = "INSERT INTO {$this->table} (" . \implode(', ', $columns) . ') VALUES (' . \implode(', ', \array_fill(0, \count($columns), '?')) . ')';
        $this->connection->execute($sql, \array_values($values));
        return (int) $this->connection->lastInsertId();
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): int
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Update values must not be empty.');
        }
        [$where, $bindings] = $this->compileWhere();
        $sets = [];
        foreach ($values as $column => $_value) {
            $sets[] = self::identifier($column) . ' = ?';
        }
        return $this->connection->execute(
            "UPDATE {$this->table} SET " . \implode(', ', $sets) . $where,
            [...\array_values($values), ...$bindings],
        );
    }

    public function delete(): int
    {
        [$where, $bindings] = $this->compileWhere();
        return $this->connection->execute("DELETE FROM {$this->table}{$where}", $bindings);
    }

    /** @return array{string, list<mixed>} */
    private function compileWhere(): array
    {
        if ($this->wheres === []) {
            return ['', []];
        }
        $parts = [];
        $bindings = [];
        foreach ($this->wheres as $index => $where) {
            $prefix = $index === 0 ? '' : $where['boolean'] . ' ';
            $parts[] = $prefix . $where['column'] . ' ' . $where['operator'] . ' ?';
            $bindings[] = $where['value'];
        }
        return [' WHERE ' . \implode(' ', $parts), $bindings];
    }

    private function compileOrderLimit(): string
    {
        $sql = '';
        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . \implode(', ', \array_map(
                static fn (array $order): string => $order['column'] . ' ' . $order['direction'],
                $this->orders,
            ));
        }
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit} OFFSET " . ($this->offset ?? 0);
        }
        return $sql;
    }

    private static function identifier(string $identifier): string
    {
        if ($identifier !== '*' && !\preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid SQL identifier [{$identifier}].");
        }
        return $identifier;
    }
}
