<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily alert email digest (docs/specs/alerts-email-digest.md): the user's
 * explicit opt-in (time) and digest language on `users`, plus a ledger with
 * one row per user and as-of date so a digest is never sent twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('alert_email_opt_in_at')->nullable();
            $table->string('preferred_locale', 5)->nullable();
        });

        Schema::create('alert_email_digests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('as_of');
            $table->unsignedInteger('items');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'as_of']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_email_digests');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['alert_email_opt_in_at', 'preferred_locale']);
        });
    }
};
