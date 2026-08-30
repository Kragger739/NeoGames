<?php

namespace App\Http\Controllers\Api;

use App\Enums\DatasetType;
use App\Enums\DatasetVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\DdfQuestionRequest;
use App\Http\Requests\ImportPlaylistRequest;
use App\Http\Requests\StoreDatasetRequest;
use App\Http\Requests\UpdateDatasetRequest;
use App\Models\Dataset;
use App\Models\DatasetTrack;
use App\Models\DdfQuestion;
use App\Services\SongleDatasetService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Workshop CRUD. Every write authorizes against DatasetPolicy; nested
 * question/track routes authorize `update` on the parent dataset and verify
 * the child belongs to it. Client-supplied ids are never trusted for
 * authorization - datasets arrive via route-model binding + policy check.
 */
class DatasetController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $type = $request->query('type');

        $base = Dataset::query()
            ->when(in_array($type, DatasetType::values(), true), fn ($q) => $q->where('type', $type))
            ->withCount(['questions', 'tracks'])
            ->with('owner:id,username,name')
            ->latest('updated_at');

        return response()->json([
            'mine' => (clone $base)->where('owner_id', $user->id)->get()
                ->map(fn (Dataset $d) => $this->summary($d)),
            'community' => (clone $base)
                ->where('visibility', DatasetVisibility::Public->value)
                ->where('owner_id', '!=', $user->id)
                ->get()->map(fn (Dataset $d) => $this->summary($d)),
        ]);
    }

    public function store(StoreDatasetRequest $request)
    {
        $isDdf = $request->validated('type') === DatasetType::Ddf->value;

        $dataset = $request->user()->datasets()->create([
            'name' => $request->validated('name'),
            'type' => $request->validated('type'),
            'visibility' => DatasetVisibility::Private->value,
            'language' => $isDdf ? $request->validated('language') : null,
        ]);

        return response()->json($this->detail($dataset), 201);
    }

    public function show(Request $request, Dataset $dataset)
    {
        $this->authorize('view', $dataset);

        return response()->json($this->detail($dataset));
    }

    public function update(UpdateDatasetRequest $request, Dataset $dataset)
    {
        $this->authorize('update', $dataset);
        $dataset->update($request->validated());

        return response()->json($this->detail($dataset->fresh()));
    }

    public function destroy(Request $request, Dataset $dataset)
    {
        $this->authorize('delete', $dataset);
        $dataset->delete();

        return response()->noContent();
    }

    public function duplicate(Request $request, Dataset $dataset)
    {
        $this->authorize('view', $dataset);

        $copy = $request->user()->datasets()->create([
            'name' => mb_substr($dataset->name.' (copy)', 0, 80),
            'type' => $dataset->type->value,
            'visibility' => DatasetVisibility::Private->value,
            'language' => $dataset->language,
        ]);

        if ($dataset->type === DatasetType::Ddf) {
            foreach ($dataset->questions as $question) {
                $copy->questions()->create([
                    'category' => $question->category->value,
                    'language' => $copy->language,
                    'text' => $question->text,
                    'correct_answer' => $question->correct_answer,
                    'position' => $question->position,
                ]);
            }
        } else {
            foreach ($dataset->tracks as $track) {
                $copy->tracks()->create($track->only([
                    'deezer_track_id', 'title', 'artist', 'album_art_url', 'preview_url', 'position',
                ]));
            }
        }

        return response()->json($this->detail($copy), 201);
    }

    // ---- questions (ddf datasets) --------------------------------------------

    public function storeQuestion(DdfQuestionRequest $request, Dataset $dataset)
    {
        $this->authorize('update', $dataset);
        $this->assertType($dataset, DatasetType::Ddf);

        $dataset->questions()->create([
            'category' => $request->validated('category'),
            'language' => $dataset->language,
            'text' => $request->validated('text'),
            'correct_answer' => $request->validated('correct_answer'),
            'position' => (int) $dataset->questions()->max('position') + 1,
        ]);

        return response()->json($this->detail($dataset->fresh()), 201);
    }

    public function updateQuestion(DdfQuestionRequest $request, Dataset $dataset, DdfQuestion $question)
    {
        $this->authorize('update', $dataset);
        $this->assertQuestionBelongs($question, $dataset);

        $question->update([
            'category' => $request->validated('category'),
            'text' => $request->validated('text'),
            'correct_answer' => $request->validated('correct_answer'),
        ]);

        return response()->json($this->detail($dataset->fresh()));
    }

    public function destroyQuestion(Request $request, Dataset $dataset, DdfQuestion $question)
    {
        $this->authorize('update', $dataset);
        $this->assertQuestionBelongs($question, $dataset);
        $question->delete();

        return response()->json($this->detail($dataset->fresh()));
    }

    public function reorderQuestions(Request $request, Dataset $dataset)
    {
        $this->authorize('update', $dataset);
        $this->assertType($dataset, DatasetType::Ddf);

        $ids = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ])['ids'];

        $ownIds = $dataset->questions()->pluck('id')->all();

        if (array_diff($ids, $ownIds) !== [] || count($ids) !== count($ownIds)) {
            throw ValidationException::withMessages([
                'ids' => ['The list must contain every question in this dataset exactly once.'],
            ]);
        }

        foreach (array_values($ids) as $position => $id) {
            DdfQuestion::where('id', $id)->update(['position' => $position]);
        }

        return response()->json($this->detail($dataset->fresh()));
    }

    // ---- tracks (songle datasets) -----------------------------------------

    public function importPlaylist(ImportPlaylistRequest $request, Dataset $dataset, SongleDatasetService $service)
    {
        $this->authorize('update', $dataset);
        $this->assertType($dataset, DatasetType::Songle);

        $service->importPlaylist($dataset, $request->validated('playlist'));
        $dataset->touch();

        return response()->json($this->detail($dataset->fresh()));
    }

    public function destroyTrack(Request $request, Dataset $dataset, DatasetTrack $track)
    {
        $this->authorize('update', $dataset);

        if ($track->dataset_id !== $dataset->id) {
            abort(404);
        }

        $track->delete();
        $dataset->touch();

        return response()->json($this->detail($dataset->fresh()));
    }

    // ---- helpers --------------------------------------------------------------

    private function assertType(Dataset $dataset, DatasetType $type): void
    {
        if ($dataset->type !== $type) {
            abort(422, "This isn’t a {$type->value} dataset.");
        }
    }

    private function assertQuestionBelongs(DdfQuestion $question, Dataset $dataset): void
    {
        if ($question->dataset_id !== $dataset->id) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Dataset $dataset): array
    {
        return [
            'id' => $dataset->id,
            'name' => $dataset->name,
            'type' => $dataset->type->value,
            'visibility' => $dataset->visibility->value,
            'item_count' => $dataset->type === DatasetType::Ddf
                ? ($dataset->questions_count ?? $dataset->questions()->count())
                : ($dataset->tracks_count ?? $dataset->tracks()->count()),
            'updated_at' => $dataset->updated_at,
            'owner_username' => $dataset->owner?->username ?? $dataset->owner?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Dataset $dataset): array
    {
        $dataset->loadMissing('owner:id,username,name');

        $out = $this->summary($dataset) + [
            'owner_id' => $dataset->owner_id,
            'language' => $dataset->language,
        ];

        if ($dataset->type === DatasetType::Ddf) {
            $out['questions'] = $dataset->questions()->get()->map(fn (DdfQuestion $q) => [
                'id' => $q->id,
                'text' => $q->text,
                'correct_answer' => $q->correct_answer,
                'category' => $q->category->value,
                'position' => $q->position,
            ]);
        } else {
            $out['tracks'] = $dataset->tracks()->get()->map(fn (DatasetTrack $t) => [
                'id' => $t->id,
                'deezer_track_id' => $t->deezer_track_id,
                'title' => $t->title,
                'artist' => $t->artist,
                'album_art_url' => $t->album_art_url,
                'position' => $t->position,
            ]);
        }

        return $out;
    }
}
