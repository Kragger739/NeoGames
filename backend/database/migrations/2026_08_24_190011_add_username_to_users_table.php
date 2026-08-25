<?php

use App\Models\User;
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
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 24)->nullable()->unique()->after('name');
        });

        // Backfill for existing dev users - safe here since this is confirmed
        // dev data, not a production rollout needing a phased nullable step.
        User::whereNull('username')->orderBy('id')->each(function (User $user) {
            // forceFill/save (not update()) - this backfill must not depend on
            // whatever the model's mass-assignment $fillable happens to be.
            $user->forceFill(['username' => User::generateUniqueUsernameFrom($user->name)])->save();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
        });
    }
};
