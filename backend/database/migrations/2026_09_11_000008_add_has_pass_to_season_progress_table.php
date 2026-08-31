<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the user owns this season's premium battlepass. There is no
 * payment flow yet - an admin grants it per user (see AdminUserController::
 * seasonPass / SeasonService::grantPass). When true, crossing a tier also
 * grants that tier's premium reward cosmetic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('season_progress', function (Blueprint $table) {
            $table->boolean('has_pass')->default(false)->after('current_tier');
        });
    }

    public function down(): void
    {
        Schema::table('season_progress', function (Blueprint $table) {
            $table->dropColumn('has_pass');
        });
    }
};
