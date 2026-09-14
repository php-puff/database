<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Adapter;

use BackedEnum;
use Fiber;
use Illuminate\Database\Connection as EloquentConnection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Puff\Database\Contract\DatabaseManagerInterface;
use Puff\Database\Driver;
use UnitEnum;
use WeakMap;

final class EloquentConnectionResolver implements ConnectionResolverInterface
{
    /** @var WeakMap<object, array<string, EloquentConnection>> */
    private WeakMap $fibers;

    /** @var array<string, EloquentConnection> */
    private array $main = [];

    private string $default;

    public function __construct(private readonly DatabaseManagerInterface $databases)
    {
        $this->default = $databases->defaultConnection();
        $this->fibers = new WeakMap();
    }

    public function connection($name = null): EloquentConnection
    {
        $name = match (true) {
            $name instanceof BackedEnum => (string) $name->value,
            $name instanceof UnitEnum => $name->name,
            $name === null, $name === '' => $this->default,
            default => $name,
        };
        $connections = &$this->connections();
        return $connections[$name] ??= $this->create($name);
    }

    public function getDefaultConnection(): string
    {
        return $this->default;
    }

    public function setDefaultConnection($name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Eloquent default connection name must not be empty.');
        }
        $this->databases->configuration($name);
        $this->default = $name;
    }

    public function clear(): void
    {
        $fiber = Fiber::getCurrent();
        $connections = $fiber === null ? $this->main : ($this->fibers[$fiber] ?? []);
        foreach ($connections as $connection) {
            $connection->disconnect();
        }
        if ($fiber === null) {
            $this->main = [];
        } elseif (isset($this->fibers[$fiber])) {
            unset($this->fibers[$fiber]);
        }
    }

    /** @return array<string, EloquentConnection> */
    private function &connections(): array
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            return $this->main;
        }
        if (!isset($this->fibers[$fiber])) {
            $this->fibers[$fiber] = [];
        }
        return $this->fibers[$fiber];
    }

    private function create(string $name): EloquentConnection
    {
        $configuration = $this->databases->configuration($name);
        $pdo = $configuration->connect();
        $config = $configuration->publicValues() + ['name' => $name];
        $connection = match ($configuration->driver) {
            Driver::MySql => new MySqlConnection($pdo, $configuration->database, '', $config),
            Driver::PostgreSql => new PostgresConnection($pdo, $configuration->database, '', $config),
            Driver::SQLite => new SQLiteConnection($pdo, $configuration->database, '', $config),
        };
        return $connection;
    }
}
