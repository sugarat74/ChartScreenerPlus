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
        Schema::create('ingestion_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingestion_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->unsignedInteger('bars_stored')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->unique(['ingestion_run_id', 'instrument_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingestion_run_items');
    }
};
