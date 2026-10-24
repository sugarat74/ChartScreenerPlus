<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screener_rows', function (Blueprint $table): void {
            $table->string('industry', 120)->nullable();
            $table->char('country', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('screener_rows', function (Blueprint $table): void {
            $table->dropColumn(['industry', 'country']);
        });
    }
};
