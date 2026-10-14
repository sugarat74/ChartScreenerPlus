<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rule fields of the `watchlist_pattern` Alert kind
     * (docs/specs/alerts-patterns.md): the chosen chartist pattern types and
     * events (`appears`, `confirmed`). Both stay null for the other kinds.
     */
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->json('pattern_types')->nullable();
            $table->json('pattern_events')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn(['pattern_types', 'pattern_events']);
        });
    }
};
