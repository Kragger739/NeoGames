<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            // Separate column from artist_name (singular) - that one stays
            // owned by the single-Artist genre, this one by MultiArtist.
            $table->json('artist_names')->nullable()->after('artist_name');
        });
    }

    public function down(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->dropColumn('artist_names');
        });
    }
};
