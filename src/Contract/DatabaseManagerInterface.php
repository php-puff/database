<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Contract;

use Closure;
use Puff\Database\ConnectionConfig;
use Puff\Database\Query;
use UnitEnum;

interface DatabaseManagerInterface
{
    public function connection(string|UnitEnum|null $name = null): ConnectionInterface;

    public function configuration(string|UnitEnum|null $name = null): ConnectionConfig;

    /** @return array<string, ConnectionConfig> */
    public function configurations(): array;

    public function defaultConnection(): string;

    public function table(string $table, string|UnitEnum|null $connection = null): Query;

    /**
     * @template TResult
     * @param Closure(ConnectionInterface): TResult $callback
     * @return TResult
     */
    public function transaction(Closure $callback, string|UnitEnum|null $name = null): mixed;

    public function disconnect(string|UnitEnum|null $name = null): void;

    public function disconnectAll(): void;
}
