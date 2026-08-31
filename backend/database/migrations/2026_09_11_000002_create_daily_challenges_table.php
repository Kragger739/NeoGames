<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fixed set of songs for one day's Daily challenge - the same five for
 * every player, generated deterministically from the date on first request
 * (see App\Models\DailyChallenge) unless an admin curates them by hand
 * (`curated` = true).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_challenges', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->json('song_ids');          // ordered array of songs.id
            $table->boolean('curated')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_challenges');
    }
};
