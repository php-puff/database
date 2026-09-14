<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Adapter;

use Cycle\Database\DatabaseManager;
use Cycle\ORM\EntityManager;
use Cycle\ORM\ORM;

final readonly class CycleContext
{
    public function __construct(
        public DatabaseManager $database,
        public ORM $orm,
        public EntityManager $entityManager,
    ) {
    }

    public function close(): void
    {
        $this->entityManager->clean(true);
        foreach ($this->database->getDrivers() as $driver) {
            $driver->disconnect();
        }
    }
}
