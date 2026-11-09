<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('discovery_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('generation_profile_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('source_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('category_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('geo_type', 30);
            $table->unsignedBigInteger('geo_id');

            $table->dateTime('window_from');
            $table->dateTime('window_to');

            $table->string('status', 20)->default('pending');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->unsignedInteger('found_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);

            $table->unsignedInteger('google_search_count')->default(0);

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['source_id', 'category_id']);
            $table->index(['geo_type', 'geo_id']);
            $table->index(['generation_profile_id', 'created_at']);
        });

        Schema::create('source_articles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('source_id')
                ->constrained()
                ->restrictOnDelete();

            $table->text('url');
            $table->text('canonical_url');
            $table->char('url_hash', 64);

            $table->text('title');
            $table->timestamp('published_at')->nullable();
            $table->text('summary')->nullable();

            $table->string('status', 20)->default('discovered');

            $table->timestamps();

            $table->unique(['source_id', 'url_hash']);
            $table->index(['source_id', 'published_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('discovery_run_articles', function (Blueprint $table) {
            $table->foreignId('discovery_run_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('source_article_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->primary([
                'discovery_run_id',
                'source_article_id',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discovery_run_articles');
        Schema::dropIfExists('source_articles');
        Schema::dropIfExists('discovery_runs');
    }
};
