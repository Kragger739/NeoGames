<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A curated act for the "Iconic Artist series" on the Songle landing page.
 * `name` is matched case-insensitively against `songs.artist` (the same way
 * the Artist genre matches), so it must be spelled exactly as the pool
 * stores it. `image_path` is an uploaded photo on the `public` disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iconic_artists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('image_path')->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iconic_artists');
    }
};
