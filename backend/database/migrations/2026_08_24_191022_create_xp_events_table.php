<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('xp_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('round_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('game_rooms')->nullOnDelete();
            $table->string('type'); // 'first' | 'second' | 'third' | 'participation'
            $table->unsignedInteger('amount');
            $table->timestamp('created_at')->useCurrent();

            // Makes double-awarding the same round physically impossible at
            // the DB layer, independent of application logic correctness -
            // defense-in-depth beyond advanceAfterRoundResolved()'s own
            // once-per-round guarantee.
            $table->unique(['round_id', 'user_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('xp_events');
    }
};
