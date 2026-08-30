<?php

namespace App\Rules;

use App\Enums\GameMode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Gates Battle Royale behind config('leveling.battle_royale_min_level') -
 * a no-op for every other mode value, so it's safe to attach to the same
 * 'mode' rule array unconditionally in both StoreGameRoomRequest and
 * UpdateRoomSettingsRequest rather than duplicating the level check in each.
 */
class BattleRoyaleRequiresLevel implements ValidationRule
{
    public function __construct(private FormRequest $request) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== GameMode::BattleRoyale->value) {
            return;
        }

        $minLevel = (int) config('leveling.battle_royale_min_level');
        $level = $this->request->user()?->level ?? 0;

        if ($level < $minLevel) {
            $fail("Battle Royale unlocks at level {$minLevel} (you're level {$level}).");
        }
    }
}
