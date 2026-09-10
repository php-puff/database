<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Tests;

use Cycle\Database\DatabaseManager as CycleDatabaseManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Fiber;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Puff\Database\Adapter\CycleAdapter;
use Puff\Database\Adapter\EloquentAdapter;
use Puff\Database\Adapter\ThinkAdapter;
use Puff\Database\Adapter\ThinkManagerResolver;
use Puff\Database\AdapterRegistry;
use Puff\Database\DatabaseManager;
use Puff\Database\Tests\Fixtures\CycleUser;
use Puff\Di\Container;
use think\db\PDOConnection as ThinkConnection;
use think\DbManager as ThinkDbManager;
use think\Model as ThinkModel;

final class OrmAdapterTest extends TestCase
{
    public function testThinkManagersAndModelsAreIsolatedByFiber(): void
    {
        $container = new Container();
        $registry = new AdapterRegistry();
        $registry->add(new ThinkAdapter($this->manager()));
        $registry->activate($container);

        $main = $container->make(ThinkDbManager::class);
        self::assertInstanceOf(ThinkDbManager::class, $main);
        self::assertSame($main, (new ThinkUser())->getOption('db'));

        $fiberManager = null;
        $fiberModelManager = null;
        $fiber = new Fiber(function () use ($container, $registry, &$fiberManager, &$fiberModelManager): void {
            $fiberManager = $container->make(ThinkDbManager::class);
            $fiberModelManager = (new ThinkUser())->getOption('db');
            $registry->clear();
        });
        $fiber->start();

        self::assertInstanceOf(ThinkDbManager::class, $fiberManager);
        self::assertNotSame($main, $fiberManager);
        self::assertSame($fiberManager, $fiberModelManager);
        self::assertInstanceOf(ThinkManagerResolver::class, $container->make(ThinkManagerResolver::class));
        $registry->clear();
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testThinkModelCrud(): void
    {
        $container = new Container();
        $registry = new AdapterRegistry();
        $registry->add(new ThinkAdapter($this->manager()));
        $registry->activate($container);
        $database = $container->make(ThinkDbManager::class);
        self::assertInstanceOf(ThinkDbManager::class, $database);
        $connection = $database->connect();
        if (!$connection instanceof ThinkConnection) {
            self::fail('Think ORM must create a PDO connection.');
        }
        $connection->execute(
            'CREATE TABLE think_user ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'name TEXT NOT NULL, '
            . 'create_time DATETIME NULL, '
            . 'update_time DATETIME NULL)',
        );

        $user = new ThinkUser(['name' => 'Puff']);
        self::assertTrue($user->save());
        self::assertSame('Puff', $user->getAttr('name'));
        $found = ThinkUser::find($user->getAttr('id'));
        if (!$found instanceof ThinkUser) {
            self::fail('Think ORM did not return the expected model.');
        }
        self::assertSame('Puff', $found->getAttr('name'));
        $registry->clear();
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testEloquentConnectionsAreIsolatedByFiber(): void
    {
        $manager = $this->manager();
        $container = new Container();
        $registry = new AdapterRegistry();
        $registry->add(new EloquentAdapter($manager));
        $registry->activate($container);

        $resolver = $container->make(ConnectionResolverInterface::class);
        self::assertInstanceOf(ConnectionResolverInterface::class, $resolver);
        $main = $resolver->connection();
        $main->statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        EloquentUser::query()->create(['name' => 'Puff']);
        self::assertSame('Puff', EloquentUser::query()->first()?->getAttribute('name'));
        $fiberConnection = null;
        $fiber = new Fiber(function () use ($resolver, &$fiberConnection, $registry): void {
            $fiberConnection = $resolver->connection();
            $registry->clear();
        });
        $fiber->start();

        self::assertNotSame($main, $fiberConnection);
        $registry->clear();
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testCycleContextsAreIsolatedByFiberAndUseCachedSchema(): void
    {
        self::assertTrue(\class_exists(CycleUser::class));
        $cache = \sys_get_temp_dir() . '/puff-cycle-' . \bin2hex(\random_bytes(6)) . '.php';
        $container = new Container();
        $registry = new AdapterRegistry();
        $registry->add(new CycleAdapter($this->manager(), __DIR__ . '/Fixtures', $cache));
        $registry->activate($container);
        try {
            $mainOrm = $container->make(ORMInterface::class);
            self::assertInstanceOf(ORMInterface::class, $mainOrm);
            self::assertContains('user', $mainOrm->getSchema()->getRoles());
            $cycleDatabase = $container->make(CycleDatabaseManager::class);
            self::assertInstanceOf(CycleDatabaseManager::class, $cycleDatabase);
            $cycleDatabase->database()->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT)');
            $entityManager = $container->make(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $user = new CycleUser();
            $entityManager->persist($user)->run();
            self::assertSame(1, $user->id);

            $fiberOrm = null;
            $fiber = new Fiber(function () use ($container, &$fiberOrm, $registry): void {
                $fiberOrm = $container->make(ORMInterface::class);
                $registry->clear();
            });
            $fiber->start();
            self::assertNotSame($mainOrm, $fiberOrm);
            self::assertFileExists($cache);
        } finally {
            $registry->clear();
            @\unlink($cache);
        }
    }

    private function manager(): DatabaseManager
    {
        return new DatabaseManager('sqlite', [
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
    }
}

final class EloquentUser extends EloquentModel
{
    public $timestamps = false;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['name'];
}

final class ThinkUser extends ThinkModel
{
}
