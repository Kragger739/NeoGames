<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progress + resume state for FetchIconicArtistCatalogue (the self-chaining
 * job that crawls an artist's Spotify catalogue and resolves iTunes
 * previews). Kept on the row (not cache) so it survives across worker
 * invocations on every driver. `fetch_run_token` lets a Re-fetch supersede
 * an in-flight chain; `fetch_cursor` carries the resumable album crawl.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iconic_artists', function (Blueprint $table) {
            $table->string('fetch_status')->default('pending'); // pending|discovering|seeding|done|failed
            $table->unsignedInteger('fetched_total')->default(0);
            $table->unsignedInteger('fetched_playable')->default(0);
            $table->string('fetch_error')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->string('fetch_run_token')->nullable();
            $table->json('fetch_cursor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('iconic_artists', function (Blueprint $table) {
            $table->dropColumn([
                'fetch_status',
                'fetched_total',
                'fetched_playable',
                'fetch_error',
                'fetched_at',
                'fetch_run_token',
                'fetch_cursor',
            ]);
        });
    }
};
