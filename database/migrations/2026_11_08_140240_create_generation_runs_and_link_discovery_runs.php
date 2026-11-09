<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('generation_profile_id')
                ->constrained('generation_profiles')
                ->restrictOnDelete();

            $table->string('status', 20)->default('pending');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->unsignedInteger('sources_count')->default(0);
            $table->unsignedInteger('discovery_runs_count')->default(0);

            $table->unsignedInteger('completed_discovery_runs_count')->default(0);
            $table->unsignedInteger('failed_discovery_runs_count')->default(0);

            $table->unsignedInteger('articles_count')->default(0);
            $table->unsignedInteger('events_count')->default(0);
            $table->unsignedInteger('markets_count')->default(0);

            $table->unsignedInteger('google_search_count')->default(0);

            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index([
                'generation_profile_id',
                'created_at',
            ]);

            $table->index([
                'status',
                'created_at',
            ]);
        });

        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->foreignId('generation_run_id')
                ->nullable()
                ->after('id')
                ->constrained('generation_runs')
                ->restrictOnDelete();

            $table->index([
                'generation_run_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->dropIndex([
                'generation_run_id',
                'status',
            ]);

            $table->dropConstrainedForeignId(
                'generation_run_id'
            );
        });

        Schema::dropIfExists('generation_runs');
    }
};
