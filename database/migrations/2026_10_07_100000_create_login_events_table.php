<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in activity for the Admin overview: successful sign-ins, failed
 * attempts, sign-outs and Admin session revocations. Personal data (email,
 * IP, user agent) is kept for `config('admin.activity_retention_days')` and
 * pruned daily by `model:prune` (App\Models\LoginEvent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->string('event', 32);
            // The account involved (null for a failed attempt on an unknown email).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // The Admin who performed a revocation.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // Attempted/used email, never a password.
            $table->string('email')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->unsignedInteger('sessions_revoked')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};
