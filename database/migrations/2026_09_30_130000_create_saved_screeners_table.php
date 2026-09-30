<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Saved Screener has its own attributes (a name and a stored definition),
     * so it gets a real table rather than a pivot. Ownership is `user_id` and
     * the definition is the canonical seven-key filter object stored as JSON
     * (`docs/domain-model.md`: `draft -> saved -> deleted`, no soft delete).
     */
    public function up(): void
    {
        Schema::create('saved_screeners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('filters');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_screeners');
    }
};
