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
use Puff\Database\Driver;
use Puff\Database\Query;

interface ConnectionInterface
{
    public function name(): string;

    public function driver(): Driver;

    public function fingerprint(): string;

    public function table(string $table): Query;

    /**
     * @param array<array-key, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $parameters = []): array;

    /**
     * @param array<array-key, mixed> $parameters
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $parameters = []): ?array;

    /** @param array<array-key, mixed> $parameters */
    public function execute(string $sql, array $parameters = []): int;

    /**
     * @template TResult
     * @param Closure(ConnectionInterface): TResult $callback
     * @return TResult
     */
    public function transaction(Closure $callback): mixed;

    public function lastInsertId(): string|false;

    public function disconnect(): void;
}
