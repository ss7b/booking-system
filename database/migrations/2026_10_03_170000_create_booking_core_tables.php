<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->string('timezone', 64)->default('UTC');
            $table->char('default_currency', 3)->default('SAR');
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->timestamps(6);
        });

        Schema::create('business_user', function (Blueprint $table) {
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 50)->default('admin');
            $table->timestamps(6);
            $table->primary(['business_id', 'user_id']);
        });

        Schema::create('availability_calendars', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('timezone', 64);
            $table->timestamps(6);
            $table->index(['business_id', 'name']);
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('calendar_id')->nullable()->constrained('availability_calendars')->nullOnDelete();
            $table->string('name', 150);
            $table->string('slug', 150);
            $table->string('timezone', 64)->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps(6);
            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'is_active']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->string('slug', 200);
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('buffer_before_minutes')->default(0);
            $table->unsignedInteger('buffer_after_minutes')->default(0);
            $table->unsignedInteger('slot_interval_minutes');
            $table->unsignedInteger('min_notice_minutes')->default(0);
            $table->unsignedInteger('max_advance_days')->nullable();
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps(6);
            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'is_active']);
        });

        Schema::create('staff', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('calendar_id')->nullable()->constrained('availability_calendars')->nullOnDelete();
            $table->string('name', 200);
            $table->string('slug', 200)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps(6);
            $table->index(['business_id', 'status']);
        });

        Schema::create('staff_services', function (Blueprint $table) {
            $table->foreignUlid('staff_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->primary(['staff_id', 'service_id']);
        });

        Schema::create('staff_locations', function (Blueprint $table) {
            $table->foreignUlid('staff_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at', 6)->useCurrent();
            $table->primary(['staff_id', 'location_id']);
            $table->index(['location_id', 'is_active']);
        });

        Schema::create('service_locations', function (Blueprint $table) {
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->primary(['service_id', 'location_id']);
        });

        Schema::create('calendar_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('calendar_id')->constrained('availability_calendars')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps(6);
            $table->index(['calendar_id', 'day_of_week']);
        });

        Schema::create('calendar_overrides', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('calendar_id')->constrained('availability_calendars')->cascadeOnDelete();
            $table->date('date');
            $table->string('mode', 20);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps(6);
            $table->index(['calendar_id', 'date']);
        });

        Schema::create('staff_blocks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('staff_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6);
            $table->string('type', 30);
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps(6);
            $table->index(['staff_id', 'starts_at', 'ends_at']);
            $table->index(['business_id', 'staff_id', 'starts_at']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->string('phone', 50)->nullable();
            $table->string('normalized_phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps(6);
            $table->index(['business_id', 'normalized_phone']);
            $table->index(['business_id', 'email']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('service_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('staff_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('customer_id')->constrained()->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('status', 30)->index();
            $table->string('source', 30)->default('public');
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6);
            $table->dateTime('occupied_starts_at', 6);
            $table->dateTime('occupied_ends_at', 6);
            $table->dateTime('hold_expires_at', 6)->nullable();
            $table->string('service_name_snapshot', 200);
            $table->unsignedInteger('duration_snapshot');
            $table->unsignedBigInteger('price_minor_snapshot')->nullable();
            $table->char('currency_snapshot', 3)->nullable();
            $table->string('access_token_hash')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('confirmed_at', 6)->nullable();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['business_id', 'starts_at']);
            $table->index(['staff_id', 'starts_at', 'ends_at']);
            $table->index(['staff_id', 'occupied_starts_at', 'occupied_ends_at']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['status', 'hold_expires_at']);
            $table->index(['business_id', 'status', 'starts_at']);
        });

        Schema::create('booking_day_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('staff_id')->constrained()->restrictOnDelete();
            $table->date('booking_date');
            $table->timestamps(6);
            $table->unique(['business_id', 'staff_id', 'booking_date']);
        });

        Schema::create('booking_status_histories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('actor_type', 30);
            $table->char('actor_id', 26)->nullable();
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['booking_id', 'created_at']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->string('key', 255);
            $table->string('endpoint', 255);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->dateTime('expires_at', 6);
            $table->timestamps(6);
            $table->unique(['business_id', 'key', 'endpoint'], 'idempotency_business_key_endpoint_unique');
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 100);
            $table->string('aggregate_type', 100);
            $table->char('aggregate_id', 26);
            $table->json('payload');
            $table->dateTime('occurred_at', 6);
            $table->dateTime('published_at', 6)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps(6);
            $table->index(['published_at', 'created_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('booking_status_histories');
        Schema::dropIfExists('booking_day_locks');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('staff_blocks');
        Schema::dropIfExists('calendar_overrides');
        Schema::dropIfExists('calendar_rules');
        Schema::dropIfExists('service_locations');
        Schema::dropIfExists('staff_locations');
        Schema::dropIfExists('staff_services');
        Schema::dropIfExists('staff');
        Schema::dropIfExists('services');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('availability_calendars');
        Schema::dropIfExists('business_user');
        Schema::dropIfExists('businesses');
    }
};