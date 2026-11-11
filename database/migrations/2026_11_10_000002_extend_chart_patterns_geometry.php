<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_patterns', function (Blueprint $table) {
            $table->string('direction', 8)->nullable()->after('status');
            $table->decimal('breakout_level', 14, 4)->nullable()->change();
        });
        DB::table('chart_patterns')->whereIn('type', ['double_bottom','cup_with_handle','bull_flag','triple_bottom','bull_pennant'])->update(['direction' => 'bullish']);
        DB::table('chart_patterns')->whereIn('type', ['double_top','triple_top','inverse_cup_with_handle','bear_flag','bear_pennant'])->update(['direction' => 'bearish']);
    }

    public function down(): void
    {
        Schema::table('chart_patterns', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
        // Keep breakout_level nullable on rollback too: converting a forming
        // sloped pattern to an invented horizontal price would be misleading.
    }
};
