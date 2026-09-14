<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database;

enum Driver: string
{
    case MySql = 'mysql';
    case PostgreSql = 'pgsql';
    case SQLite = 'sqlite';

    public function supportsTransactionalDdl(): bool
    {
        return $this !== self::MySql;
    }

    public function supportsSavepoints(): bool
    {
        return true;
    }

    public function supportsAdvisoryLocks(): bool
    {
        return $this !== self::SQLite;
    }

    public function quote(string $identifier): string
    {
        if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid SQL identifier [{$identifier}].");
        }
        $quote = $this === self::MySql ? '`' : '"';
        return $quote . $identifier . $quote;
    }
}
