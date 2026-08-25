<?php

namespace App\Support;

class Scoring
{
    private const BASE_POINTS = 100;

    private const POINTS_STEP = 20;

    /**
     * Earlier (shorter) snippet stages score more.
     */
    public static function pointsForStage(float $stage): int
    {
        $index = array_search($stage, SnippetStage::SEQUENCE, strict: true);
        $index = $index === false ? count(SnippetStage::SEQUENCE) - 1 : $index;

        return max(self::BASE_POINTS - ($index * self::POINTS_STEP), self::POINTS_STEP);
    }
}
