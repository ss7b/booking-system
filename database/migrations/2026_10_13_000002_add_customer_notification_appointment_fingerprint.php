<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table): void {
            $table->string('appointment_fingerprint', 64)->nullable()->after('appointment_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table): void {
            $table->dropColumn('appointment_fingerprint');
        });
    }
};
