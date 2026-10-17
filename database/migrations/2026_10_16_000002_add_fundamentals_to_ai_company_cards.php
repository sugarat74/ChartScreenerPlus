<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Company Card remembers the SEC filing its outlook was grounded on, so a
 * newer 10-K/10-Q makes it stale (company-fundamentals-sec).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_company_cards', function (Blueprint $table) {
            $table->string('fundamentals_accession', 20)->nullable();
            $table->date('fundamentals_period_end')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_company_cards', function (Blueprint $table) {
            $table->dropColumn(['fundamentals_accession', 'fundamentals_period_end']);
        });
    }
};
