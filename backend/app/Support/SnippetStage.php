<?php

namespace App\Support;

class SnippetStage
{
    /** @var array<int, float> */
    public const SEQUENCE = [0.1, 0.5, 1.0, 5.0, 15.0];

    public static function first(): float
    {
        return self::SEQUENCE[0];
    }

    public static function next(float $current): ?float
    {
        $index = array_search($current, self::SEQUENCE, strict: true);

        if ($index === false) {
            return null;
        }

        return self::SEQUENCE[$index + 1] ?? null;
    }

    public static function isLast(float $current): bool
    {
        return self::next($current) === null;
    }
}
