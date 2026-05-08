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
Add the trait to your test class. The package automatically starts watching insert queries before each test (via a PHPUnit `@before` hook) and truncates only the tables that were touched after each test:

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

### Manual setup (legacy PHPUnit):
If your PHPUnit version does not support `@before`, you can still invoke the setup helper in `setUp()`:

```php
protected function setUp(): void
{
    parent::setUp();

    // Manually start watching inserts for this test process
    $this->setupDatabaseAndStartWatchingTables();
}
```



You may also check my other package as well:

- https://www.github.com/mohamadtsn


<a name="credits"></a>
## Credits

- [Iman](https://github.com/mohamadtsn)
- [All Contributors](../../contributors)

<a name="license"></a>
## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
