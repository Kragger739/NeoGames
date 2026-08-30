<?php

namespace App\Enums;

enum DatasetType: string
{
    case Ddf = 'ddf';
    case Songle = 'songle';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
