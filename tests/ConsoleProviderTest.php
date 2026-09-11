<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Tests;

use PHPUnit\Framework\TestCase;
use Puff\Console\Generator;
use Puff\Console\Input;
use Puff\Console\Output;
use Puff\Database\ConsoleProvider;
use Puff\Database\Contract\ConnectionInterface;
use Puff\Database\Contract\DatabaseManagerInterface;
use Puff\Database\DatabaseManager;
use Puff\Database\Driver;
use Puff\Database\EntityCommand;
use Puff\Di\Container;

final class ConsoleProviderTest extends TestCase
{
    public function testRegistersInstalledOrmGenerators(): void
    {
        $names = [];
        foreach ((new ConsoleProvider())->commands(__DIR__, $this->container()) as $command) {
            $names[] = $command->name();
        }

        self::assertSame(['model', 'entity'], $names);
    }

    public function testGeneratesOrmModelsFromSharedStub(): void
    {
        $root = \sys_get_temp_dir() . '/puff-model-' . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($root));
        $commands = \iterator_to_array((new ConsoleProvider())->commands($root, $this->container()));
        $stream = \fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        self::assertSame(0, $commands[0]->execute(Input::parse(['User']), new Output($stream)));
        $model = $root . '/database/Model/User.php';
        self::assertStringContainsString('use think\\Model;', (string) \file_get_contents($model));

        \unlink($model);
        \rmdir($root . '/database/Model');
        \rmdir($root . '/database');
        \rmdir($root);
    }

    public function testGeneratesEntityFromTableDefinition(): void
    {
        $root = \sys_get_temp_dir() . '/puff-entity-' . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($root));
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('name')->willReturn('sqlite');
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('fetchAll')->willReturn([
            ['name' => 'id', 'type' => 'INTEGER', 'notnull' => 1, 'pk' => 1],
            ['name' => 'display_name', 'type' => 'VARCHAR', 'notnull' => 0, 'pk' => 0],
        ]);
        $databases = $this->createMock(DatabaseManagerInterface::class);
        $databases->method('connection')->willReturn($connection);
        $command = new EntityCommand(new Generator($root), $databases, \dirname(__DIR__) . '/stub/entity.stub');
        $stream = \fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        self::assertSame(0, $command->execute(Input::parse(['Users']), new Output($stream)));
        $entity = (string) \file_get_contents($root . '/database/Entity/Users.php');
        self::assertStringContainsString("#[Entity(table: 'users')]", $entity);
        self::assertStringContainsString("#[Column(type: 'primary')]", $entity);
        self::assertStringContainsString("#[Column(type: 'string', name: 'display_name', nullable: true)]", $entity);
        self::assertStringContainsString('public ?string $displayName = null;', $entity);

        \unlink($root . '/database/Entity/Users.php');
        \rmdir($root . '/database/Entity');
        \rmdir($root . '/database');
        \rmdir($root);
    }

    private function container(): Container
    {
        $container = new Container();
        $manager = new DatabaseManager('sqlite', ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $container->instance(DatabaseManagerInterface::class, $manager);

        return $container;
    }
}
