<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Curated Spotify playlists the `songs:sync` command seeds each genre's pool
 * from. Managed from the admin dashboard rather than the environment, so the
 * pool can be curated without a redeploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seed_playlists', function (Blueprint $table) {
            $table->id();
            $table->string('genre');
            $table->string('spotify_playlist_id');
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique(['genre', 'spotify_playlist_id']);
            $table->index('genre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seed_playlists');
    }
};
