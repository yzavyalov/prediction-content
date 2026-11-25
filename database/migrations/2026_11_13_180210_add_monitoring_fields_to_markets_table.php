<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->boolean('monitoring_enabled')->default(true);
            $table->string('monitoring_status', 32)->default('pending');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->unsignedInteger('monitoring_attempts')->default(0);

            $table->index(
                ['monitoring_enabled', 'monitoring_status', 'next_check_at'],
                'markets_monitoring_schedule_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropIndex('markets_monitoring_schedule_idx');

            $table->dropColumn([
                'monitoring_enabled',
                'monitoring_status',
                'last_checked_at',
                'next_check_at',
                'monitoring_attempts',
            ]);
        });
    }
};
