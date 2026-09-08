<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rounds', function (Blueprint $table) {
            // One-shot latch: set exactly once, by whichever of the delayed
            // AdvanceAfterReveal job or a >50% skip-reveal vote first moves
            // the game forward from this (already won/failed) round. Every
            // later attempt to advance from the same round is a no-op.
            // Nullable with no default, so existing rows read "not advanced";
            // harmless, since their delayed jobs already ran.
            $table->timestamp('advanced_at')->nullable()->after('stage_version');
        });
    }

    public function down(): void
    {
        Schema::table('rounds', function (Blueprint $table) {
            $table->dropColumn('advanced_at');
        });
    }
};
