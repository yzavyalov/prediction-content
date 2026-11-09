<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_profiles', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);
            $table->string('status', 20)->default('draft');

            $table->unsignedInteger('target_markets_per_run')->default(10);
            $table->unsignedInteger('freshness_minutes')->default(1440);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('status');
        });

        Schema::create('generation_profile_categories', function (Blueprint $table) {
            $table->foreignId('generation_profile_id')
                ->constrained('generation_profiles')
                ->cascadeOnDelete();

            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete();

            $table->primary([
                'generation_profile_id',
                'category_id',
            ]);
        });

        Schema::create('generation_profile_countries', function (Blueprint $table) {
            $table->foreignId('generation_profile_id')
                ->constrained('generation_profiles')
                ->cascadeOnDelete();

            $table->foreignId('country_id')
                ->constrained('countries')
                ->restrictOnDelete();

            $table->primary([
                'generation_profile_id',
                'country_id',
            ]);
        });

        Schema::create('generation_profile_country_groups', function (Blueprint $table) {
            $table->foreignId('generation_profile_id')
                ->constrained('generation_profiles')
                ->cascadeOnDelete();

            $table->foreignId('country_group_id')
                ->constrained('country_groups')
                ->restrictOnDelete();

            $table->primary([
                'generation_profile_id',
                'country_group_id',
            ]);
        });

        Schema::create('generation_profile_languages', function (Blueprint $table) {
            $table->foreignId('generation_profile_id')
                ->constrained('generation_profiles')
                ->cascadeOnDelete();

            $table->foreignId('language_id')
                ->constrained('languages')
                ->restrictOnDelete();

            $table->primary([
                'generation_profile_id',
                'language_id',
            ]);
        });

        Schema::create('generation_profile_sources', function (Blueprint $table) {
            $table->foreignId('generation_profile_id')
                ->constrained('generation_profiles')
                ->cascadeOnDelete();

            $table->foreignId('source_id')
                ->constrained('sources')
                ->restrictOnDelete();

            $table->primary([
                'generation_profile_id',
                'source_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_profile_sources');
        Schema::dropIfExists('generation_profile_languages');
        Schema::dropIfExists('generation_profile_country_groups');
        Schema::dropIfExists('generation_profile_countries');
        Schema::dropIfExists('generation_profile_categories');
        Schema::dropIfExists('generation_profiles');
    }
};
