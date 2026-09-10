<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Tests;

use PHPUnit\Framework\TestCase;
use Puff\Database\AdapterRegistry;
use Puff\Database\Contract\AdapterInterface;
use Puff\Di\Container;

final class AdapterRegistryTest extends TestCase
{
    public function testRegistersAdaptersAddedBeforeAndAfterActivation(): void
    {
        $registry = new AdapterRegistry();
        $before = new TestAdapter('before');
        $registry->add($before);
        self::assertSame(0, $before->registered);

        $registry->activate(new Container());
        self::assertSame(1, $before->registered);

        $after = new TestAdapter('after');
        $registry->add($after);
        self::assertSame(1, $after->registered);
        self::assertSame(['before', 'after'], $registry->names());

        $registry->clear();
        self::assertSame(1, $before->cleared);
        self::assertSame(1, $after->cleared);
    }

    public function testRejectsDuplicateAdapterNames(): void
    {
        $registry = new AdapterRegistry();
        $registry->add(new TestAdapter('duplicate'));
        $this->expectException(\LogicException::class);
        $registry->add(new TestAdapter('duplicate'));
    }

    public function testResolveKeepsProviderRegistrationIndependentOfOrder(): void
    {
        $container = new Container();
        $adapter = new TestAdapter('third-party');

        AdapterRegistry::resolve($container)->add($adapter);
        $databaseRegistry = AdapterRegistry::resolve($container);
        $databaseRegistry->activate($container);

        self::assertSame($databaseRegistry, $container->make(AdapterRegistry::class));
        self::assertSame(1, $adapter->registered);
    }

    public function testActivationIsIdempotentAndRegistrationFailuresAreVisible(): void
    {
        $container = new Container();
        $registry = new AdapterRegistry();
        $adapter = new TestAdapter('stable');
        $registry->add($adapter);
        $registry->activate($container);
        $registry->activate($container);
        self::assertSame(1, $adapter->registered);

        $this->expectException(\RuntimeException::class);
        $registry->add(new FailingAdapter());
    }
}

final class TestAdapter implements AdapterInterface
{
    public int $registered = 0;
    public int $cleared = 0;

    public function __construct(private readonly string $adapterName)
    {
    }

    public function name(): string
    {
        return $this->adapterName;
    }

    public function register(Container $container): void
    {
        ++$this->registered;
    }

    public function clear(): void
    {
        ++$this->cleared;
    }
}

final class FailingAdapter implements AdapterInterface
{
    public function name(): string
    {
        return 'failing';
    }

    public function register(Container $container): void
    {
        throw new \RuntimeException('Adapter initialization failed.');
    }

    public function clear(): void
    {
    }
}
