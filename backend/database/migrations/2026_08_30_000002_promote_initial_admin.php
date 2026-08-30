<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const EMAIL = 'diamondpickminer@gmail.com';

    public function up(): void
    {
        DB::table('users')->where('email', self::EMAIL)->update(['is_admin' => true]);
    }

    public function down(): void
    {
        DB::table('users')->where('email', self::EMAIL)->update(['is_admin' => false]);
    }
};
