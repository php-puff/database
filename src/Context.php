<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database;

use Puff\Database\Contract\ConnectionInterface;

/** @internal */
final class Context
{
    /** @var array<string, ConnectionInterface> */
    public array $connections = [];

    public function close(): void
    {
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }
        $this->connections = [];
    }
}
