<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Languages
        |--------------------------------------------------------------------------
        |
        | Language of the source/article content.
        | Language and geography are intentionally independent.
        |
        */

        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | Categories
        |--------------------------------------------------------------------------
        */

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | Countries
        |--------------------------------------------------------------------------
        */

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('iso2', 2)->unique();
            $table->char('iso3', 3)->nullable()->unique();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | Country Groups
        |--------------------------------------------------------------------------
        |
        | Examples:
        | European Union
        | Latin America
        | Balkans
        |
        */

        Schema::create('country_groups', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('country_group_country', function (Blueprint $table) {
            $table->foreignId('country_group_id')
                ->constrained('country_groups')
                ->cascadeOnDelete();

            $table->foreignId('country_id')
                ->constrained('countries')
                ->cascadeOnDelete();

            $table->primary(['country_group_id', 'country_id']);
        });

        /*
        |--------------------------------------------------------------------------
        | Sources
        |--------------------------------------------------------------------------
        |
        | Sources are managed by humans through the admin panel.
        |
        | language_id = language in which the source publishes content.
        |
        | Geography is NOT derived from language. Geography is configured
        | separately through source_countries / source_country_groups.
        |
        */

        Schema::create('sources', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);
            $table->string('domain', 255)->unique();
            $table->string('base_url', 2048);

            $table->foreignId('language_id')
                ->constrained('languages')
                ->restrictOnDelete();

            /*
             * Source lifecycle:
             *
             * draft    - added by admin but not tested
             * testing  - source is being tested
             * active   - available for Discovery
             * disabled - manually disabled
             */
            $table->string('status', 20)->default('draft');

            /*
             * Higher priority means the source may be processed earlier
             * by Discovery orchestration.
             */
            $table->unsignedSmallInteger('priority')->default(50);

            /*
             * How often Discovery should check this source.
             */
            $table->unsignedInteger('discovery_frequency_minutes')->default(120);

            $table->timestamp('last_discovery_at')->nullable();
            $table->timestamp('last_successful_discovery_at')->nullable();

            $table->text('notes')->nullable();

            /*
             * Will later reference the admin/user who created the source.
             * Nullable so sources can also be seeded/imported.
             *
             * We intentionally do not add the FK here yet because the
             * authentication/admin architecture has not been finalized.
             */
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('priority');
            $table->index('language_id');
        });

        /*
        |--------------------------------------------------------------------------
        | Source Categories
        |--------------------------------------------------------------------------
        |
        | One source can cover multiple categories.
        |
        */

        Schema::create('source_categories', function (Blueprint $table) {
            $table->foreignId('source_id')
                ->constrained('sources')
                ->cascadeOnDelete();

            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnDelete();

            $table->primary(['source_id', 'category_id']);
        });

        /*
        |--------------------------------------------------------------------------
        | Source Countries
        |--------------------------------------------------------------------------
        |
        | GEO coverage of a source.
        |
        | Example:
        |
        | Romania Insider
        | language = en
        | GEO      = RO
        |
        */

        Schema::create('source_countries', function (Blueprint $table) {
            $table->foreignId('source_id')
                ->constrained('sources')
                ->cascadeOnDelete();

            $table->foreignId('country_id')
                ->constrained('countries')
                ->cascadeOnDelete();

            $table->primary(['source_id', 'country_id']);
        });

        /*
        |--------------------------------------------------------------------------
        | Source Country Groups
        |--------------------------------------------------------------------------
        |
        | Used for regional/international sources.
        |
        */

        Schema::create('source_country_groups', function (Blueprint $table) {
            $table->foreignId('source_id')
                ->constrained('sources')
                ->cascadeOnDelete();

            $table->foreignId('country_group_id')
                ->constrained('country_groups')
                ->cascadeOnDelete();

            $table->primary(['source_id', 'country_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_country_groups');
        Schema::dropIfExists('source_countries');
        Schema::dropIfExists('source_categories');

        Schema::dropIfExists('sources');

        Schema::dropIfExists('country_group_country');
        Schema::dropIfExists('country_groups');
        Schema::dropIfExists('countries');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('languages');
    }
};
