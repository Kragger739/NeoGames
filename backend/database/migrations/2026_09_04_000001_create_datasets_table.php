<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            // 'ddf' | 'songle' - cast to App\Enums\DatasetType (same "store
            // string, cast to enum" convention as game_rooms.genre).
            $table->string('type');
            // 'private' | 'public' - App\Enums\DatasetVisibility.
            $table->string('visibility')->default('private');
            // 'en' | 'de' for ddf datasets; null for songle.
            $table->string('language')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'type']);
            $table->index(['type', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datasets');
    }
};
