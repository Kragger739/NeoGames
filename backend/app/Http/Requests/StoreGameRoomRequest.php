<?php

namespace App\Http\Requests;

use App\Enums\GameMode;
use App\Enums\SongGenre;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGameRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'songs_per_tier' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'guess_timeout_seconds' => ['sometimes', 'integer', 'min:3', 'max:60'],
            'mode' => ['sometimes', Rule::in(array_column(GameMode::cases(), 'value'))],
            'genre' => ['sometimes', Rule::in(array_column(SongGenre::cases(), 'value'))],
            // nullable is required alongside required_if: the frontend
            // always sends an explicit year_from/year_to (null when genre
            // isn't "year", not simply omitted), and without nullable a
            // literal null fails the integer/min/max rules below even
            // though required_if correctly doesn't demand a value here.
            'year_from' => ['nullable', 'required_if:genre,year', 'integer', 'min:1900', 'max:'.now()->year],
            'year_to' => ['nullable', 'required_if:genre,year', 'integer', 'min:1900', 'max:'.now()->year, 'gte:year_from'],
            'artist_name' => ['nullable', 'required_if:genre,artist', 'string', 'min:1', 'max:100'],
        ];
    }
}
