<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A "watchlist" is implicit: one per user. There is no `watchlists` table
     * and no dedicated model (the `instrument_universe` pivot precedent), so
     * ownership is simply `user_id` on this unique pair.
     */
    public function up(): void
    {
        Schema::create('watchlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'instrument_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('watchlist_items');
    }
};
