<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_notification_settings', function (Blueprint $table): void {
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30);
            $table->string('notification_type', 50);
            $table->boolean('enabled');
            $table->json('reminder_intervals')->nullable();
            $table->timestamps(6);
            $table->primary(['business_id', 'role', 'notification_type'], 'business_notification_settings_primary');
        });

        Schema::create('user_notification_preferences', function (Blueprint $table): void {
            $table->foreignUlid('business_id');
            $table->foreignId('user_id');
            $table->string('notification_type', 50);
            $table->boolean('enabled');
            $table->timestamps(6);
            $table->primary(['business_id', 'user_id', 'notification_type'], 'user_notification_preferences_primary');
            $table->foreign(['business_id', 'user_id'], 'user_notification_preferences_membership_foreign')
                ->references(['business_id', 'user_id'])->on('business_user')->cascadeOnDelete();
        });

        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notification_type', 50);
            $table->string('idempotency_key', 64)->unique();
            $table->json('data')->nullable();
            $table->timestamp('read_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['user_id', 'business_id', 'read_at', 'created_at'], 'user_notifications_inbox_index');
            $table->index(['booking_id', 'user_id'], 'user_notifications_booking_user_index');
        });

        DB::statement("ALTER TABLE business_notification_settings ADD CONSTRAINT business_notification_settings_mandatory_check CHECK ((notification_type <> 'booking.created' OR role NOT IN ('owner', 'manager', 'receptionist') OR enabled = 1) AND (notification_type NOT IN ('booking.rescheduled', 'booking.cancelled') OR enabled = 1))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE business_notification_settings DROP CHECK business_notification_settings_mandatory_check');
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('user_notification_preferences');
        Schema::dropIfExists('business_notification_settings');
    }
};
