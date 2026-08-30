<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deezer preview URLs are signed CDN links (~330 chars: hdnea= expiry +
     * acl + hmac); some album-art URLs also exceed 255. SQLite (dev) ignores
     * varchar length, but Postgres rejects the insert with "value too long
     * for type character varying(255)". Both the `songs` cache table and the
     * `dataset_tracks` table store these, so widen both to text.
     */
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->text('preview_url')->change();
            $table->text('album_art_url')->nullable()->change();
        });

        Schema::table('dataset_tracks', function (Blueprint $table) {
            $table->text('preview_url')->nullable()->change();
            $table->text('album_art_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->string('preview_url')->change();
            $table->string('album_art_url')->nullable()->change();
        });

        Schema::table('dataset_tracks', function (Blueprint $table) {
            $table->string('preview_url')->nullable()->change();
            $table->string('album_art_url')->nullable()->change();
        });
    }
};
