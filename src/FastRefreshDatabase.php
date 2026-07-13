<?php

namespace MohamadTsn\DatabaseFresh;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\Traits\CanConfigureMigrationCommands;

trait FastRefreshDatabase
{
    use CanConfigureMigrationCommands;

    /**
     * Tables that received tracked write queries during the current test,
     * keyed by connection name.
     *
     * @var array<string, array<string, true>>
     */
    protected array $trackedInsertedTables = [];

    /**
     * Run-wide cumulative written tables, keyed by connection name.
     * Note: statics in traits are stored per using class; when several test
     * classes use the trait directly each keeps its own set — end-of-run
     * cleanup still covers the union because each class registers its own
     * shutdown callback.
     *
     * @var array<string, array<string, true>>
     */
    protected static array $runInsertedTables = [];

    /**
     * Whether the end-of-run cleanup callback has been registered
     * (per class using the trait).
     */
    protected static bool $cleanupRegistered = false;

    /**
     * Latest connection instances kept for the shutdown callback,
     * keyed by connection name.
     *
     * @var array<string, Connection>
     */
    protected static array $cleanupConnections = [];

    /**
     * Start watching write queries before each test.
     */
    public function setUpFastRefreshDatabase(): void
    {
        $this->beforeFastRefreshDatabase();

        $database = $this->app->make('db');

        collect($this->connectionsToUnseed())->each(function ($name) use ($database) {
            $connection = $database->connection($name);
            $key = $connection->getName();

            $this->trackedInsertedTables[$key] = [];

            if ($this->shouldCleanupAfterAllTests()) {
                static::$cleanupConnections[$key] = $connection;
            }

            // Connection::listen() receives QueryExecuted events for every
            // connection, so filter by name.
            $connection->listen(function (QueryExecuted $event) use ($key) {
                if ($event->connectionName !== $key) {
                    return;
                }

                if (preg_match($this->getRegex(), $event->sql, $match)) {
                    $this->trackedInsertedTables[$key][$match[1]] = true;
                    static::$runInsertedTables[$key][$match[1]] = true;
                }
            });
        });

        $this->beforeApplicationDestroyed(function () {
            $this->unseedTablesForAllConnections();
        });

        if ($this->shouldCleanupAfterAllTests() && ! static::$cleanupRegistered) {
            static::$cleanupRegistered = true;

            register_shutdown_function(function () {
                $this->runCleanupAfterAllTests();
            });
        }

        $this->afterFastRefreshDatabase();
    }

    /**
     * Truncate the written tables for all configured connections.
     */
    protected function unseedTablesForAllConnections(): void
    {
        $database = $this->app->make('db');

        collect($this->connectionsToUnseed())
            ->each(function ($name) use ($database) {
                $connection = $database->connection($name);

                $connection->getSchemaBuilder()->withoutForeignKeyConstraints(
                    fn () => $this->unseedTablesForConnection($connection, $name)
                );
            });
    }

    /**
     * Truncate the written tables for the given database connection.
     */
    protected function unseedTablesForConnection(Connection $connection, ?string $name): void
    {
        $dispatcher = $connection->getEventDispatcher();

        $connection->unsetEventDispatcher();

        $this->getInsertedTables($connection)
            ->merge($this->alwaysTruncate())
            ->map(fn ($table) => $this->withoutTablePrefix($connection, $table))
            ->unique()
            ->diff($this->excludeFromRefresh())
            ->each(fn ($table) => $connection->table($table)->truncate());

        $connection->setEventDispatcher($dispatcher);
    }

    /**
     * Truncate every table on the given connection except `migrations`
     * and the excludeFromRefresh() list. Intended for one-off initial
     * cleanup before a test run.
     */
    public function truncateAllTables(?string $name = null): void
    {
        $connection = $this->app->make('db')->connection($name);

        $connection->getSchemaBuilder()->withoutForeignKeyConstraints(function () use ($connection) {
            $dispatcher = $connection->getEventDispatcher();

            $connection->unsetEventDispatcher();

            collect($this->getAllTables($connection))
                ->map(fn ($table) => $this->withoutTablePrefix($connection, $table))
                ->reject(fn ($table) => str_starts_with($table, 'sqlite_'))
                ->diff(array_merge(['migrations'], $this->excludeFromRefresh()))
                ->each(fn ($table) => $connection->table($table)->truncate());

            $connection->setEventDispatcher($dispatcher);
        });
    }

