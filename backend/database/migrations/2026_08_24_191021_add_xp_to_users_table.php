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
            // Denormalized running total kept in sync with xp_events inserts.
            // No `level` column - always derived from this via
            // LevelingService::levelForXp() so the curve can be retuned
            // without a data migration.
            $table->unsignedInteger('xp')->default(0)->after('username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('xp');
        });
    }
};
