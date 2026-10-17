<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company Fundamentals (company-fundamentals-sec): one row per Instrument
 * with the inputs computed from its SEC 10-K/10-Q filings, rebuilt by
 * `fundamentals:refresh`. Metrics are derived on read. Portable
 * SQLite/PostgreSQL: `inputs` is a plain `json` column read and written only
 * through Eloquent's `array` cast (no JSON operators in queries).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_fundamentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('cik', 10)->nullable();
            $table->string('sic', 4)->nullable();
            $table->string('sic_description', 120)->nullable();
            $table->boolean('is_financial')->default(false);
            $table->string('status', 16);
            $table->string('currency', 3)->default('USD');
            $table->date('period_end')->nullable();
            $table->date('prior_period_end')->nullable();
            $table->date('balance_sheet_date')->nullable();
            $table->date('shares_as_of')->nullable();
            $table->string('latest_accession', 20)->nullable();
            $table->string('latest_form', 10)->nullable();
            $table->date('latest_filed_at')->nullable();
            $table->string('pending_accession', 20)->nullable();
            $table->string('pending_form', 10)->nullable();
            $table->date('pending_filed_at')->nullable();
            foreach ([
                'revenue_ttm', 'revenue_prior_ttm', 'ebit_ttm', 'pretax_ttm', 'tax_ttm', 'da_ttm',
                'eps_diluted_ttm', 'dividends_per_share_ttm', 'total_debt', 'cash', 'equity', 'shares_outstanding',
            ] as $column) {
                $table->decimal($column, 24, 4)->nullable();
            }
            $table->json('inputs')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->string('last_error', 200)->nullable();
            $table->timestamps();

            $table->index('cik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_fundamentals');
    }
};
