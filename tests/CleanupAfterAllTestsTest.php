<?php

namespace MohamadTsn\DatabaseFresh\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MohamadTsn\DatabaseFresh\FastRefreshDatabase;
use Orchestra\Testbench\TestCase;
use ReflectionProperty;

class CleanupAfterAllTestsTest extends TestCase
{
    use FastRefreshDatabase;

    protected $cleanupAfterAllTests = true;

    protected $excludeFromCleanup = ['audits'];

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testing');
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['users', 'audits'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->increments('id');
                $blueprint->string('name')->nullable();
            });
        }
    }

    public function test_cleanup_targets_cumulative_writes_minus_excludes(): void
    {
        DB::table('users')->insert(['name' => 'a']);
        DB::table('audits')->insert(['name' => 'a']);

        $connectionName = DB::connection()->getName();
        $targets = $this->tablesToCleanupAfterAllTests($connectionName);

        $this->assertContains('users', $targets);
        $this->assertNotContains('audits', $targets);
    }

    public function test_shutdown_callback_registered_once(): void
    {
        $property = new ReflectionProperty(static::class, 'cleanupRegistered');
        $property->setAccessible(true);

        $this->assertTrue($property->getValue());
    }

    public function test_disabled_by_default(): void
    {
        $harness = new class {
            use FastRefreshDatabase {
                shouldCleanupAfterAllTests as public exposedShould;
            }
        };

        $this->assertFalse($harness->exposedShould());
    }
}