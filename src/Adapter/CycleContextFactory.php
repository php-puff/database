<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Adapter;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\MySQL\TcpConnectionConfig as MySqlConnectionConfig;
use Cycle\Database\Config\MySQLDriverConfig;
use Cycle\Database\Config\Postgres\TcpConnectionConfig as PostgresConnectionConfig;
use Cycle\Database\Config\PostgresDriverConfig;
use Cycle\Database\Config\SQLite\FileConnectionConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\EntityManager;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Fiber;
use Puff\Database\ConnectionConfig;
use Puff\Database\Contract\DatabaseManagerInterface;
use Puff\Database\Driver;
use WeakMap;

final class CycleContextFactory
{
    /** @var WeakMap<object, CycleContext> */
    private WeakMap $fibers;

    private ?CycleContext $main = null;

    public function __construct(
        private readonly DatabaseManagerInterface $databases,
        private readonly CycleSchema $schema,
    ) {
        $this->fibers = new WeakMap();
    }

    public function current(): CycleContext
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            return $this->main ??= $this->create();
        }
        return $this->fibers[$fiber] ??= $this->create();
    }

    public function clear(): void
    {
        $fiber = Fiber::getCurrent();
        $context = $fiber === null ? $this->main : ($this->fibers[$fiber] ?? null);
        $context?->close();
        if ($fiber === null) {
            $this->main = null;
        } elseif (isset($this->fibers[$fiber])) {
            unset($this->fibers[$fiber]);
        }
    }

    private function create(): CycleContext
    {
        $database = $this->database();
        $orm = new ORM(new Factory($database), new Schema($this->schema->load($database)));
        return new CycleContext($database, $orm, new EntityManager($orm));
    }

    private function database(): DatabaseManager
    {
        $connections = [];
        $databases = [];
        foreach ($this->databases->configurations() as $name => $configuration) {
            $connections[$name] = $this->driver($configuration);
            $databases[$name] = ['connection' => $name];
        }
        return new DatabaseManager(new DatabaseConfig([
            'default' => $this->databases->defaultConnection(),
            'databases' => $databases,
            'connections' => $connections,
        ]));
    }

    private function driver(ConnectionConfig $config): MySQLDriverConfig|PostgresDriverConfig|SQLiteDriverConfig
    {
        $charset = $config->charset === '' ? null : $config->charset;
        $username = $config->username === '' ? null : $config->username;
        $password = $config->password === '' ? null : $config->password;
        $options = $this->cycleOptions($config->options);
        return match ($config->driver) {
            Driver::MySql => new MySQLDriverConfig(new MySqlConnectionConfig(
                $this->nonEmpty($config->database, 'database'),
                $this->nonEmpty($config->host, 'host'),
                $this->positive($config->port, 'port'),
                $charset,
                $username,
                $password,
                $options,
            )),
            Driver::PostgreSql => new PostgresDriverConfig(new PostgresConnectionConfig(
                $this->nonEmpty($config->database, 'database'),
                $this->nonEmpty($config->host, 'host'),
                $this->positive($config->port, 'port'),
                $username,
                $password,
                $options,
            )),
            Driver::SQLite => new SQLiteDriverConfig(
                $config->database === ':memory:'
                    ? new MemoryConnectionConfig($options)
                    : new FileConnectionConfig($config->database, $options),
            ),
        };
    }

    /** @return non-empty-string */
    private function nonEmpty(string $value, string $field): string
    {
        if ($value === '') {
            throw new \InvalidArgumentException("Cycle database {$field} must not be empty.");
        }
        return $value;
    }

    /** @return positive-int */
    private function positive(int $value, string $field): int
    {
        if ($value < 1) {
            throw new \InvalidArgumentException("Cycle database {$field} must be positive.");
        }
        return $value;
    }

    /**
     * @param array<int, mixed> $options
     * @return array<0|1|2|3|4|5|6|7|8|9|10|11|12|13|14|15|16|17|18|19|20|21, mixed>
     */
    private function cycleOptions(array $options): array
    {
        return \array_filter(
            $options,
            static fn (int $key): bool => $key >= 0 && $key <= 21,
            ARRAY_FILTER_USE_KEY,
        );
    }
}
