<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->date('as_of_date');
            $table->decimal('reference_close', 14, 4);
            $table->unsignedSmallInteger('horizon');
            $table->date('target_date');
            $table->string('model_version', 80);
            $table->string('question_version', 80);
            $table->string('input_hash', 64);
            $table->decimal('probability_up', 7, 6);
            $table->decimal('probability_down', 7, 6);
            $table->decimal('probability_flat', 7, 6);
            $table->string('source', 16)->default('prospective');
            $table->decimal('observed_close', 14, 4)->nullable();
            $table->string('observed_class', 8)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['instrument_id', 'as_of_date', 'horizon', 'model_version', 'question_version'], 'forecast_case_unique');
            $table->index(['resolved_at', 'target_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_predictions');
    }
};
