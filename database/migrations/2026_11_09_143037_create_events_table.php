<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete();

            $table->foreignId('country_id')
                ->nullable()
                ->constrained('countries')
                ->nullOnDelete();

            $table->string('title', 500);
            $table->text('description')->nullable();

            $table->string('status', 30)->default('draft');

            $table->dateTime('event_at')->nullable();

            $table->string('source_locale', 10)->default('en');

            $table->string('deduplication_key', 64)
                ->nullable()
                ->unique();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['category_id', 'status']);
            $table->index(['country_id', 'event_at']);
        });

        Schema::create('event_source_article', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')
                ->constrained('events')
                ->cascadeOnDelete();

            $table->foreignId('source_article_id')
                ->constrained('source_articles')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['event_id', 'source_article_id']);
        });

        Schema::create('event_translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')
                ->constrained('events')
                ->cascadeOnDelete();

            $table->string('locale', 10);
            $table->string('title', 500);
            $table->text('description')->nullable();

            $table->timestamps();

            $table->unique(['event_id', 'locale']);
        });

        Schema::create('markets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')
                ->constrained('events')
                ->restrictOnDelete();

            $table->foreignId('generation_run_id')
                ->nullable()
                ->constrained('generation_runs')
                ->nullOnDelete();

            $table->string('question', 500);
            $table->text('description')->nullable();

            $table->string('status', 30)->default('draft');

            $table->string('source_locale', 10)->default('en');

            $table->dateTime('finish_at')->nullable();
            $table->dateTime('resolution_at')->nullable();
            $table->dateTime('resolved_at')->nullable();

            $table->text('resolution_rules')->nullable();

            $table->foreignId('winning_outcome_id')->nullable();

            $table->string('deduplication_key', 64)
                ->nullable()
                ->unique();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['status', 'finish_at']);
        });

        Schema::create('market_outcomes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('market_id')
                ->constrained('markets')
                ->cascadeOnDelete();

            $table->string('code', 100);
            $table->string('title', 255);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['market_id', 'code']);
        });

        Schema::table('markets', function (Blueprint $table) {
            $table->foreign('winning_outcome_id')
                ->references('id')
                ->on('market_outcomes')
                ->restrictOnDelete();
        });

        Schema::create('market_translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('market_id')
                ->constrained('markets')
                ->cascadeOnDelete();

            $table->string('locale', 10);
            $table->string('question', 500);
            $table->text('description')->nullable();
            $table->text('resolution_rules')->nullable();

            $table->timestamps();

            $table->unique(['market_id', 'locale']);
        });

        Schema::create('market_outcome_translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('market_outcome_id')
                ->constrained('market_outcomes')
                ->cascadeOnDelete();

            $table->string('locale', 10);
            $table->string('title', 255);

            $table->timestamps();

            $table->unique(['market_outcome_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_outcome_translations');
        Schema::dropIfExists('market_translations');

        Schema::table('markets', function (Blueprint $table) {
            $table->dropForeign(['winning_outcome_id']);
        });

        Schema::dropIfExists('market_outcomes');
        Schema::dropIfExists('markets');
        Schema::dropIfExists('event_translations');
        Schema::dropIfExists('event_source_article');
        Schema::dropIfExists('events');
    }
};
