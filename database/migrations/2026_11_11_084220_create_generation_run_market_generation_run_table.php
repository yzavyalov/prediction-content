<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_run_market_generation_run', function (Blueprint $table) {
            $table->id();

            $table->foreignId('generation_run_id');

            $table->foreignId('market_generation_run_id');

            $table->timestamps();

            $table->foreign('generation_run_id', 'gr_mgr_gr_fk')
                ->references('id')
                ->on('generation_runs')
                ->cascadeOnDelete();

            $table->foreign('market_generation_run_id', 'gr_mgr_mgr_fk')
                ->references('id')
                ->on('market_generation_runs')
                ->cascadeOnDelete();

            $table->unique(
                ['generation_run_id', 'market_generation_run_id'],
                'gr_mgr_unique'
            );

            $table->index(
                'market_generation_run_id',
                'gr_mgr_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_run_market_generation_run');
    }
};
