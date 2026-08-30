<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cosmetics', function (Blueprint $table) {
            $table->id();
            // Equip slot - one equipped item per slot. String backed by the
            // App\Enums\CosmeticSlot enum (same "store string, cast to enum"
            // convention as game_rooms.mode/genre).
            $table->string('slot');
            // Stable asset key that maps to a hand-authored SVG component in
            // frontend/src/lib/cosmetics/registry.tsx.
            $table->string('key')->unique();
            $table->string('name');
            $table->string('rarity')->default('common'); // common | rare | epic - drives a UI glow only
            // How it can be obtained. 'starter' = owned by everyone implicitly;
            // 'track' = unlocked by crossing a season tier. Phase 2 adds 'pass'.
            $table->string('source')->default('track');
            $table->foreignId('season_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('tier')->nullable(); // position on that season's unlock track
            $table->timestamps();

            $table->index(['season_id', 'tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cosmetics');
    }
};
