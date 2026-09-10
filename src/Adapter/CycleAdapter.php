<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Adapter;

use Cycle\Database\DatabaseManager as CycleDatabaseManager;
use Cycle\ORM\EntityManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORM;
use Cycle\ORM\ORMInterface;
use Puff\Database\Contract\AdapterInterface;
use Puff\Database\Contract\DatabaseManagerInterface;
use Puff\Di\Container;

final class CycleAdapter implements AdapterInterface
{
    private ?CycleContextFactory $contexts = null;

    public function __construct(
        private readonly DatabaseManagerInterface $databases,
        private readonly string $entityDirectory,
        private readonly string $cacheFile,
    ) {
    }

    public function name(): string
    {
        return 'cycle';
    }

    public function register(Container $container): void
    {
        $this->contexts ??= new CycleContextFactory(
            $this->databases,
            new CycleSchema($this->entityDirectory, $this->cacheFile),
        );
        $contexts = $this->contexts;
        $container->instance(CycleContextFactory::class, $contexts);
        $container->bind(CycleDatabaseManager::class, static fn (): CycleDatabaseManager => $contexts->current()->database);
        $container->bind(ORM::class, static fn (): ORM => $contexts->current()->orm);
        $container->bind(ORMInterface::class, static fn (): ORMInterface => $contexts->current()->orm);
        $container->bind(EntityManager::class, static fn (): EntityManager => $contexts->current()->entityManager);
        $container->bind(EntityManagerInterface::class, static fn (): EntityManagerInterface => $contexts->current()->entityManager);
    }

    public function clear(): void
    {
        $this->contexts?->clear();
    }
}
