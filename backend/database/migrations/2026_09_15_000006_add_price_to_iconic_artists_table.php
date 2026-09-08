<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop price, in NeoCoins, for unlocking this iconic artist. 0 (the default,
 * and every existing row) means free - playable by any account with no
 * purchase. Any value > 0 must be bought once in the Shop; ownership then
 * lives in iconic_artist_user forever. Admin sets this per artist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iconic_artists', function (Blueprint $table) {
            $table->unsignedInteger('price')->default(0)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('iconic_artists', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
