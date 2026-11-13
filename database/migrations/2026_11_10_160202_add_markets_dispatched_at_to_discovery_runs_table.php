<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->timestamp('markets_dispatched_at')
                ->nullable()
                ->after('events_finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->dropColumn('markets_dispatched_at');
        });
    }
};
