<?php

namespace Tests\Feature\Music;

use App\Services\Music\AppleMusicClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AppleMusicClientTest extends TestCase
{
    private function itunesResult(string $artist, string $track): array
    {
        return [
            'kind' => 'song',
            'trackId' => 267954487,
            'trackName' => $track,
            'artistName' => $artist,
            'previewUrl' => 'https://audio-ssl.itunes.apple.com/x.m4a',
            'artworkUrl100' => 'https://is1-ssl.mzstatic.com/image/thumb/x/100x100bb.jpg',
            'releaseDate' => '2000-04-11T07:00:00Z',
        ];
    }

    public function test_it_returns_the_matching_song_with_upscaled_artwork(): void
    {
        Http::fake(['itunes.apple.com/search*' => Http::response(['results' => [
            $this->itunesResult('Someone Else', 'Wrong Song'),
            $this->itunesResult('Britney Spears', 'Oops!...I Did It Again'),
        ]], 200)]);

        $out = app(AppleMusicClient::class)->findPreview('Britney Spears', 'Oops!...I Did It Again');

        $this->assertSame('267954487', $out['itunes_track_id']);
        $this->assertSame('https://audio-ssl.itunes.apple.com/x.m4a', $out['preview_url']);
        $this->assertStringContainsString('600x600bb', $out['album_art_url']);
        $this->assertSame(2000, $out['release_year']);
    }

    public function test_it_returns_null_when_nothing_matches_confidently(): void
    {
        Http::fake(['itunes.apple.com/search*' => Http::response(['results' => [
            $this->itunesResult('A Totally Different Act', 'A Totally Different Song'),
        ]], 200)]);

        $this->assertNull(app(AppleMusicClient::class)->findPreview('Daft Punk', 'One More Time'));
    }

    public function test_a_403_raises_a_runtime_exception(): void
    {
        Http::fake(['itunes.apple.com/search*' => Http::response('rate limited', 403)]);

        $this->expectException(RuntimeException::class);
        app(AppleMusicClient::class)->findPreview('x', 'y');
    }
}
