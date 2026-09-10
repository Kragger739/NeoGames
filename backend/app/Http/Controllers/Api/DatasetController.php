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
use App\Support\DatasetPresenter;
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
            ->withCount(['questions', 'tracks', 'dubClips'])
            ->with('owner:id,username,name')
            ->latest('updated_at');

        return response()->json([
            'mine' => (clone $base)->where('owner_id', $user->id)->get()
                ->map(fn (Dataset $d) => DatasetPresenter::summary($d)),
            'community' => (clone $base)
                ->where('visibility', DatasetVisibility::Public->value)
                // Dub packs need admin approval before others can play them;
                // the column defaults to 'approved' for ddf/songle so this is
                // a no-op for those.
                ->where('review_status', 'approved')
                ->where('owner_id', '!=', $user->id)
                ->get()->map(fn (Dataset $d) => DatasetPresenter::summary($d)),
        ]);
    }

    public function store(StoreDatasetRequest $request)
    {
        $type = $request->validated('type');

        $dataset = $request->user()->datasets()->create([
            'name' => $request->validated('name'),
            'type' => $type,
            'visibility' => DatasetVisibility::Private->value,
            'language' => $type === DatasetType::Ddf->value ? $request->validated('language') : null,
            // Dub packs go through admin review before they can be played by
            // anyone else; everything else is auto-approved.
            'review_status' => $type === DatasetType::Dub->value ? 'draft' : 'approved',
        ]);

        return response()->json(DatasetPresenter::detail($dataset), 201);
    }

    public function show(Request $request, Dataset $dataset)
    {
        $this->authorize('view', $dataset);

        return response()->json(DatasetPresenter::detail($dataset));
    }

    public function update(UpdateDatasetRequest $request, Dataset $dataset)
    {
        $this->authorize('update', $dataset);
        $dataset->update($request->validated());

        // Publishing a dub pack = submitting it for review, not going live.
        if (
            $dataset->type === DatasetType::Dub
            && $dataset->visibility === DatasetVisibility::Public
            && in_array($dataset->review_status, ['draft', 'rejected'], true)
        ) {
            $dataset->update(['review_status' => 'pending']);
        }

        return response()->json(DatasetPresenter::detail($dataset->fresh()));
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

        if ($dataset->type === DatasetType::Dub) {
            abort(422, 'Dub packs can’t be copied.');
        }

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
                    'provider_track_id', 'title', 'artist', 'album_art_url', 'preview_url', 'position',
                ]));
            }
        }

        return response()->json(DatasetPresenter::detail($copy), 201);
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

        return response()->json(DatasetPresenter::detail($dataset->fresh()), 201);
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

        return response()->json(DatasetPresenter::detail($dataset->fresh()));
    }

    public function destroyQuestion(Request $request, Dataset $dataset, DdfQuestion $question)
    {
        $this->authorize('update', $dataset);
        $this->assertQuestionBelongs($question, $dataset);
        $question->delete();

        return response()->json(DatasetPresenter::detail($dataset->fresh()));
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

        return response()->json(DatasetPresenter::detail($dataset->fresh()));
    }

    // ---- tracks (songle datasets) -----------------------------------------

    public function importPlaylist(ImportPlaylistRequest $request, Dataset $dataset, SongleDatasetService $service)
    {
        $this->authorize('update', $dataset);
        $this->assertType($dataset, DatasetType::Songle);

        $service->importPlaylist($dataset, $request->validated('playlist'));
        $dataset->touch();

        return response()->json(DatasetPresenter::detail($dataset->fresh()));
    }

    public function destroyTrack(Request $request, Dataset $dataset, DatasetTrack $track)
    {
        $this->authorize('update', $dataset);

        if ($track->dataset_id !== $dataset->id) {
            abort(404);
        }

        $track->delete();
        $dataset->touch();

        return response()->json(DatasetPresenter::detail($dataset->fresh()));
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
}
