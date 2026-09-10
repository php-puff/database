<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database;

use Puff\Console\Contract;
use Puff\Console\Generator;
use Puff\Console\Input;
use Puff\Console\Output;
use Puff\Database\Contract\ConnectionInterface;
use Puff\Database\Contract\DatabaseManagerInterface;

final readonly class EntityCommand implements Contract
{
    public function __construct(
        private Generator $generator,
        private DatabaseManagerInterface $databases,
        private string $stub,
    ) {
    }

    public function name(): string
    {
        return 'entity';
    }

    public function description(): string
    {
        return 'Create a Cycle entity from a database table';
    }

    public function usage(): string
    {
        return 'entity <name> [options]';
    }

    public function valueOptions(): array
    {
        return ['connection' => 'c', 'table' => 't'];
    }

    public function flagOptions(): array
    {
        return ['force' => 'f'];
    }

    public function execute(Input $input, Output $output): int
    {
        $name = (string) $input->argument(0);
        if ($name === '') {
            throw new \InvalidArgumentException('Entity name is required.');
        }

        $table = (string) ($input->option('table') ?: $this->table($name));
        $connectionName = $input->option('connection');
        $connection = $this->databases->connection(\is_string($connectionName) ? $connectionName : null);
        $columns = $this->columns($connection, $table);
        if ($columns === []) {
            throw new \InvalidArgumentException("Table [{$table}] has no columns or does not exist.");
        }

        $class = $this->generator->generate(
            $name,
            'Entity',
            $this->stub,
            $input->hasOption('force'),
            ['%TABLE%' => $table, '%PROPERTIES%' => $this->properties($columns)],
        );
        $output->write("{$class} created from {$connection->name()}.{$table}.");

        return 0;
    }

    /** @return list<array{name: string, type: string, nullable: bool, primary: bool, length: ?int}> */
    private function columns(ConnectionInterface $connection, string $table): array
    {
        if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name [{$table}].");
        }

        $rows = match ($connection->driver()) {
            Driver::MySql => $connection->fetchAll(
                'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, IS_NULLABLE AS nullable, '
                . "COLUMN_KEY = 'PRI' AS `primary`, CHARACTER_MAXIMUM_LENGTH AS length "
                . 'FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ORDINAL_POSITION',
                [$table],
            ),
            Driver::PostgreSql => $connection->fetchAll(
                'SELECT c.column_name AS name, c.data_type AS type, c.is_nullable AS nullable, '
                . 'c.character_maximum_length AS length, '
                . 'EXISTS (SELECT 1 FROM information_schema.table_constraints tc '
                . 'JOIN information_schema.key_column_usage kcu '
                . 'ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema '
                . "WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = c.table_schema "
                . 'AND tc.table_name = c.table_name AND kcu.column_name = c.column_name) AS "primary" '
                . 'FROM information_schema.columns c WHERE c.table_schema = current_schema() AND c.table_name = ? '
                . 'ORDER BY c.ordinal_position',
                [$table],
            ),
            Driver::SQLite => $connection->fetchAll('PRAGMA table_info(' . $connection->driver()->quote($table) . ')'),
        };

        $columns = [];
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $columns[] = [
                'name' => $name,
                'type' => \strtolower((string) ($row['type'] ?? 'string')),
                'nullable' => $this->nullable($row),
                'primary' => $this->flag($row['primary'] ?? $row['pk'] ?? false),
                'length' => isset($row['length']) && \is_numeric($row['length']) ? (int) $row['length'] : null,
            ];
        }

        return $columns;
    }

    /** @param array<string, mixed> $row */
    private function nullable(array $row): bool
    {
        return match ($row['nullable'] ?? null) {
            'YES', 'yes', true, 1, '1' => true,
            default => !(bool) ($row['notnull'] ?? false),
        };
    }

    private function flag(mixed $value): bool
    {
        return \in_array($value, [true, 1, '1', 't', 'true', 'TRUE'], true);
    }

    /** @param list<array{name: string, type: string, nullable: bool, primary: bool, length: ?int}> $columns */
    private function properties(array $columns): string
    {
        return \implode("\n\n", \array_map($this->property(...), $columns));
    }

    /** @param array{name: string, type: string, nullable: bool, primary: bool, length: ?int} $column */
    private function property(array $column): string
    {
        [$type, $phpType] = $this->types($column);
        $property = $this->propertyName($column['name']);
        $arguments = ["type: '{$type}'"];
        if ($property !== $column['name']) {
            $arguments[] = "name: '{$column['name']}'";
        }
        if ($column['nullable']) {
            $arguments[] = 'nullable: true';
        }
        $nullable = $column['nullable'] ? '?' : '';
        $default = $column['nullable'] ? ' = null' : '';

        return '    #[Column(' . \implode(', ', $arguments) . ")]\n"
            . "    public {$nullable}{$phpType} \${$property}{$default};";
    }

    /**
     * @param array{name: string, type: string, nullable: bool, primary: bool, length: ?int} $column
     * @return array{0: string, 1: string}
     */
    private function types(array $column): array
    {
        $type = $column['type'];
        if ($column['primary']) {
            return [\str_contains($type, 'big') ? 'bigPrimary' : 'primary', 'int'];
        }
        if (\in_array($type, ['json', 'jsonb'], true)) {
            return [$type, 'array'];
        }
        if (\str_contains($type, 'bool')) {
            return ['boolean', 'bool'];
        }
        if (\str_contains($type, 'int')) {
            return [\str_contains($type, 'big') ? 'bigInteger' : 'integer', 'int'];
        }
        if (\in_array($type, ['float', 'double', 'real'], true)) {
            return [$type === 'real' ? 'double' : $type, 'float'];
        }
        if (\str_contains($type, 'decimal') || \str_contains($type, 'numeric')) {
            return ['decimal', 'string'];
        }
        if (\in_array($type, ['datetime', 'timestamp', 'timestamp without time zone', 'timestamp with time zone'], true)) {
            return ['datetime', '\\DateTimeImmutable'];
        }
        if ($type === 'date') {
            return ['date', '\\DateTimeImmutable'];
        }
        if ($type === 'time') {
            return ['time', '\\DateTimeImmutable'];
        }
        if (\str_contains($type, 'blob') || \str_contains($type, 'binary')) {
            return ['binary', 'string'];
        }
        if (\str_contains($type, 'text')) {
            return ['text', 'string'];
        }
        $length = $column['length'];
        return [$length !== null && $length > 0 ? "string({$length})" : 'string', 'string'];
    }

    private function propertyName(string $column): string
    {
        $property = \lcfirst((string) \preg_replace_callback(
            '/_([a-zA-Z0-9])/',
            static fn (array $match): string => \strtoupper($match[1]),
            $column,
        ));
        if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $property)) {
            throw new \InvalidArgumentException("Invalid column name [{$column}].");
        }
        return $property;
    }

    private function table(string $name): string
    {
        $class = \basename(\str_replace('\\', '/', $name));
        $snake = \strtolower((string) \preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
        return \str_ends_with($snake, 's') ? $snake : $snake . 's';
    }
}
