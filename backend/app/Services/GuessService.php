<?php

namespace App\Services;

use App\Enums\GameMode;
use App\Events\GuessMissed;
use App\Events\RoundWon;
use App\Models\Guess;
use App\Models\Round;
use App\Models\RoomPlayer;
use App\Support\Scoring;
use Illuminate\Support\Str;

class GuessService
{
    public function __construct(private RoundService $roundService) {}

    /**
     * @return array{correct: bool, won: bool}
     */
    public function submit(Round $round, RoomPlayer $player, string $guessText): array
    {
        $correct = $this->isCorrect($guessText, $round);
        $mode = $round->room->mode;

        Guess::create([
            'round_id' => $round->id,
            'player_id' => $player->id,
            'guess_text' => $guessText,
            'correct' => $correct,
            'snippet_stage_at_guess' => $round->snippet_stage,
        ]);

        if (! $correct) {
            broadcast(new GuessMissed($round, $player->nickname));

            if ($mode === GameMode::Solo) {
                return $this->roundService->escalateSoloStage($round);
            }

            return ['correct' => false, 'won' => false];
        }

        if ($mode === GameMode::BattleRoyale) {
            return $this->resolveBattleRoyaleGuess($round, $player);
        }

        // Classic + Solo: atomic conditional update, only the first
        // correct guess wins the round, race-safe without explicit
        // row locking.
        $won = Round::where('id', $round->id)
            ->where('status', 'playing')
            ->update([
                'status' => 'won',
                'winning_player_id' => $player->id,
            ]);

        if (! $won) {
            return ['correct' => true, 'won' => false];
        }

        $points = Scoring::pointsForStage((float) $round->snippet_stage);

        $player->increment('score', $points);

        $round->refresh();
        $round->setRelation('winningPlayer', $player);

        $this->roundService->ensureRevealStats($round);
        broadcast(new RoundWon($round, $points));

        $this->roundService->advanceAfterRoundResolved($round);

        return ['correct' => true, 'won' => true];
    }

    /**
     * Battle Royale: the round doesn't end on the first correct guess -
     * every active player gets scored independently at their own stage,
     * and the round only closes once every active player has answered
     * correctly (or the final stage's timer runs out).
     *
     * @return array{correct: bool, won: bool}
     */
    private function resolveBattleRoyaleGuess(Round $round, RoomPlayer $player): array
    {
        $correctGuessCount = $round->guesses()
            ->where('player_id', $player->id)
            ->where('correct', true)
            ->count();

        // Only the first correct guess this round earns points - a player
        // isn't currently blocked from re-submitting after already being
        // right, so this guards against double-scoring them.
        if ($correctGuessCount === 1) {
            $player->increment('score', Scoring::pointsForStage((float) $round->snippet_stage));
        }

        $activeIds = $round->room->activePlayers()->pluck('id');
        $correctIds = $round->correctGuesserIds();

        if ($activeIds->diff($correctIds)->isEmpty()) {
            $this->roundService->resolveBattleRoyaleRound($round);
        }

        return ['correct' => true, 'won' => true];
    }

    private function isCorrect(string $guessText, Round $round): bool
    {
        $normalized = $this->normalize($guessText);

        return $normalized === $this->normalize($round->song->title)
            || $normalized === $this->normalize($round->song->artist);
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->lower()->trim()->squish()->toString();
    }
}
