<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database;

use Puff\Database\Contract\AdapterInterface;
use Puff\Di\Container;

final class AdapterRegistry
{
    /** @var array<string, AdapterInterface> */
    private array $adapters = [];

    /** @var array<string, true> */
    private array $registered = [];

    private ?Container $container = null;

    public static function resolve(Container $container): self
    {
        $container->singletonIf(self::class, static fn (): self => new self());
        $registry = $container->make(self::class);
        if (!$registry instanceof self) {
            throw new \LogicException('Database adapter registry binding is invalid.');
        }
        return $registry;
    }

    public function add(AdapterInterface $adapter): void
    {
        $name = \trim($adapter->name());
        if ($name === '') {
            throw new \InvalidArgumentException('Database adapter name must not be empty.');
        }
        if (isset($this->adapters[$name])) {
            throw new \LogicException("Database adapter [{$name}] is already registered.");
        }
        $this->adapters[$name] = $adapter;
        if ($this->container !== null) {
            $this->register($name, $adapter);
        }
    }

    public function activate(Container $container): void
    {
        if ($this->container !== null && $this->container !== $container) {
            throw new \LogicException('Database adapter registry is already active in another container.');
        }
        $this->container = $container;
        foreach ($this->adapters as $name => $adapter) {
            $this->register($name, $adapter);
        }
    }

    /** @return list<string> */
    public function names(): array
    {
        return \array_keys($this->adapters);
    }

    public function has(string $name): bool
    {
        return isset($this->adapters[$name]);
    }

    public function clear(): void
    {
        foreach ($this->adapters as $adapter) {
            $adapter->clear();
        }
    }

    private function register(string $name, AdapterInterface $adapter): void
    {
        if (isset($this->registered[$name])) {
            return;
        }
        $adapter->register($this->container ?? throw new \LogicException('Database adapter registry is not active.'));
        $this->registered[$name] = true;
    }
}
