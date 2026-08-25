<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JoinRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // A logged-in visitor auto-joins as themselves (see
        // RoomPlayerController::store()) and never submits a nickname at
        // all, so it's only required for an anonymous join.
        $isAuthenticated = $this->user('sanctum') !== null;

        return [
            'nickname' => [$isAuthenticated ? 'sometimes' : 'required', 'string', 'min:1', 'max:20'],
        ];
    }
}
