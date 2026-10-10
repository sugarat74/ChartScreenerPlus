<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The current active chartist patterns per instrument (replaced on every
     * `patterns:detect`, like `signals`). See docs/specs/chart-patterns-detect.md.
     */
    public function up(): void
    {
        Schema::create('chart_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->date('as_of_date');
            $table->string('type', 32);
            $table->string('status', 16);
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('breakout_level', 14, 4);
            $table->json('points');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['instrument_id', 'as_of_date', 'type']);
            $table->index(['type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chart_patterns');
    }
};
