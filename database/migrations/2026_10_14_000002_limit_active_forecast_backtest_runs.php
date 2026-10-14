<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite (tests) and PostgreSQL (production) both support partial
        // expression indexes. This guards against two paid runs even if the
        // cache lock expires while a large run is being prepared.
        DB::statement("CREATE UNIQUE INDEX forecast_backtest_one_active_run ON forecast_backtest_runs ((1)) WHERE status IN ('queued', 'running')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS forecast_backtest_one_active_run');
    }
};
