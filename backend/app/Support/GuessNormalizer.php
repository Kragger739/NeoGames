<?php

namespace App\Support;

/**
 * Single source of truth for how a song title / artist is folded before it's
 * compared to a player's typed guess - so "Dont Stop Me Now" matches "Don't
 * Stop Me Now" and "mr brightside" matches "Mr. Brightside". Used both by the
 * guess-correctness check (GuessService) and the autocomplete SQL
 * (SongSearchController), which is why the policy has to live in one place:
 * normalize() is the PHP form, sqlExpr() is the exact same transformation as
 * a SQL expression so a LIKE against the folded column still matches.
 *
 * Deliberately ASCII-only case folding (matches SQLite's LOWER()); accent
 * folding (Beyonce vs Beyonce) is out of scope here.
 */
final class GuessNormalizer
{
    /** Removed entirely - punctuation that a player routinely omits. */
    private const REMOVE = ['.', ',', "'", "\u{2019}", '!', '?', '"', '(', ')', ':', ';'];

    /** Collapsed to a space, so "spider-man" reads the same as "spider man". */
    private const TO_SPACE = ['-', "\u{2013}", "\u{2014}", '/'];

    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(self::REMOVE, '', $value);
        $value = str_replace(self::TO_SPACE, ' ', $value);

        // Collapse any run of whitespace to a single space (== Str::squish()).
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * The same folding as normalize(), expressed against a column so an
     * autocomplete LIKE can match a stripped query. Safe on sqlite / mysql /
     * pgsql (all have LOWER() and REPLACE()).
     */
    public static function sqlExpr(string $column): string
    {
        $expr = "LOWER($column)";

        foreach (self::REMOVE as $char) {
            $expr = "REPLACE($expr, ".self::quote($char).", '')";
        }

        foreach (self::TO_SPACE as $char) {
            $expr = "REPLACE($expr, ".self::quote($char).", ' ')";
        }

        return $expr;
    }

    private static function quote(string $char): string
    {
        return "'".str_replace("'", "''", $char)."'";
    }
}
