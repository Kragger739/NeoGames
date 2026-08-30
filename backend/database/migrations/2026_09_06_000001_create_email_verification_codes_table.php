<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_codes', function (Blueprint $table) {
            $table->id();
            // One active code per user - sendCode() updateOrCreate()s this row.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        // Every account that predates the verification gate has a NULL
        // email_verified_at (only UserFactory ever set it), so without this
        // backfill the gate would lock every existing user out. Intentionally
        // NOT reversed in down() - we can't know which rows were originally NULL.
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_codes');
    }
};
