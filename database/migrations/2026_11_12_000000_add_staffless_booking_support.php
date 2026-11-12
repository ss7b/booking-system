<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_locations', function (Blueprint $table): void {
            $table->string('booking_mode', 20)->default('staff_required');
            $table->unsignedInteger('capacity')->default(1);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->index(
                ['business_id', 'location_id', 'service_id', 'status', 'occupied_starts_at', 'occupied_ends_at'],
                'bookings_staffless_capacity_index',
            );
        });

        DB::statement("ALTER TABLE service_locations ADD CONSTRAINT service_locations_booking_mode_check CHECK (booking_mode IN ('staff_required', 'staffless'))");
        DB::statement('ALTER TABLE service_locations ADD CONSTRAINT service_locations_capacity_check CHECK (capacity >= 1)');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['staff_id']);
            $table->ulid('staff_id')->nullable()->change();
            $table->foreign('staff_id')->references('id')->on('staff')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('bookings')->whereNull('staff_id')->exists()) {
            throw new RuntimeException('Cannot roll back staffless booking support while staffless bookings exist.');
        }

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['staff_id']);
            $table->ulid('staff_id')->nullable(false)->change();
            $table->foreign('staff_id')->references('id')->on('staff')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE service_locations DROP CHECK service_locations_booking_mode_check');
        DB::statement('ALTER TABLE service_locations DROP CHECK service_locations_capacity_check');

        Schema::table('service_locations', function (Blueprint $table): void {
            $table->dropColumn(['booking_mode', 'capacity']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('bookings_staffless_capacity_index');
        });
    }
};
