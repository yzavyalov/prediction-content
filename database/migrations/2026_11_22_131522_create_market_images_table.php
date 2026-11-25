<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_images', function (Blueprint $table) {
            $table->id();

            // Market relationship
            $table->foreignId('market_id')
                ->constrained('markets')
                ->cascadeOnDelete();

            // AI generation
            $table->string('provider', 50)->default('gemini');
            $table->string('model', 100)->nullable();
            $table->longText('prompt');

            // Image storage
            $table->string('disk', 50)->default('public');
            $table->string('path')->nullable();
            $table->string('mime_type', 100)->nullable();

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Generation status
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error_message')->nullable();

            // Selected image for export
            $table->boolean('is_selected')->default(false);

            // Generation timestamps
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['market_id', 'status']);
            $table->index(['market_id', 'is_selected']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_images');
    }
};
