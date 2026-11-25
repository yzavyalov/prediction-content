<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_crawled_urls', function (Blueprint $table) {
            $table->id();

            $table->foreignId('source_id')
                ->constrained('sources')
                ->cascadeOnDelete();

            $table->char('url_hash', 64);

            $table->timestamp('processed_at');

            $table->unique(
                ['source_id', 'url_hash'],
                'source_crawled_urls_source_hash_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_crawled_urls');
    }
};
