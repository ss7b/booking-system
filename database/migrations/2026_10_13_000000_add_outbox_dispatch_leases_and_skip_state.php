<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->uuid('dispatch_claim_token')->nullable();
            $table->dateTime('dispatch_claim_expires_at', 6)->nullable();
            $table->dateTime('next_attempt_at', 6)->nullable();
            $table->dateTime('skipped_at', 6)->nullable();
            $table->string('skip_reason', 100)->nullable();
            $table->index(
                ['published_at', 'skipped_at', 'next_attempt_at', 'dispatch_claim_expires_at', 'created_at'],
                'outbox_events_dispatch_scan_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->dropIndex('outbox_events_dispatch_scan_index');
            $table->dropColumn([
                'dispatch_claim_token',
                'dispatch_claim_expires_at',
                'next_attempt_at',
                'skipped_at',
                'skip_reason',
            ]);
        });
    }
};
