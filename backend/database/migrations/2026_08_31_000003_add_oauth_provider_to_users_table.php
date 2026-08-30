<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Informational only - which OAuth provider (if any) this
            // account last signed in with. Account matching/linking is
            // always done by email (see OAuthController), never by these,
            // so neither needs a unique constraint.
            $table->string('provider')->nullable()->after('avatar_path');
            $table->string('provider_id')->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['provider', 'provider_id']);
        });
    }
};
