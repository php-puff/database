<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Contract;

use Puff\Di\Container;

interface AdapterInterface
{
    public function name(): string;

    public function register(Container $container): void;

    public function clear(): void;
}