    /**
     * List all table names on the connection, across Laravel versions.
     */
    protected function getAllTables(Connection $connection): array
    {
        $schema = $connection->getSchemaBuilder();

        if (method_exists($schema, 'getTableListing')) {
            // Laravel >= 10.43; strip optional schema qualifier (main.users).
            return array_map(
                fn ($table) => ($pos = strrpos($table, '.')) === false ? $table : substr($table, $pos + 1),
                $schema->getTableListing()
            );
        }

        // Laravel 8 - 10.x: getAllTables() returns driver-specific row
        // objects whose first column is the table name.
        return array_map(
            fn ($table) => array_values((array) $table)[0],
            $schema->getAllTables()
        );
    }

    /**
     * Get the list of table names that received tracked write queries
     * during the current test.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getInsertedTables($connection)
    {
        return collect(array_keys($this->trackedInsertedTables[$connection->getName()] ?? []));
    }

    /**
     * Get the regex used to derive the table name from a write query.
     *
     * Covers: insert into, insert ignore into (MySQL), insert or ignore into
     * (SQLite), replace into. Laravel upsert() compiles to "insert into ...
     * on conflict / on duplicate key" so it is covered too.
     */
    protected function getRegex(): string
    {
        return '/^\s*(?:insert(?:\s+(?:ignore|or\s+ignore))?|replace)\s+into\s+(?:`|\[|"|\')?([^\s`\]"\'(]+)/i';
    }

    /**
     * Remove the table prefix from a table name, if it exists.
     *
     * @return string
     */
    protected function withoutTablePrefix(Connection $connection, string $table)
    {
        $prefix = $connection->getTablePrefix();

        return str_starts_with($table, $prefix)
            ? substr($table, strlen($prefix))
            : $table;
    }

    /**
     * The database connections that should have their tables truncated.
     */
    protected function connectionsToUnseed(): array
    {
        return property_exists($this, 'connectionsToUnseed')
            ? $this->connectionsToUnseed : [null];
    }

    /**
     * Tables that must never be truncated by this trait.
     */
    protected function excludeFromRefresh(): array
    {
        return property_exists($this, 'excludeFromRefresh')
            ? $this->excludeFromRefresh : [];
    }

    /**
     * Tables truncated after every test even without a tracked write
     * (e.g. rows written through raw PDO statements).
     */
    protected function alwaysTruncate(): array
    {
        return property_exists($this, 'alwaysTruncate')
            ? $this->alwaysTruncate : [];
    }

    /**
     * Whether the database should be cleaned once the whole run finishes.
     * Opt in with: protected bool $cleanupAfterAllTests = true;
     */
    protected function shouldCleanupAfterAllTests(): bool
    {
        return property_exists($this, 'cleanupAfterAllTests')
            && (bool) $this->cleanupAfterAllTests;
    }

    /**
     * Tables that must survive the end-of-run cleanup.
     */
    protected function excludeFromCleanup(): array
    {
        return property_exists($this, 'excludeFromCleanup')
            ? $this->excludeFromCleanup : [];
    }

    /**
     * The tables the end-of-run cleanup will truncate for the given
     * connection. Defaults to every table written during the run minus
     * excludeFromCleanup(). Override to change the strategy (e.g. return
     * all tables).
     */
    protected function tablesToCleanupAfterAllTests(string $connectionName): array
    {
        return array_values(array_diff(
            array_keys(static::$runInsertedTables[$connectionName] ?? []),
            $this->excludeFromCleanup()
        ));
    }

    /**
     * Executed by register_shutdown_function after the whole run.
     */
    protected function runCleanupAfterAllTests(): void
    {
        foreach (static::$cleanupConnections as $connectionName => $connection) {
            try {
                $connection->unsetEventDispatcher();

                $connection->getSchemaBuilder()->withoutForeignKeyConstraints(function () use ($connection, $connectionName) {
                    collect($this->tablesToCleanupAfterAllTests($connectionName))
                        ->map(fn ($table) => $this->withoutTablePrefix($connection, $table))
                        ->each(fn ($table) => $connection->table($table)->truncate());
                });
            } catch (\Throwable $exception) {
                // ponytail: connection may already be closed at process
                // shutdown; nothing sane to do with a failure here.
            }
        }
    }

    /**
     * Perform any work that should take place before the database has started refreshing.
     *
     * @return void
     */
    protected function beforeFastRefreshDatabase(): void
    {
        //
    }

    /**
     * Perform any work that should take place once the database has finished refresh.
     *
     * @return void
     */
    protected function afterFastRefreshDatabase(): void
    {
        //
    }
}
