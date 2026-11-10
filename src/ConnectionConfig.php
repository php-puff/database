<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database;

use PDO;
use Puff\Database\Exception\DatabaseException;

final readonly class ConnectionConfig
{
    /** @param array<int, mixed> $options */
    public function __construct(
        public string $name,
        public Driver $driver,
        public string $database,
        public string $host = '127.0.0.1',
        public int $port = 0,
        public string $username = '',
        public string $password = '',
        public string $charset = 'utf8mb4',
        public string $prefix = '',
        public array $options = [],
        public ?string $searchPath = null,
    ) {
        if ($name === '' || !\preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $name)) {
            throw new DatabaseException('Database connection name is invalid.');
        }
        if ($database === '') {
            throw new DatabaseException("Database connection [{$name}] must define a database.");
        }
        if ($prefix !== '' && \preg_match('/^[A-Za-z0-9_]+$/D', $prefix) !== 1) {
            throw new DatabaseException("Database connection [{$name}] has an invalid table prefix.");
        }
        if ($searchPath !== null) {
            self::validateSchema($searchPath);
        }
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(string $name, array $values): self
    {
        $driver = Driver::tryFrom((string) ($values['driver'] ?? ''))
            ?? throw new DatabaseException("Database connection [{$name}] has an unsupported driver.");
        $options = $values['options'] ?? [];
        if (!\is_array($options)) {
            throw new DatabaseException("Database connection [{$name}] options must be an array.");
        }
        $searchPath = $values['search_path'] ?? null;
        if ($searchPath !== null && !\is_string($searchPath)) {
            throw new DatabaseException("Database connection [{$name}] search_path must be a schema name.");
        }
        /** @var array<int, mixed> $options */
        return new self(
            $name,
            $driver,
            (string) ($values['database'] ?? ''),
            (string) ($values['host'] ?? '127.0.0.1'),
            (int) ($values['port'] ?? match ($driver) {
                Driver::MySql => 3306,
                Driver::PostgreSql => 5432,
                Driver::SQLite => 0,
            }),
            (string) ($values['username'] ?? ''),
            (string) ($values['password'] ?? ''),
            (string) ($values['charset'] ?? 'utf8mb4'),
            (string) ($values['prefix'] ?? ''),
            $options,
            $searchPath,
        );
    }

    public function dsn(): string
    {
        if ($this->driver === Driver::SQLite) {
            return 'sqlite:' . $this->database;
        }
        $dsn = \sprintf(
            '%s:host=%s;port=%d;dbname=%s',
            $this->driver->value,
            $this->host,
            $this->port,
            $this->database,
        );
        return $this->driver === Driver::MySql ? $dsn . ';charset=' . $this->charset : $dsn;
    }

    public function connect(?string $schema = null): PDO
    {
        $options = $this->options + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        try {
            $pdo = new PDO($this->dsn(), $this->username, $this->password, $options);
            $schema ??= $this->searchPath;
            if ($this->driver === Driver::PostgreSql && $schema !== null) {
                self::validateSchema($schema);
                $statement = $pdo->prepare('SELECT 1 FROM pg_catalog.pg_namespace WHERE nspname = ?');
                $statement->execute([$schema]);
                if ($statement->fetchColumn() === false) {
                    throw new DatabaseException("Database schema [{$schema}] does not exist.");
                }
                $statement = $pdo->prepare("SELECT pg_catalog.set_config('search_path', ?, false)");
                $statement->execute(['"' . $schema . '"']);
            }
            return $pdo;
        } catch (\PDOException $exception) {
            throw new DatabaseException("Unable to connect to database [{$this->name}].", 0, $exception);
        }
    }

    public static function validateSchema(string $schema): void
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new DatabaseException('Database schema name is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function publicValues(): array
    {
        return [
            'driver' => $this->driver->value,
            'database' => $this->database,
            'host' => $this->host,
            'port' => $this->port,
            'charset' => $this->charset,
            'prefix' => $this->prefix,
            'search_path' => $this->searchPath,
        ];
    }
}
