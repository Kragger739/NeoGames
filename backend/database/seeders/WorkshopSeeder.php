<?php

namespace Database\Seeders;

use App\Models\Dataset;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A small demo Workshop dataset so `/workshop` isn't empty on a fresh
 * migrate:fresh --seed. Songle datasets aren't seeded (they need a live
 * Deezer import).
 */
class WorkshopSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()->firstWhere('email', 'test@example.com')
            ?? User::query()->first();

        if ($owner === null) {
            return;
        }

        $dataset = Dataset::query()->firstOrCreate(
            ['owner_id' => $owner->id, 'name' => 'Family Quiz'],
            ['type' => 'ddf', 'visibility' => 'public', 'language' => 'en'],
        );

        if ($dataset->questions()->exists()) {
            return;
        }

        $questions = [
            ['category' => 'movies_tv', 'text' => 'Which movie features a character named Forrest Gump?', 'correct_answer' => 'Forrest Gump'],
            ['category' => 'geography', 'text' => 'Which country is home to the kangaroo?', 'correct_answer' => 'Australia'],
            ['category' => 'science', 'text' => 'What gas do plants absorb from the air?', 'correct_answer' => 'Carbon dioxide'],
            ['category' => 'music', 'text' => 'Which band released the album "Abbey Road"?', 'correct_answer' => 'The Beatles'],
            ['category' => 'everyday_knowledge', 'text' => 'How many minutes are there in a full day?', 'correct_answer' => '1440'],
        ];

        foreach ($questions as $position => $question) {
            $dataset->questions()->create([
                ...$question,
                'language' => 'en',
                'position' => $position,
            ]);
        }
    }
}
