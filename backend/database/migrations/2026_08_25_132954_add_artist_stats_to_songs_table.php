<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            // Deezer's track object carries the artist's id but not their
            // fan count - artist_deezer_id lets a later reveal look the fan
            // count up (and cache it) without needing a second trackDetails()
            // round-trip just to rediscover which artist this song belongs to.
            $table->string('artist_deezer_id')->nullable()->after('artist');
            $table->unsignedInteger('artist_fan_count')->nullable()->after('artist_deezer_id');
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropColumn(['artist_deezer_id', 'artist_fan_count']);
        });
    }
};
