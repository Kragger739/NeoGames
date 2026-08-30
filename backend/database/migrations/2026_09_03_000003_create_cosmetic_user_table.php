<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cosmetic_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cosmetic_id')->constrained()->cascadeOnDelete();
            // How this user came to own it - 'track' (tier unlock) for now;
            // Phase 2 adds 'pass'. 'starter' cosmetics are owned implicitly and
            // never get a row here.
            $table->string('source')->default('track');
            $table->timestamp('acquired_at')->useCurrent();

            $table->unique(['user_id', 'cosmetic_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cosmetic_user');
    }
};
