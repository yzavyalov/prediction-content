<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_generation_runs', function (Blueprint $table) {
            $table->string('gemini_interaction_id')
                ->nullable();

            $table->string('gemini_stage', 32)
                ->nullable();

            $table->timestamp('gemini_started_at')
                ->nullable();

            $table->timestamp('gemini_last_checked_at')
                ->nullable();

            $table->json('gemini_first_response')
                ->nullable();

            $table->json('gemini_first_usage')
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('market_generation_runs', function (Blueprint $table) {
            $table->dropColumn([
                'gemini_interaction_id',
                'gemini_stage',
                'gemini_started_at',
                'gemini_last_checked_at',
                'gemini_first_response',
                'gemini_first_usage',
            ]);
        });
    }
};
