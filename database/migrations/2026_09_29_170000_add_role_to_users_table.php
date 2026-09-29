<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The column default keeps the model out of the mass-assignment path:
     * new accounts are always created as `user` and the role can only change
     * out of band (see app/Console/Commands/MakeAdmin.php).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop the index first: SQLite refuses to drop a column that is
            // still referenced by an index.
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
