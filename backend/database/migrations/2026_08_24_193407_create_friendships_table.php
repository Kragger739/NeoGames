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
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // requester
            $table->foreignId('friend_id')->constrained('users')->cascadeOnDelete(); // recipient
            $table->string('status'); // 'pending' | 'accepted'
            $table->timestamps();

            // Decline/unfriend/cancel all just DELETE the row (no 'declined'
            // status) - keeps this constraint from permanently blocking a
            // future re-request between the same two users.
            $table->unique(['user_id', 'friend_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('friendships');
    }
};
