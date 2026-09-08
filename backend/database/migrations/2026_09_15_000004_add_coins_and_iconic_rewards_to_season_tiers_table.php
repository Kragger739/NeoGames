<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A battle-pass tier can now additionally grant NeoCoins and/or an iconic-artist
 * unlock, on the free and/or premium track, alongside the existing cosmetic
 * reward. Auto-granted on crossing the tier (SeasonService::syncTierUnlocks) -
 * no claim step. Legacy config-threshold seasons stay cosmetic-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('season_tiers', function (Blueprint $table) {
            $table->unsignedInteger('free_coins')->default(0)->after('free_cosmetic_id');
            $table->foreignId('free_iconic_artist_id')->nullable()->after('free_coins')
                ->constrained('iconic_artists')->nullOnDelete();
            $table->unsignedInteger('premium_coins')->default(0)->after('premium_cosmetic_id');
            $table->foreignId('premium_iconic_artist_id')->nullable()->after('premium_coins')
                ->constrained('iconic_artists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('season_tiers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('free_iconic_artist_id');
            $table->dropConstrainedForeignId('premium_iconic_artist_id');
            $table->dropColumn(['free_coins', 'premium_coins']);
        });
    }
};
