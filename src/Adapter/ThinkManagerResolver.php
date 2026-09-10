<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Adapter;

use Fiber;
use Puff\Database\ConnectionConfig;
use Puff\Database\Contract\DatabaseManagerInterface;
use Puff\Database\Driver;
use WeakMap;

final class ThinkManagerResolver
{
    /** @var WeakMap<object, ThinkManager> */
    private WeakMap $fibers;

    private ?ThinkManager $main = null;

    public function __construct(private readonly DatabaseManagerInterface $databases)
    {
        $this->fibers = new WeakMap();
    }

    public function current(): ThinkManager
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
        $manager = $fiber === null ? $this->main : ($this->fibers[$fiber] ?? null);
        $manager?->closeAll();
        if ($fiber === null) {
            $this->main = null;
        } elseif (isset($this->fibers[$fiber])) {
            unset($this->fibers[$fiber]);
        }
    }

    private function create(): ThinkManager
    {
        $connections = [];
        foreach ($this->databases->configurations() as $name => $configuration) {
            $connections[$name] = $this->configuration($configuration);
        }

        $manager = new ThinkManager();
        $manager->setConfig([
            'default' => $this->databases->defaultConnection(),
            'connections' => $connections,
        ]);

        return $manager;
    }

    /** @return array<string, mixed> */
    private function configuration(ConnectionConfig $config): array
    {
        return [
            'type' => match ($config->driver) {
                Driver::MySql => 'mysql',
                Driver::PostgreSql => 'pgsql',
                Driver::SQLite => 'sqlite',
            },
            'hostname' => $config->host,
            'hostport' => $config->port,
            'database' => $config->database,
            'username' => $config->username,
            'password' => $config->password,
            'charset' => $config->charset,
            'params' => $config->options,
            'prefix' => '',
            'break_reconnect' => false,
        ];
    }
}
