<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 6-digit code now lives in the cache, keyed by user id - the
     * dedicated table is gone. Safe everywhere: a fresh install created it one
     * migration earlier and drops it here; the running production DB drops the
     * populated-but-now-unused table. The email_verified_at backfill from the
     * create migration is NOT undone.
     */
    public function up(): void
    {
        Schema::dropIfExists('email_verification_codes');
    }

    public function down(): void
    {
        Schema::create('email_verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }
};
