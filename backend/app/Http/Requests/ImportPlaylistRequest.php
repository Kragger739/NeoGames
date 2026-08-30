<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportPlaylistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // A Spotify playlist URL or a bare base-62 id - the exact shape is
        // parsed/validated in SpotifyClient::parsePlaylistId().
        return [
            'playlist' => ['required', 'string', 'max:255'],
        ];
    }
}
