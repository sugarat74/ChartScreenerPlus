<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Observations of a Macro Series, stored as published with their own date
 * (docs/specs/macro-series-fred.md, Domain Rule 2). FRED's missing value
 * (`"."`) is never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('macro_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('macro_series_id')->constrained('macro_series')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('value', 20, 6);
            $table->timestamps();

            $table->unique(['macro_series_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('macro_observations');
    }
};
