<?php

/*
 * PHP Fiber Framework
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
        public array $options = [],
    ) {
        if ($name === '' || !\preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $name)) {
            throw new DatabaseException('Database connection name is invalid.');
        }
        if ($database === '') {
            throw new DatabaseException("Database connection [{$name}] must define a database.");
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
            $options,
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

    public function connect(): PDO
    {
        $options = $this->options + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        try {
            return new PDO($this->dsn(), $this->username, $this->password, $options);
        } catch (\PDOException $exception) {
            throw new DatabaseException("Unable to connect to database [{$this->name}].", 0, $exception);
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
        ];
    }
}
