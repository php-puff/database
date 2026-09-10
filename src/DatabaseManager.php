<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database;

use BackedEnum;
use Closure;
use Fiber;
use Psr\Log\LoggerInterface;
use Puff\Database\Contract\ConnectionInterface;
use Puff\Database\Contract\DatabaseManagerInterface;
use Puff\Database\Exception\DatabaseException;
use UnitEnum;
use WeakMap;

final class DatabaseManager implements DatabaseManagerInterface
{
    /** @var WeakMap<object, Context> */
    private WeakMap $fibers;
    private ?Context $main = null;

    /**
     * @param array<string, array<string, mixed>|ConnectionConfig> $connections
     * @param (Closure(): ?LoggerInterface)|null $logger
     */
    public function __construct(
        private readonly string $default,
        array $connections,
        private readonly ?Closure $logger = null,
    ) {
        $normalized = [];
        foreach ($connections as $name => $connection) {
            $normalized[$name] = $connection instanceof ConnectionConfig
                ? $connection
                : ConnectionConfig::fromArray($name, $connection);
        }
        if (!isset($normalized[$default])) {
            throw new DatabaseException("Default database connection [{$default}] is not configured.");
        }
        $this->connections = $normalized;
        $this->fibers = new WeakMap();
    }

    /** @var array<string, ConnectionConfig> */
    private readonly array $connections;

    public function connection(string|UnitEnum|null $name = null): ConnectionInterface
    {
        $name = $this->normalize($name) ?? $this->default;
        $context = $this->context();
        if (!isset($this->connections[$name])) {
            throw new DatabaseException("Database connection [{$name}] is not configured.");
        }
        return $context->connections[$name] ??= new Connection(
            $this->connections[$name],
            $this->connections[$name]->connect(),
            $this->logger === null ? null : ($this->logger)(),
        );
    }

    public function configuration(string|UnitEnum|null $name = null): ConnectionConfig
    {
        $name = $this->normalize($name) ?? $this->default;
        return $this->connections[$name]
            ?? throw new DatabaseException("Database connection [{$name}] is not configured.");
    }

    public function defaultConnection(): string
    {
        return $this->default;
    }

    public function configurations(): array
    {
        return $this->connections;
    }

    public function table(string $table, string|UnitEnum|null $connection = null): Query
    {
        return $this->connection($connection)->table($table);
    }

    public function transaction(Closure $callback, string|UnitEnum|null $name = null): mixed
    {
        return $this->connection($name)->transaction($callback);
    }

    public function disconnect(string|UnitEnum|null $name = null): void
    {
        $context = $this->existingContext();
        if ($context === null) {
            return;
        }
        if ($name === null) {
            $this->disconnectAll();
            return;
        }
        $normalized = $this->normalize($name);
        if ($normalized !== null && isset($context->connections[$normalized])) {
            $context->connections[$normalized]->disconnect();
            unset($context->connections[$normalized]);
        }
    }

    public function disconnectAll(): void
    {
        $this->existingContext()?->close();
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $this->main = null;
        } elseif (isset($this->fibers[$fiber])) {
            unset($this->fibers[$fiber]);
        }
    }

    /** @internal */
    public function context(): Context
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            return $this->main ??= new Context();
        }
        return $this->fibers[$fiber] ??= new Context();
    }

    private function existingContext(): ?Context
    {
        $fiber = Fiber::getCurrent();
        return $fiber === null ? $this->main : ($this->fibers[$fiber] ?? null);
    }

    private function normalize(string|UnitEnum|null $name): ?string
    {
        return match (true) {
            $name instanceof BackedEnum => (string) $name->value,
            $name instanceof UnitEnum => $name->name,
            default => $name,
        };
    }
}
