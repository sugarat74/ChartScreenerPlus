<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive, nullable columns: until `indicators:compute` runs again they
     * are null and every consumer treats null as "not available".
     */
    public function up(): void
    {
        Schema::table('indicator_snapshots', function (Blueprint $table) {
            $table->decimal('atr14', 12, 4)->nullable();
            $table->decimal('plus_di', 12, 4)->nullable();
            $table->decimal('minus_di', 12, 4)->nullable();
            $table->decimal('stoch_k', 12, 4)->nullable();
            $table->decimal('stoch_d', 12, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('indicator_snapshots', function (Blueprint $table) {
            $table->dropColumn(['atr14', 'plus_di', 'minus_di', 'stoch_k', 'stoch_d']);
        });
    }
};
