<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_backtest_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->json('tickers');
            $table->unsignedSmallInteger('bars_requested');
            $table->string('model_version', 80);
            $table->string('question_version', 80);
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('correct')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->timestamps();
        });

        Schema::create('forecast_backtest_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('forecast_backtest_runs')->cascadeOnDelete();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->date('as_of_date');
            $table->date('target_date')->nullable();
            $table->string('status', 16)->default('queued');
            $table->string('predicted_class', 8)->nullable();
            $table->string('observed_class', 8)->nullable();
            $table->json('probabilities')->nullable();
            $table->string('reason', 64)->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'instrument_id', 'as_of_date'], 'forecast_backtest_case_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_backtest_cases');
        Schema::dropIfExists('forecast_backtest_runs');
    }
};
