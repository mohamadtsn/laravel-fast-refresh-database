<?php

namespace MohamadTsn\DatabaseFresh\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MohamadTsn\DatabaseFresh\FastRefreshDatabase;
use Orchestra\Testbench\TestCase;

class FastRefreshDatabaseTest extends TestCase
{
    use FastRefreshDatabase;

    protected $excludeFromRefresh = ['lookups'];

    protected $alwaysTruncate = ['audits'];

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testing');
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['users', 'posts', 'lookups', 'audits'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->increments('id');
                $blueprint->string('name')->nullable();
            });
        }
    }

    public function test_tracks_inserted_tables(): void
    {
        DB::table('users')->insert(['name' => 'a']);

        $tables = $this->getInsertedTables(DB::connection());

        $this->assertContains('users', $tables->all());
        $this->assertNotContains('posts', $tables->all());
    }

    public function test_unseed_truncates_only_inserted_tables(): void
    {
        DB::table('users')->insert(['name' => 'a']);

        $this->unseedTablesForAllConnections();

        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_excluded_tables_survive_unseed(): void
    {
        DB::table('lookups')->insert(['name' => 'keep-me']);
        DB::table('users')->insert(['name' => 'a']);

        $this->unseedTablesForAllConnections();

        $this->assertSame(1, DB::table('lookups')->count());
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_always_truncate_tables_are_cleared_without_tracked_insert(): void
    {
        // Bypass the listener: raw PDO insert is never tracked.
        DB::connection()->getPdo()->exec("insert into audits (name) values ('untracked')");

        $this->assertSame(1, DB::table('audits')->count());

        $this->unseedTablesForAllConnections();

        $this->assertSame(0, DB::table('audits')->count());
    }

    public function test_tracking_survives_query_log_flush(): void
    {
        DB::table('users')->insert(['name' => 'a']);
        DB::connection()->flushQueryLog();

        $this->assertContains('users', $this->getInsertedTables(DB::connection())->all());
    }

    public function test_truncate_all_tables_clears_everything(): void
    {
        DB::connection()->getPdo()->exec("insert into users (name) values ('untracked')");
        DB::connection()->getPdo()->exec("insert into posts (name) values ('untracked')");

        $this->truncateAllTables();

        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('posts')->count());
    }

    public function test_truncate_all_tables_skips_migrations_and_excluded(): void
    {
        Schema::create('migrations', function (Blueprint $blueprint) {
            $blueprint->increments('id');
            $blueprint->string('migration');
        });
        DB::connection()->getPdo()->exec("insert into migrations (migration) values ('m1')");
        DB::connection()->getPdo()->exec("insert into lookups (name) values ('keep-me')");

        $this->truncateAllTables();

        $this->assertSame(1, DB::table('migrations')->count());
        $this->assertSame(1, DB::table('lookups')->count());
    }
}