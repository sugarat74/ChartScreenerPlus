<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each instrument holds the *current active* signal set, so a type can
     * appear at most once per instrument and date. The unique key makes the
     * replace-per-instrument invariant of `signals:detect` explicit at the
     * database level.
     */
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->unique(
                ['instrument_id', 'date', 'type'],
                'signals_instrument_id_date_type_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropUnique('signals_instrument_id_date_type_unique');
        });
    }
};
