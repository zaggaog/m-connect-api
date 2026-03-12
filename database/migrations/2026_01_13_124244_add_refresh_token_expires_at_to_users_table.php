<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add refresh_token_expires_at if it doesn't exist
            if (!Schema::hasColumn('users', 'refresh_token_expires_at')) {
                $table->timestamp('refresh_token_expires_at')->nullable()->after('refresh_token');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop the column if it exists
            if (Schema::hasColumn('users', 'refresh_token_expires_at')) {
                $table->dropColumn('refresh_token_expires_at');
            }
        });
    }
};
