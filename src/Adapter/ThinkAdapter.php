<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Adapter;

use Puff\Database\Contract\AdapterInterface;
use Puff\Database\Contract\DatabaseManagerInterface;
use Puff\Di\Container;
use think\DbManager;

final class ThinkAdapter implements AdapterInterface
{
    private ?ThinkManagerResolver $resolver = null;

    public function __construct(private readonly DatabaseManagerInterface $databases)
    {
    }

    public function name(): string
    {
        return 'think';
    }

    public function register(Container $container): void
    {
        $this->resolver ??= new ThinkManagerResolver($this->databases);
        ThinkModelContext::register($this->resolver);
        $resolver = $this->resolver;
        $container->instance(ThinkManagerResolver::class, $resolver);
        $container->bind(ThinkManager::class, static fn (): ThinkManager => $resolver->current());
        $container->bind(DbManager::class, static fn (): DbManager => $resolver->current());
    }

    public function clear(): void
    {
        $this->resolver?->clear();
    }
}
