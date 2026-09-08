<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only NeoCoins ledger. Every credit/debit writes one row; users.neo_coins
 * is the denormalized running total (mirrors xp_events vs users.xp).
 *
 * `dedup_key` is NULL for one-off movements (spend, admin grant, plain credit)
 * and set for idempotent credits (level_up:{userId}:{level},
 * bp:{seasonId}:{tier}:{track}). UNIQUE(user_id, dedup_key) then makes a
 * repeat credit a no-op - NULLs are distinct under a unique index on SQLite /
 * PostgreSQL / MySQL, so the NULL-key rows are unconstrained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('neo_coin_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount'); // signed: + credit, - debit
            $table->string('reason'); // level_up | battlepass | admin_grant | spend | stripe_purchase
            $table->string('dedup_key')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'dedup_key']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('neo_coin_events');
    }
};
