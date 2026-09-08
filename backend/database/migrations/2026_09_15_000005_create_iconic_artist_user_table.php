<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ownership of a priced iconic artist. A row means the user has unlocked that
 * act permanently (bought in the Shop, or granted by the battle pass). A free
 * artist (iconic_artists.price = 0) needs no row - it's playable by any
 * account. Mirrors cosmetic_user: written via insertOrIgnore, `source` records
 * where the unlock came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iconic_artist_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('iconic_artist_id')->constrained()->cascadeOnDelete();
            $table->string('source')->default('shop'); // shop | battlepass
            $table->timestamp('acquired_at')->useCurrent();

            $table->unique(['user_id', 'iconic_artist_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iconic_artist_user');
    }
};
