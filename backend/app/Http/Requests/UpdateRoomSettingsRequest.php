<?php

namespace App\Http\Requests;

use App\Enums\DatasetType;
use App\Enums\DifficultyTier;
use App\Enums\GameMode;
use App\Enums\RoomPlayerMode;
use App\Enums\SongGenre;
use App\Models\Dataset;
use App\Rules\BattleRoyaleRequiresLevel;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Host ownership is checked in the controller (same pattern as
        // start()/redo()), not here - this only validates field shape.
        return true;
    }

    public function rules(): array
    {
        return [
            'dataset_id' => ['sometimes', 'nullable', 'integer'],
            'songs_per_tier' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'enabled_tiers' => ['sometimes', 'array', 'min:1'],
            'enabled_tiers.*' => [Rule::in(array_column(DifficultyTier::cases(), 'value'))],
            'guess_timeout_seconds' => ['sometimes', 'integer', 'min:3', 'max:60'],
            'mode' => ['sometimes', Rule::in(array_column(GameMode::cases(), 'value')), new BattleRoyaleRequiresLevel($this)],
            'player_mode' => ['sometimes', Rule::in(array_column(RoomPlayerMode::cases(), 'value'))],
            'genre' => ['sometimes', Rule::in(array_column(SongGenre::cases(), 'value'))],
            // nullable is required alongside required_if: the frontend
            // always sends an explicit year_from/year_to (null when genre
            // isn't "year", not simply omitted), and without nullable a
            // literal null fails the integer/min/max rules below even
            // though required_if correctly doesn't demand a value here.
            'year_from' => ['nullable', 'required_if:genre,year', 'integer', 'min:1900', 'max:'.now()->year],
            'year_to' => ['nullable', 'required_if:genre,year', 'integer', 'min:1900', 'max:'.now()->year, 'gte:year_from'],
            'artist_name' => ['nullable', 'required_if:genre,artist', 'string', 'min:1', 'max:100'],
            'artist_names' => ['nullable', 'required_if:genre,multi_artist', 'array', 'min:1', 'max:'.SongGenre::MAX_MULTI_ARTIST_COUNT],
            'artist_names.*' => ['distinct:ignore_case', 'string', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $id = $this->input('dataset_id');

            if (blank($id)) {
                return;
            }

            $dataset = Dataset::find($id);

            if (! $dataset || $dataset->type !== DatasetType::Songle || ! $this->user()?->can('view', $dataset)) {
                $validator->errors()->add('dataset_id', 'That song source isn’t available.');
            }
        });
    }
}
