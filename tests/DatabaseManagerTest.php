<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Tests;

use Fiber;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Puff\Database\DatabaseManager;

#[RequiresPhpExtension('pdo_sqlite')]
final class DatabaseManagerTest extends TestCase
{
    public function testQueriesAndTransactions(): void
    {
        $manager = $this->manager();
        $manager->connection()->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $id = $manager->table('users')->insert(['name' => 'Puff']);
        self::assertSame(1, $id);
        self::assertSame('Puff', $manager->table('users')->where('id', $id)->first()['name'] ?? null);
        self::assertSame(1, $manager->table('users')->count());

        try {
            $manager->transaction(static function ($connection): void {
                $connection->table('users')->insert(['name' => 'Rollback']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            self::assertSame('rollback', $exception->getMessage());
        }
        self::assertSame(1, $manager->table('users')->count());
    }

    public function testNestedTransactionRollsBackOnlyTheFailedSavepoint(): void
    {
        $manager = $this->manager();
        $manager->connection()->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        $manager->transaction(static function ($connection): void {
            $connection->table('users')->insert(['name' => 'outer']);
            try {
                $connection->transaction(static function ($connection): void {
                    $connection->table('users')->insert(['name' => 'inner']);
                    throw new \RuntimeException('rollback savepoint');
                });
            } catch (\RuntimeException) {
            }
            self::assertSame(1, $connection->table('users')->count());
        });

        self::assertSame([['name' => 'outer']], $manager->table('users')->select('name')->get());
    }

    public function testBuilderUpdatesDeletesAndRejectsIdentifiers(): void
    {
        $manager = $this->manager();
        $manager->connection()->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $manager->table('users')->insert(['name' => 'Puff']);
        self::assertSame(1, $manager->table('users')->where('name', 'Puff')->update(['name' => 'PHP']));
        self::assertSame([['name' => 'PHP']], $manager->table('users')->select('name')->orderBy('id')->get());
        self::assertSame(1, $manager->table('users')->where('name', 'PHP')->delete());

        $this->expectException(\InvalidArgumentException::class);
        $manager->table('users; DROP TABLE users');
    }

    public function testReusesConnectionWithinFiberAndIsolatesFibers(): void
    {
        $manager = $this->manager();
        $connections = [];
        $fibers = [];
        for ($index = 0; $index < 2; ++$index) {
            $fibers[] = new Fiber(function () use ($manager, &$connections): void {
                $first = $manager->connection();
                self::assertSame($first, $manager->connection());
                $connections[] = $first;
                Fiber::suspend();
                $manager->disconnectAll();
            });
        }
        foreach ($fibers as $fiber) {
            $fiber->start();
        }
        self::assertNotSame($connections[0], $connections[1]);
        foreach ($fibers as $fiber) {
            $fiber->resume();
        }
    }

    private function manager(): DatabaseManager
    {
        return new DatabaseManager('sqlite', ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    }
}
