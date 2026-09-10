<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Adapter;

use think\DbManager;

final class ThinkManager extends DbManager
{
    protected function modelMaker(): void
    {
    }

    public function closeAll(): void
    {
        foreach ($this->getInstance() as $connection) {
            $connection->close();
        }
    }
}
