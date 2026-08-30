<?php

namespace App\Enums;

enum CosmeticSlot: string
{
    case Frame = 'frame';
    case Hat = 'hat';
    case Accessory = 'accessory';
    case Badge = 'badge';
    case Background = 'background';
    case Effect = 'effect';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $slot) => $slot->value, self::cases());
    }
}
