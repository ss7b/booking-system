<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('password');
        });

        DB::table('business_user')->where('role', 'admin')->update(['role' => 'manager']);
        DB::table('business_user')
            ->whereNotIn('role', ['owner', 'manager', 'receptionist', 'staff'])
            ->update(['role' => 'staff']);

        Schema::table('business_user', function (Blueprint $table): void {
            $table->string('access_scope', 20)->nullable()->after('role');
        });

        DB::table('business_user')->whereNull('access_scope')->update(['access_scope' => 'all']);

        Schema::table('business_user', function (Blueprint $table): void {
            $table->string('role', 50)->change();
            $table->string('access_scope', 20)->nullable(false)->change();
        });

        DB::statement("ALTER TABLE business_user ADD CONSTRAINT business_user_role_check CHECK (role IN ('owner', 'manager', 'receptionist', 'staff'))");
        DB::statement("ALTER TABLE business_user ADD CONSTRAINT business_user_access_scope_check CHECK (access_scope IN ('all', 'selected'))");
        DB::statement("ALTER TABLE business_user ADD CONSTRAINT business_user_owner_scope_check CHECK (role <> 'owner' OR access_scope = 'all')");

        Schema::table('locations', function (Blueprint $table): void {
            $table->unique(['business_id', 'id'], 'locations_business_id_id_unique');
        });

        Schema::create('business_user_locations', function (Blueprint $table): void {
            $table->foreignUlid('business_id');
            $table->foreignId('user_id');
            $table->foreignUlid('location_id');
            $table->timestamp('created_at', 6)->useCurrent();
            $table->primary(['business_id', 'user_id', 'location_id'], 'business_user_locations_primary');
            $table->index(['business_id', 'location_id'], 'business_user_locations_business_location_index');
            $table->foreign(['business_id', 'user_id'], 'business_user_locations_membership_foreign')
                ->references(['business_id', 'user_id'])->on('business_user')->cascadeOnDelete();
            $table->foreign(['business_id', 'location_id'], 'business_user_locations_location_foreign')
                ->references(['business_id', 'id'])->on('locations')->cascadeOnDelete();
        });

        Schema::table('staff', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('phone')->constrained()->nullOnDelete();
            $table->unique(['business_id', 'user_id'], 'staff_business_user_unique');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table): void {
            $table->dropUnique('staff_business_user_unique');
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::dropIfExists('business_user_locations');

        Schema::table('locations', function (Blueprint $table): void {
            $table->dropUnique('locations_business_id_id_unique');
        });

        DB::statement('ALTER TABLE business_user DROP CHECK business_user_role_check');
        DB::statement('ALTER TABLE business_user DROP CHECK business_user_access_scope_check');
        DB::statement('ALTER TABLE business_user DROP CHECK business_user_owner_scope_check');

        Schema::table('business_user', function (Blueprint $table): void {
            $table->dropColumn('access_scope');
            $table->string('role', 50)->default('admin')->change();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
