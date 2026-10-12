<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_rules', function (Blueprint $table) {
            $table->unique(
                ['calendar_id', 'day_of_week', 'start_time', 'end_time'],
                'calendar_rules_exact_window_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('calendar_rules', function (Blueprint $table) {
            $table->dropUnique('calendar_rules_exact_window_unique');
        });
    }
};
