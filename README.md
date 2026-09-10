# Puff Database

`puff/database` is Puff's Fiber-scoped data-access core. It provides normalized PDO connections, nested transactions, a small immutable query builder, and an adapter registry for optional ORMs. It does not define a common Model or Entity API.

## Requirements and installation

- PHP 8.2 or newer
- PDO and the PDO driver used by the application
- `puff/config` and `puff/di`

```bash
composer require puff/database
```

The package publishes `config/database.php` and registers its provider through Composer discovery. Configure one or more named connections:

```php
return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => dirname(__DIR__) . '/runtime/database.sqlite',
        ],
        'mysql' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'puff',
            'username' => 'puff',
            'password' => '',
            'charset' => 'utf8mb4',
            'options' => [],
        ],
    ],
];
```

Supported drivers are MySQL, PostgreSQL, and SQLite. Configuration is normalized into an immutable `ConnectionConfig`; credentials are never included in its public values or query logs.

## Core API

Inject `Puff\Database\Contract\DatabaseManagerInterface` rather than the concrete manager:

```php
use Puff\Database\Contract\DatabaseManagerInterface;

final readonly class UserRepository
{
    public function __construct(private DatabaseManagerInterface $database)
    {
    }

    public function active(): array
    {
        return $this->database
            ->table('users')
            ->select('id', 'email')
            ->where('active', true)
            ->orderBy('id', 'DESC')
            ->limit(50)
            ->get();
    }
}
```

The query API supports `select`, `where`, `orderBy`, `limit`, `get`, `first`, `count`, `insert`, `update`, and `delete`. Values use prepared-statement bindings, while table and column identifiers are strictly validated.

Raw parameterized queries remain available:

```php
$connection = $database->connection('mysql');
$row = $connection->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
$affected = $connection->execute('UPDATE users SET active = ? WHERE id = ?', [true, $id]);
```

Nested transactions use savepoints:

```php
$database->transaction(static function ($connection): void {
    $connection->table('accounts')->where('id', 1)->update(['balance' => 100]);

    $connection->transaction(static function ($connection): void {
        $connection->table('audit')->insert(['event' => 'balance.updated']);
    });
});
```

## Fiber lifecycle

Each Fiber receives its own connection context. Connections are reused inside that Fiber only; transactions and PDO instances are not shared with another request Fiber. `Container::clearScope()` rolls back unfinished transactions, disconnects the current Fiber's PDO connections, and clears the current Fiber's context in every active ORM adapter.

PDO itself is synchronous. Fiber isolation prevents state leakage, but a slow database call still blocks its worker. Use multiple workers or a genuinely asynchronous driver when blocking latency is unacceptable.

Query logs contain the SQL template, execution time, row count, connection name, and binding types. Binding values and passwords are not logged.

## Optional ORM adapters

No ORM is required by default. Installing a supported package makes its built-in adapter available automatically; no `default_orm` or `orms` configuration is used.

```bash
# PHP 8.2-compatible Eloquent
composer require illuminate/database:^12.0

# Cycle ORM, database layer, and attribute mapping
composer require cycle/annotated:^4.6

# Think ORM
composer require topthink/think-orm:^4.0
```

For Cycle integration, install `cycle/annotated`; it already requires compatible versions of `cycle/orm` and `cycle/database`. Installing only `cycle/database` provides DBAL without the ORM, while installing only `cycle/orm` does not provide the attribute mapper required by Puff's automatic `Entity` discovery.

### Eloquent

Models use the `Model` namespace, live in `model/`, and extend `Illuminate\Database\Eloquent\Model` directly. The adapter installs a Fiber-aware Illuminate connection resolver; each Fiber owns its Illuminate connections and transaction state.

```php
namespace Model;

use Illuminate\Database\Eloquent\Model;

final class User extends Model
{
    protected $fillable = ['email', 'name'];
}
```

### Cycle ORM

Entities use the `Entity` namespace, live in `entity/`, and use Cycle attributes. Every Fiber receives an independent `EntityManager`, `UnitOfWork`, ORM, and Cycle database context. The read-only compiled mapping is cached in `runtime/database/cycle.php`; changes to PHP entity files invalidate the cache by checksum.

When `puff/console` is available, this package registers ORM generators automatically. The `model` command uses Think ORM when it is installed and falls back to Eloquent. The `entity` command is exposed when Cycle Annotated is installed:

```bash
./puff model User
./puff entity User
# Explicit table or connection when the defaults differ
./puff entity Users --table=users --connection=mysql
```

`entity` reads the database DDL by default. `Users` resolves to the `users` table, then generates `Entity\\Users` with Cycle `#[Entity]` and `#[Column]` attributes for the table, columns, primary key, nullable fields, and supported scalar types. Existing files remain protected unless `--force` is supplied.

```php
namespace Entity;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;

#[Entity]
final class User
{
    #[Column(type: 'bigPrimary')]
    public int $id;
}
```

### Think ORM

Think models use `Model`, live in `model/`, and extend `think\Model`. Puff binds `think\DbManager` to a Fiber-aware resolver: each Fiber receives an independent manager, connection cache, PDO connection, statement state, and transaction counter.

```bash
./puff model User
```

```php
namespace Model;

use think\Model;

final class User extends Model
{
    protected $table = 'users';
}
```

Use dependency injection for `think\DbManager` or let a model receive it through Puff's model context. Do not use `think\facade\Db` in a long-running Puff worker: Think's fallback facade stores a process-global manager and bypasses Fiber isolation. The global helpers shipped by Think ORM (`db()`, `raw()`, `inc()`, and `dec()`) also use that facade and should not be used in Puff application code.

Eloquent, Cycle, and Think ORM may be installed together. Puff deliberately does not select a default ORM and does not attempt to make their Model and Entity APIs interchangeable.

## Third-party adapters

An adapter implements only three operations:

```php
use Puff\Database\Contract\AdapterInterface;
use Puff\Di\Container;

final class VendorOrmAdapter implements AdapterInterface
{
    public function name(): string { return 'vendor-orm'; }
    public function register(Container $container): void { /* bind ORM services */ }
    public function clear(): void { /* release the current Fiber context */ }
}
```

Register it from a normal Puff provider:

```php
use Puff\Database\AdapterRegistry;

AdapterRegistry::resolve($this->app)->add(new VendorOrmAdapter());
```

The adapter package declares that provider with existing Composer discovery:

```json
{
    "require": {
        "puff/database": "^1.0",
        "vendor/orm": "^2.0"
    },
    "extra": {
        "puff": {
            "providers": ["Vendor\\Orm\\PuffServiceProvider"]
        }
    }
}
```

`AdapterRegistry::resolve()` makes provider order irrelevant. Duplicate names fail immediately, activation is idempotent, adapters added after activation register immediately, and initialization exceptions are never hidden.

## Migrations and boundaries

Install `puff/migration` for schema changes. Migrations use database contracts and never inspect Eloquent models or Cycle entities, so database configuration and migration history remain stable when the ORM changes.

## Quality checks

```bash
composer test
composer analyse
composer validate --strict
```

The SQLite integration suite requires `pdo_sqlite`; a skipped database suite is not considered a release pass.
