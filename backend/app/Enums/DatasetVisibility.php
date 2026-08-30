<?php

namespace App\Enums;

enum DatasetVisibility: string
{
    case Private = 'private';
    case Public = 'public';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $visibility) => $visibility->value, self::cases());
    }
}
