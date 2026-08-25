<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\LevelingService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'username', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Computed, not stored - included on every serialization (register,
     * login, /api/user) without touching those controllers individually.
     */
    protected $appends = ['level'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'xp' => 'integer',
        ];
    }

    protected function level(): Attribute
    {
        return Attribute::make(
            // A freshly created-in-memory instance may not yet reflect the
            // xp column's DB-level default until it's re-fetched - default
            // to 0 rather than passing a possibly-null value along.
            get: fn () => app(LevelingService::class)->levelForXp((int) ($this->xp ?? 0)),
        );
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(GameRoom::class, 'host_id');
    }

    /**
     * A default, editable-later handle - never left null for a real
     * account, since friend search and room auto-join both key off it.
     */
    public static function generateUniqueUsernameFrom(string $name): string
    {
        $base = Str::of($name)->slug('')->limit(20, '')->lower()->toString();
        $base = $base !== '' ? $base : 'user';

        $username = $base;
        $suffix = 1;

        while (self::where('username', $username)->exists()) {
            $suffix++;
            $username = "{$base}{$suffix}";
        }

        return $username;
    }
}
