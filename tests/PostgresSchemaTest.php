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
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Puff\Database\Adapter\EloquentConnectionResolver;
use Puff\Database\DatabaseManager;
use Puff\Database\Exception\DatabaseException;

#[RequiresPhpExtension('pdo_pgsql')]
final class PostgresSchemaTest extends TestCase
{
    public function testConfiguredAndFiberSelectedSchemasIsolatePuffAndEloquent(): void
    {
        if (\getenv('PUFF_TEST_PGSQL') !== '1') {
            self::markTestSkipped('Set PUFF_TEST_PGSQL=1 to run the PostgreSQL integration test.');
        }

        $host = \getenv('PUFF_TEST_PGSQL_HOST') ?: '127.0.0.1';
        $port = (int) (\getenv('PUFF_TEST_PGSQL_PORT') ?: '5432');
        $database = \getenv('PUFF_TEST_PGSQL_DATABASE') ?: 'prm';
        $username = \getenv('PUFF_TEST_PGSQL_USERNAME') ?: 'postgres';
        $password = \getenv('PUFF_TEST_PGSQL_PASSWORD') ?: 'postgres';
        $admin = new PDO("pgsql:host={$host};port={$port};dbname={$database}", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $suffix = \bin2hex(\random_bytes(5));
        $first = 'puff_schema_a_' . $suffix;
        $second = 'puff_schema_b_' . $suffix;
        $config = [
            'driver' => 'pgsql',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'prefix' => 'puff_',
            'search_path' => $first,
        ];

        $admin->exec('CREATE SCHEMA "' . $first . '"');
        $admin->exec('CREATE SCHEMA "' . $second . '"');
        $manager = new DatabaseManager('pgsql', ['pgsql' => $config]);
        $resolver = new EloquentConnectionResolver($manager);
        Model::setConnectionResolver($resolver);

        try {
            foreach ([$first => 'first', $second => 'second'] as $schema => $value) {
                $admin->exec('CREATE TABLE "' . $schema . '".puff_items (id integer PRIMARY KEY, name text NOT NULL)');
                $admin->prepare('INSERT INTO "' . $schema . '".puff_items (id, name) VALUES (1, ?)')
                    ->execute([$value]);
            }

            self::assertSame($first, $manager->schema());
            self::assertSame('first', $manager->table('items')->first()['name'] ?? null);
            self::assertSame('first', SchemaItem::query()->first()?->getAttribute('name'));
            $manager->disconnectAll();
            $manager->setSchema($second);
            try {
                SchemaItem::query()->first();
                self::fail('A stale Eloquent connection must not read the previous schema.');
            } catch (DatabaseException $exception) {
                self::assertStringContainsString('different database schema', $exception->getMessage());
            }
            $resolver->clear();
            self::assertSame('second', $manager->table('items')->first()['name'] ?? null);
            $manager->disconnectAll();

            $results = [];
            $fibers = [];
            foreach ([$first, $second] as $schema) {
                $fibers[] = new Fiber(static function () use ($manager, $resolver, $schema, &$results): void {
                    $manager->setSchema($schema);
                    $results[$schema]['puff'] = $manager->table('items')->first()['name'] ?? null;
                    Fiber::suspend();
                    $results[$schema]['eloquent'] = SchemaItem::query()->first()?->getAttribute('name');
                    $resolver->clear();
                    $manager->disconnectAll();
                    $results[$schema]['cleared'] = $manager->schema();
                });
            }
            foreach ($fibers as $fiber) {
                $fiber->start();
            }
            foreach ($fibers as $fiber) {
                $fiber->resume();
            }
            self::assertSame(['puff' => 'first', 'eloquent' => 'first', 'cleared' => $first], $results[$first]);
            self::assertSame(['puff' => 'second', 'eloquent' => 'second', 'cleared' => $first], $results[$second]);

            $manager->setSchema('missing_schema_' . $suffix);
            try {
                $manager->connection();
                self::fail('A missing tenant schema must not fall back to public.');
            } catch (DatabaseException $exception) {
                self::assertStringContainsString('does not exist', $exception->getMessage());
            }
            $manager->disconnectAll();

            $resolver->connection();
            $this->expectException(DatabaseException::class);
            $manager->setSchema($second);
        } finally {
            $resolver->clear();
            $manager->disconnectAll();
            Model::unsetConnectionResolver();
            $admin->exec('DROP SCHEMA "' . $first . '" CASCADE');
            $admin->exec('DROP SCHEMA "' . $second . '" CASCADE');
        }
    }
}

final class SchemaItem extends Model
{
    public $timestamps = false;

    protected $table = 'items';
}
