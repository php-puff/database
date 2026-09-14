<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database;

use Psr\Log\LoggerInterface;
use Puff\Config\Config;
use Puff\Database\Adapter\CycleAdapter;
use Puff\Database\Adapter\EloquentAdapter;
use Puff\Database\Adapter\ThinkAdapter;
use Puff\Database\Contract\DatabaseManagerInterface;
use Puff\Database\Exception\DatabaseException;
use Puff\Di\ServiceProvider as BaseServiceProvider;

final class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $registry = AdapterRegistry::resolve($this->app);
        if (!$this->app->bound(Config::class)) {
            return;
        }
        $config = $this->app->make(Config::class)->get('database', []);
        if (!\is_array($config) || !\is_array($config['connections'] ?? null) || $config['connections'] === []) {
            return;
        }
        $connections = [];
        foreach ($config['connections'] as $name => $connection) {
            if (!\is_string($name) || $name === '' || !\is_array($connection)) {
                throw new DatabaseException('Database connections must use non-empty string names and array configurations.');
            }
            $connections[$name] = $connection;
        }
        $default = (string) ($config['default'] ?? \array_key_first($connections));
        if (!isset($connections[$default])) {
            throw new DatabaseException("Default database connection [{$default}] is not configured.");
        }
        $logger = function (): ?LoggerInterface {
            if (!$this->app->bound(LoggerInterface::class)) {
                return null;
            }
            $logger = $this->app->make(LoggerInterface::class);
            return $logger instanceof LoggerInterface ? $logger : null;
        };
        $manager = new DatabaseManager($default, $connections, $logger);
        $this->app->instance(DatabaseManager::class, $manager);
        $this->app->instance(DatabaseManagerInterface::class, $manager);
        $this->app->onScopeClear($manager->disconnectAll(...));

        if (\class_exists(\Illuminate\Database\Eloquent\Model::class) && !$registry->has('eloquent')) {
            $registry->add(new EloquentAdapter($manager));
        }
        if (
            \class_exists(\Cycle\ORM\ORM::class)
            && \class_exists(\Cycle\Database\DatabaseManager::class)
            && \class_exists(\Cycle\Annotated\Entities::class)
            && !$registry->has('cycle')
        ) {
            $runtime = (string) $this->app->make(Config::class)->get('runtime', \sys_get_temp_dir() . '/puff');
            $root = \dirname($runtime);
            $registry->add(new CycleAdapter(
                $manager,
                $root . '/database/Entity',
                $runtime . '/database/cycle.php',
            ));
        }
        if (\class_exists(\think\Model::class) && !$registry->has('think')) {
            $registry->add(new ThinkAdapter($manager));
        }
        $registry->activate($this->app);
        $this->app->onScopeClear($registry->clear(...));
    }
}
