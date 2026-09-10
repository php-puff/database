<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Tests;

use PHPUnit\Framework\TestCase;
use Puff\Database\ConnectionConfig;
use Puff\Database\Driver;

final class ConnectionConfigTest extends TestCase
{
    public function testNormalizesDriverDefaultsWithoutExposingCredentials(): void
    {
        $sqlite = ConnectionConfig::fromArray('sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'password' => 'secret',
        ]);

        self::assertSame(0, $sqlite->port);
        self::assertSame('sqlite::memory:', $sqlite->dsn());
        self::assertArrayNotHasKey('password', $sqlite->publicValues());
        self::assertFalse(Driver::SQLite->supportsAdvisoryLocks());
        self::assertTrue(Driver::PostgreSql->supportsTransactionalDdl());
        self::assertFalse(Driver::MySql->supportsTransactionalDdl());
    }
}
