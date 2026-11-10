<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_event_sources', function (Blueprint $table): void {
            $table->string('code', 24)->primary();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->string('last_error', 40)->nullable();
        });
        Schema::create('market_events', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24);
            $table->date('date')->index();
            $table->unique(['code', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_events');
        Schema::dropIfExists('market_event_sources');
    }
};
