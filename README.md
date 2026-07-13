## When to use:
For large databases with more than 100 tables, it is very slow to drop all the tables and migrate again.
It is also still very slow to run the `truncate` query against all the tables.
The idea of this package is to truncate only the tables that are involved in that particular test and ignore the rest.
This way, only 5 to 6 tables need to be truncated after each test and not 200 tables.

Note that no table gets dropped or migrated. It only runs the `truncate table_name` query.

## Install:
```bash
composer require mohamadtsn/laravel-fast-refresh-database --dev
```


### How to Use:
Add the trait to your test class. The package automatically starts watching write queries (`insert`, `insert ignore`, `replace into`, `upsert`) before each test and truncates only the tables that were touched after each test. Tracking is event-based (`Connection::listen`), so the query log is never enabled and flushing it has no effect:

```php
use MohamadTsn\DatabaseFresh\FastRefreshDatabase;

class MyTest extends TestCase
{
    use FastRefreshDatabase;

    public function test_user_can_run()
    {
        // ... your test code
    }
}

```

Tip: Put the trait on your base `Tests\\TestCase` to enable it for all tests.

### Manual setup:
Laravel calls `setUpFastRefreshDatabase()` automatically through its `setUpTraits()` convention. If your base test case overrides that mechanism, invoke it yourself in `setUp()`:

```php
protected function setUp(): void
{
    parent::setUp();

    $this->setUpFastRefreshDatabase();
}
```

## Configuration

### Exclude tables from truncation
Tables listed here are never truncated by the trait (per-test refresh and `truncateAllTables()` both respect it):

```php
protected array $excludeFromRefresh = ['lookups', 'countries'];
```

### Always truncate tables
Tables truncated after every test even when no tracked write hit them (e.g. rows written through raw PDO or a stored procedure):

```php
protected array $alwaysTruncate = ['audits'];
```

### Truncate everything once (initial setup)
For a one-off full cleanup before a test run — truncates every table except `migrations` and `$excludeFromRefresh`:

```php
$this->truncateAllTables();          // default connection
$this->truncateAllTables('tenant');  // named connection
```

### Cleanup after the whole test run (opt-in, default off)
When enabled, a shutdown callback truncates every table written during the run once PHPUnit finishes:

```php
protected bool $cleanupAfterAllTests = true;

// optionally keep some tables
protected array $excludeFromCleanup = ['audits'];
```

To change the strategy entirely (e.g. wipe all tables), override:

```php
protected function tablesToCleanupAfterAllTests(string $connectionName): array
{
    return $this->getAllTables($this->app->make('db')->connection($connectionName));
}
```

### Multiple connections

```php
protected array $connectionsToUnseed = ['mysql', 'tenant'];
```

Every array property above can also be provided as a method of the same name if you need dynamic values.

You may also check my other package as well:

- https://www.github.com/mohamadtsn


<a name="credits"></a>
## Credits

- [Iman](https://github.com/mohamadtsn)
- [All Contributors](../../contributors)

<a name="license"></a>
## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
