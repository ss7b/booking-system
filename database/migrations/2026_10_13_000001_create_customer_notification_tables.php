<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_customer_notification_settings', function (Blueprint $table): void {
            $table->foreignUlid('business_id')->primary()->constrained()->cascadeOnDelete();
            $table->longText('settings');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(6);
        });

        Schema::create('customer_notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('source_outbox_event_id')->nullable()->constrained('outbox_events')->nullOnDelete();
            $table->dateTime('appointment_starts_at', 6)->nullable();
            $table->string('event_type', 40);
            $table->string('idempotency_key', 64)->unique();
            $table->json('channels');
            $table->unsignedTinyInteger('channel_index')->default(0);
            $table->unsignedTinyInteger('channel_attempts')->default(0);
            $table->unsignedSmallInteger('total_attempts')->default(0);
            $table->string('status', 20)->default('queued');
            $table->dateTime('next_attempt_at', 6)->nullable();
            $table->dateTime('accepted_at', 6)->nullable();
            $table->dateTime('delivered_at', 6)->nullable();
            $table->dateTime('failed_at', 6)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->foreignId('resent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resent_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['business_id', 'status', 'created_at'], 'customer_notifications_business_status_index');
            $table->index(['status', 'next_attempt_at'], 'customer_notifications_due_index');
            $table->index(['booking_id', 'event_type', 'created_at'], 'customer_notifications_booking_event_index');
        });

        Schema::create('customer_notification_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_notification_id')->constrained('customer_notifications')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->unsignedTinyInteger('attempt_number');
            $table->string('status', 20);
            $table->string('provider_message_id', 150)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->unique(['customer_notification_id', 'channel', 'attempt_number'], 'customer_notification_attempt_unique');
            $table->index(['created_at', 'status'], 'customer_notification_attempt_retention_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notification_attempts');
        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('business_customer_notification_settings');
    }
};
