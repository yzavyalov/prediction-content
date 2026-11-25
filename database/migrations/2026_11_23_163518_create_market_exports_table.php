<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_exports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('market_id')
                ->unique()
                ->constrained('markets')
                ->cascadeOnDelete();

            // Destination system.
            $table->string('destination', 50)->default('wrangle');

            // pending, processing, completed, failed
            $table->string('status', 20)->default('pending');

            // Market ID returned by wrangle.win.
            $table->unsignedBigInteger('remote_bet_id')->nullable();

            // Number of actual API delivery attempts.
            $table->unsignedInteger('attempts')->default(0);

            // Last HTTP response status.
            $table->unsignedSmallInteger('http_status')->nullable();

            // Last delivery error.
            $table->text('error_message')->nullable();

            // Delivery timestamps.
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_exports');
    }
};
