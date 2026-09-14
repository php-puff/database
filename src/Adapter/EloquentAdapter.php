<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Adapter;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Puff\Database\Contract\AdapterInterface;
use Puff\Database\Contract\DatabaseManagerInterface;
use Puff\Di\Container;

final class EloquentAdapter implements AdapterInterface
{
    private ?EloquentConnectionResolver $resolver = null;

    public function __construct(private readonly DatabaseManagerInterface $databases)
    {
    }

    public function name(): string
    {
        return 'eloquent';
    }

    public function register(Container $container): void
    {
        $this->resolver ??= new EloquentConnectionResolver($this->databases);
        $container->instance(EloquentConnectionResolver::class, $this->resolver);
        $container->instance(ConnectionResolverInterface::class, $this->resolver);
        Model::setConnectionResolver($this->resolver);
    }

    public function clear(): void
    {
        $this->resolver?->clear();
    }
}
