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
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Puff\Config\Config;
use Puff\Database\AdapterRegistry;
use Puff\Database\Connection;
use Puff\Database\Contract\AdapterInterface;
use Puff\Database\Contract\DatabaseManagerInterface;
use Puff\Database\Exception\DatabaseException;
use Puff\Database\ServiceProvider;
use Puff\Di\Container;

final class ServiceProviderTest extends TestCase
{
    public function testDoesNothingWithoutConfiguration(): void
    {
        $container = new Container();
        (new ServiceProvider($container))->register();
        self::assertFalse($container->bound(DatabaseManagerInterface::class));
    }

    public function testRejectsUnknownDefaultConnection(): void
    {
        $container = new Container();
        $container->instance(Config::class, new Config(['database' => [
            'default' => 'missing',
            'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        $this->expectException(DatabaseException::class);
        (new ServiceProvider($container))->register();
    }

    public function testRegistersManagerAndLogsQueries(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $container = new Container();
        $logger = new MemoryLogger();
        $container->instance(LoggerInterface::class, $logger);
        $container->instance(Config::class, new Config(['database' => [
            'default' => 'sqlite',
            'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        (new ServiceProvider($container))->register();
        $database = $container->make(DatabaseManagerInterface::class);
        self::assertSame(['value' => 'Puff'], $database->connection()->fetchOne('SELECT ? AS value', ['Puff']));
        self::assertSame('SELECT ? AS value', $logger->messages[0] ?? null);

        $connection = $database->connection();
        self::assertInstanceOf(Connection::class, $connection);
        $pdo = $connection->pdo();
        self::assertTrue($pdo->beginTransaction());
        $container->clearScope();
        self::assertFalse($pdo->inTransaction());
        $this->expectException(\LogicException::class);
        $connection->fetchOne('SELECT 1');
    }

    public function testKeepsAdapterRegisteredBeforeDatabaseProviderLoads(): void
    {
        $container = new Container();
        $adapter = new ProviderOrderAdapter();
        AdapterRegistry::resolve($container)->add($adapter);
        $container->instance(Config::class, new Config(['database' => [
            'default' => 'sqlite',
            'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));

        (new ServiceProvider($container))->register();

        self::assertSame(1, $adapter->registered);
        self::assertTrue(AdapterRegistry::resolve($container)->has('provider-order'));
        self::assertTrue(AdapterRegistry::resolve($container)->has('think'));
    }
}

final class ProviderOrderAdapter implements AdapterInterface
{
    public int $registered = 0;

    public function name(): string
    {
        return 'provider-order';
    }

    public function register(Container $container): void
    {
        ++$this->registered;
    }

    public function clear(): void
    {
    }
}

final class MemoryLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    /** @param array<string, mixed> $context */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }
}
