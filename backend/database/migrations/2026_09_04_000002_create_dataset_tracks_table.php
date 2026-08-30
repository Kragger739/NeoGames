<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained()->cascadeOnDelete();
            // The Deezer track this row references. The `songs` cache table is
            // regenerable and its deezer_track_id is globally unique, so
            // dataset membership lives here (migration-stable), not there.
            $table->string('deezer_track_id');
            $table->string('title');
            $table->string('artist');
            $table->string('album_art_url')->nullable();
            // Deezer preview URLs expire (~15 min); refreshed at play time via
            // SongDiscoveryService::ensurePlayable(). Stored for display only.
            $table->string('preview_url')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['dataset_id', 'deezer_track_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_tracks');
    }
};
