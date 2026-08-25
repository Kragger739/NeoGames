<?php

namespace Tests\Feature\Song;

use App\Services\Deezer\DeezerClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeezerClientTest extends TestCase
{
    /**
     * Deezer's /search/artist relevance does not rank by fame (confirmed
     * live) - a same-named but far-less-followed decoy can be returned
     * ahead of the real artist. findArtistId() must filter to exact
     * case-insensitive name matches first, then pick the highest fan count
     * among those - never just the first result or the overall top fan
     * count (a same-fame-tier but differently-named artist could otherwise
     * win on fan count alone).
     */
    public function test_it_resolves_to_the_exact_name_match_with_the_most_fans_over_a_decoy(): void
    {
        Http::fake([
            'api.deezer.com/search/artist*' => Http::response([
                'data' => [
                    ['id' => 111, 'name' => 'Drake', 'nb_fan' => 76],
                    ['id' => 222, 'name' => 'Drake', 'nb_fan' => 95],
                    // A different, more-followed artist whose name only
                    // partially/differently matches - must never win.
                    ['id' => 333, 'name' => 'Drakeo', 'nb_fan' => 5_000_000],
                    ['id' => 444, 'name' => 'Drake', 'nb_fan' => 24_063_063],
                ],
            ], 200),
        ]);

        $id = app(DeezerClient::class)->findArtistId('Drake');

        $this->assertSame('444', $id);
    }

    public function test_name_matching_is_case_insensitive_and_trims_whitespace(): void
    {
        Http::fake([
            'api.deezer.com/search/artist*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Not It', 'nb_fan' => 9_999_999],
                    ['id' => 2, 'name' => 'sido', 'nb_fan' => 1_327_425],
                ],
            ], 200),
        ]);

        $id = app(DeezerClient::class)->findArtistId('  Sido  ');

        $this->assertSame('2', $id);
    }

    public function test_it_falls_back_to_the_highest_fan_count_overall_when_nothing_matches_exactly(): void
    {
        Http::fake([
            'api.deezer.com/search/artist*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Sidoo', 'nb_fan' => 500],
                    ['id' => 2, 'name' => 'Sid', 'nb_fan' => 50_000],
                ],
            ], 200),
        ]);

        $id = app(DeezerClient::class)->findArtistId('Sido');

        $this->assertSame('2', $id);
    }

    public function test_it_returns_null_when_deezer_has_no_matching_artist_at_all(): void
    {
        Http::fake([
            'api.deezer.com/search/artist*' => Http::response(['data' => []], 200),
        ]);

        $id = app(DeezerClient::class)->findArtistId('Totally Unknown Act');

        $this->assertNull($id);
    }
}
