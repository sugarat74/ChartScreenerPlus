<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Macro Series of the closed list in config/macro.php
 * (docs/specs/macro-series-fred.md, Domain Rule 2), written only by
 * `macro:refresh`. `last_error` holds an error code, never a message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('macro_series', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('source', 16);
            $table->string('category', 16);
            $table->string('frequency', 16);
            $table->string('title', 255)->nullable();
            $table->string('units', 128)->nullable();
            $table->date('last_observation_date')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->string('last_error', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('macro_series');
    }
};
