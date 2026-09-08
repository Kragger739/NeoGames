<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Apple Music / iTunes artist id (from the artist page link an admin
 * pastes). When set, FetchIconicArtistCatalogue pins the catalogue to this
 * exact artist instead of guessing from the name - so two acts that share a
 * name, or a tribute act that ranks higher in search, can never be pulled in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iconic_artists', function (Blueprint $table) {
            $table->unsignedBigInteger('apple_artist_id')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('iconic_artists', function (Blueprint $table) {
            $table->dropColumn('apple_artist_id');
        });
    }
};
