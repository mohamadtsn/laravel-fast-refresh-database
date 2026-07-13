<?php

namespace MohamadTsn\DatabaseFresh\Tests;

use MohamadTsn\DatabaseFresh\FastRefreshDatabase;
use PHPUnit\Framework\TestCase;

final class InsertTableRegexTest extends TestCase
{
    private function extract(string $sql): ?string
    {
        $harness = new class {
            use FastRefreshDatabase {
                getRegex as public exposedRegex;
            }
        };

        preg_match($harness->exposedRegex(), $sql, $match);

        return $match[1] ?? null;
    }

    public function test_plain_insert(): void
    {
        $this->assertSame('users', $this->extract('insert into `users` (`name`) values (?)'));
    }

    public function test_insert_without_space_before_columns(): void
    {
        $this->assertSame('users', $this->extract('insert into users(name) values(?)'));
    }

    public function test_insert_ignore_mysql(): void
    {
        $this->assertSame('users', $this->extract('insert ignore into `users` (`name`) values (?)'));
    }

    public function test_insert_or_ignore_sqlite(): void
    {
        $this->assertSame('users', $this->extract('insert or ignore into "users" ("name") values (?)'));
    }

    public function test_replace_into(): void
    {
        $this->assertSame('settings', $this->extract('replace into `settings` (`key`, `value`) values (?, ?)'));
    }

    public function test_upsert_on_conflict(): void
    {
        $this->assertSame('users', $this->extract('insert into "users" ("email") values (?) on conflict ("email") do update set "name" = ?'));
    }

    public function test_double_quoted_identifier(): void
    {
        $this->assertSame('users', $this->extract('insert into "users" ("name") values (?)'));
    }

    public function test_bracket_identifier_sqlsrv(): void
    {
        $this->assertSame('users', $this->extract('insert into [users] ([name]) values (?)'));
    }

    public function test_select_is_ignored(): void
    {
        $this->assertNull($this->extract('select * from `users`'));
    }

    public function test_update_is_ignored(): void
    {
        $this->assertNull($this->extract('update `users` set `name` = ?'));
    }

    public function test_delete_is_ignored(): void
    {
        $this->assertNull($this->extract('delete from `users`'));
    }
}