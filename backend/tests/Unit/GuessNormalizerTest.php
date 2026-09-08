<?php

namespace Tests\Unit;

use App\Support\GuessNormalizer;
use PHPUnit\Framework\TestCase;

class GuessNormalizerTest extends TestCase
{
    public function test_it_lowercases_and_collapses_whitespace(): void
    {
        $this->assertSame('blinding lights', GuessNormalizer::normalize("  Blinding   Lights \n"));
    }

    public function test_dropped_apostrophes_and_periods_still_match(): void
    {
        $this->assertSame(
            GuessNormalizer::normalize("Don't Stop Me Now"),
            GuessNormalizer::normalize('Dont Stop Me Now'),
        );

        $this->assertSame(
            GuessNormalizer::normalize('Mr. Brightside'),
            GuessNormalizer::normalize('mr brightside'),
        );
    }

    public function test_hyphens_and_slashes_fold_to_a_space(): void
    {
        $this->assertSame(
            GuessNormalizer::normalize('Spider-Man'),
            GuessNormalizer::normalize('spider man'),
        );
    }

    public function test_parentheses_and_punctuation_are_stripped(): void
    {
        $this->assertSame('hello remastered', GuessNormalizer::normalize('Hello (Remastered)!'));
    }

    public function test_a_string_that_is_only_punctuation_normalizes_to_empty(): void
    {
        $this->assertSame('', GuessNormalizer::normalize('..!?'));
    }

    public function test_sql_expr_folds_a_column_the_same_way(): void
    {
        // The SQL form must strip/space the same characters, so a LIKE
        // against the folded column matches a folded query.
        $expr = GuessNormalizer::sqlExpr('title');

        $this->assertStringContainsString('LOWER(title)', $expr);
        $this->assertStringContainsString('REPLACE(', $expr);
        $this->assertStringContainsString("'.', ''", $expr);   // period stripped
        $this->assertStringContainsString("'-', ' '", $expr);  // hyphen -> space
    }
}
