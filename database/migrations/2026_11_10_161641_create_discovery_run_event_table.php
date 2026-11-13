<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_run_event', function (Blueprint $table) {
            $table->id();

            $table->foreignId('discovery_run_id')
                ->constrained('discovery_runs')
                ->cascadeOnDelete();

            $table->foreignId('event_id')
                ->constrained('events')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique([
                'discovery_run_id',
                'event_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_run_event');
    }
};
