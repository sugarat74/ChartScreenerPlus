<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * auth-email-verification (D2): every account that exists before the release
 * is treated as verified, so turning verification on can never lock anyone
 * out. Rows that already have a date keep it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    /**
     * Intentionally a no-op: the backfilled rows cannot be told apart from
     * accounts that really confirmed their address.
     */
    public function down(): void
    {
        //
    }
};
