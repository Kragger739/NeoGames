<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitGuessRequest;
use App\Models\Round;
use App\Services\GuessService;
use Illuminate\Validation\ValidationException;

class RoundController extends Controller
{
    public function guess(SubmitGuessRequest $request, Round $round, GuessService $guessService)
    {
        $player = $request->user();

        if ($player->room_id !== $round->room_id) {
            abort(403);
        }

        if ($round->status->value !== 'playing') {
            throw ValidationException::withMessages([
                'guess' => ['This round has already ended.'],
            ]);
        }

        if ($player->is_eliminated) {
            throw ValidationException::withMessages([
                'guess' => ['You have been eliminated from this round.'],
            ]);
        }

        $result = $guessService->submit($round, $player, $request->validated('guess'));

        return response()->json($result);
    }
}
