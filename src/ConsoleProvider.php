<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database;

use Psr\Container\ContainerInterface;
use Puff\Console\CommandProvider;
use Puff\Console\Contract;
use Puff\Console\GenerateCommand;
use Puff\Console\Generator;
use Puff\Database\Contract\DatabaseManagerInterface;

final class ConsoleProvider implements CommandProvider
{
    /** @return iterable<Contract> */
    public function commands(string $root, ContainerInterface $container): iterable
    {
        $generator = new Generator($root);
        $model = $this->model();
        if ($model !== null) {
            yield new GenerateCommand(
                'model',
                'Database\\Model',
                \dirname(__DIR__) . '/stub/model.stub',
                $generator,
                ['%MODEL%' => $model],
            );
        }
        if (\class_exists(\Cycle\Annotated\Annotation\Entity::class) && $container->has(DatabaseManagerInterface::class)) {
            $databases = $container->get(DatabaseManagerInterface::class);
            if (!$databases instanceof DatabaseManagerInterface) {
                throw new \LogicException('Database manager binding is invalid.');
            }
            yield new EntityCommand($generator, $databases, \dirname(__DIR__) . '/stub/entity.stub');
        }
    }

    private function model(): ?string
    {
        if (\class_exists(\think\Model::class)) {
            return 'think\\Model';
        }

        return \class_exists(\Illuminate\Database\Eloquent\Model::class)
            ? 'Illuminate\\Database\\Eloquent\\Model'
            : null;
    }
}
