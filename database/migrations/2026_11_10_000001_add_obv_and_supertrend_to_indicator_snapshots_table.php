<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('indicator_snapshots', function (Blueprint $table) {
            $table->decimal('obv', 20, 4)->nullable();
            $table->decimal('supertrend', 16, 4)->nullable();
            $table->smallInteger('supertrend_direction')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('indicator_snapshots', function (Blueprint $table) {
            $table->dropColumn(['obv', 'supertrend', 'supertrend_direction']);
        });
    }
};
