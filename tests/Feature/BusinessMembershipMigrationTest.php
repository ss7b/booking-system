<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessMembershipMigrationTest extends TestCase
{
    public function test_legacy_memberships_are_mapped_without_implicit_privilege_escalation(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This migration upgrade test requires MySQL.');
        }

        $this->assertSame('booking_test', DB::connection()->getDatabaseName(), 'Migration verification must be launched from the isolated booking_test database.');

        $schema = 'membership_migration_'.Str::lower(Str::random(12));
        DB::statement("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $connectionName = 'membership_migration_test';
        config(['database.connections.'.$connectionName => array_merge(config('database.connections.mysql'), ['database' => $schema])]);
        DB::purge($connectionName);
        $previousConnection = DB::getDefaultConnection();
        $previousDefault = config('database.default');

        try {
            DB::setDefaultConnection($connectionName);
            config(['database.default' => $connectionName]);
            $this->createLegacySchema();

            $businessId = (string) Str::ulid();
            DB::table('businesses')->insert(['id' => $businessId]);
            DB::table('users')->insert([
                ['name' => 'Legacy Admin', 'password' => 'unused'],
                ['name' => 'Legacy Custom', 'password' => 'unused'],
            ]);
            DB::table('business_user')->insert([
                ['business_id' => $businessId, 'user_id' => 1, 'role' => 'admin'],
                ['business_id' => $businessId, 'user_id' => 2, 'role' => 'legacy_custom'],
            ]);

            $migration = require base_path('database/migrations/2026_10_12_000000_add_business_membership_scope_and_staff_accounts.php');
            $migration->up();

            $memberships = DB::table('business_user')->orderBy('user_id')->get()->keyBy('user_id');
            $this->assertSame('manager', $memberships[1]->role);
            $this->assertSame('staff', $memberships[2]->role);
            $this->assertSame('all', $memberships[1]->access_scope);
            $this->assertSame('all', $memberships[2]->access_scope);
            $this->assertTrue(Schema::hasTable('business_user_locations'));
            $this->assertTrue(Schema::hasColumn('staff', 'user_id'));
            $this->assertTrue((bool) DB::table('users')->where('id', 1)->value('is_active'));

            $roleColumn = DB::selectOne("SHOW COLUMNS FROM business_user LIKE 'role'");
            $scopeColumn = DB::selectOne("SHOW COLUMNS FROM business_user LIKE 'access_scope'");
            $this->assertNull($roleColumn->Default);
            $this->assertNull($scopeColumn->Default);
        } finally {
            DB::setDefaultConnection($previousConnection);
            config(['database.default' => $previousDefault]);
            DB::purge($connectionName);
            DB::statement("DROP DATABASE `{$schema}`");
        }
    }

    private function createLegacySchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('password');
        });
        Schema::create('businesses', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
        });
        Schema::create('business_user', function (Blueprint $table): void {
            $table->char('business_id', 26);
            $table->unsignedBigInteger('user_id');
            $table->string('role', 50)->default('admin');
            $table->timestamps(6);
            $table->primary(['business_id', 'user_id']);
        });
        Schema::create('locations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('business_id', 26);
        });
        Schema::create('staff', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('business_id', 26);
            $table->string('phone', 50)->nullable();
        });
    }
}
