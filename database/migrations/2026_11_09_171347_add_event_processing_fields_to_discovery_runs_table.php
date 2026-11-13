
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->string('events_status', 30)
                ->default('pending');

            $table->unsignedInteger('events_count')
                ->default(0);

            $table->unsignedInteger('events_existing_count')
                ->default(0);

            $table->timestamp('events_started_at')
                ->nullable();

            $table->timestamp('events_finished_at')
                ->nullable();

            $table->text('events_error_message')
                ->nullable();

            $table->unsignedBigInteger('events_input_tokens')
                ->default(0);

            $table->unsignedBigInteger('events_output_tokens')
                ->default(0);
        });

        /*
         * Historical Discovery Runs that have not completed
         * should not be eligible for Event Processing yet.
         */
        DB::table('discovery_runs')
            ->where('status', '!=', 'completed')
            ->update([
                'events_status' => 'pending',
            ]);
    }

    public function down(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->dropColumn([
                'events_status',
                'events_count',
                'events_existing_count',
                'events_started_at',
                'events_finished_at',
                'events_error_message',
                'events_input_tokens',
                'events_output_tokens',
            ]);
        });
    }
};
