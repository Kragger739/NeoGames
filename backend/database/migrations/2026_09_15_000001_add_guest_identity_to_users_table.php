<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest identity. An unauthenticated visitor who plays the Daily, the Songle
 * game-night entry, or DDF gets a real `users` row (so game_rooms.host_id,
 * daily_challenge_attempts, room_players.user_id all keep working) flagged
 * `is_guest = true`. A guest has no email/password, earns no XP/level/NeoCoins
 * and cannot host - see EnsureGuestOrUser / EnsureNotGuest.
 *
 * email + password become nullable for guests. A UNIQUE(email) index treats
 * NULLs as distinct on SQLite / PostgreSQL / MySQL, so any number of guest
 * rows with email = NULL coexist under the existing unique index - no partial
 * index needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_guest')->default(false)->index()->after('email_verified_at');
        });

        // Separate closure: on SQLite a ->change() rebuilds the table (create
        // shadow, copy, drop, rename) and re-creates every index, so the
        // UNIQUE(email) from the original migration survives intact.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_guest');
        });

        // email / password are left nullable: reversing them would require
        // every guest row purged first, which down() has no safe way to do.
    }
};
