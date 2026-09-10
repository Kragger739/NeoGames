<?php

namespace App\Http\Controllers\Api;

use App\Enums\DatasetType;
use App\Http\Controllers\Controller;
use App\Models\Dataset;
use App\Models\DubClip;
use App\Services\Dub\DubClipManager;
use App\Support\DatasetPresenter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The dub clips inside a Workshop pack (datasets.type = 'dub'). Every write
 * authorizes `update` on the parent dataset (owner-only, even for a public
 * pack) and verifies the clip belongs to it. Delegates to DubClipManager,
 * the same engine the admin library uses. List-level mutations return the
 * re-serialized parent dataset (the Workshop convention); the editor's
 * per-clip routes return the clip detail so DubClipReviewEditor can reuse
 * its exact admin-library flow.
 */
class DubPackClipController extends Controller
{
    public function __construct(private readonly DubClipManager $clips) {}

    public function store(Request $request, Dataset $dataset)
    {
        $this->guard($dataset);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'video' => ['required', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:40960'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->clips->createFromUpload(
            $this->newClipAttrs($request, $dataset, $data['title']),
            $request->file('video'),
            $request->integer('duration_ms') ?: null,
        );

        return response()->json(DatasetPresenter::detail($dataset->fresh()), 201);
    }

    public function storeYoutube(Request $request, Dataset $dataset)
    {
        $this->guard($dataset);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:500', $this->youtubeUrlRule()],
        ]);

        $this->clips->createFromLink($this->newClipAttrs($request, $dataset, $data['title']), $data['url']);

        return response()->json(DatasetPresenter::detail($dataset->fresh()), 201);
    }

    public function reorder(Request $request, Dataset $dataset)
    {
        $this->guard($dataset);

        $ids = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ])['ids'];

        $ownIds = $dataset->dubClips()->pluck('id')->all();

        if (array_diff($ids, $ownIds) !== [] || count($ids) !== count($ownIds)) {
            throw ValidationException::withMessages([
                'ids' => ['The list must contain every clip in this pack exactly once.'],
            ]);
        }

        foreach (array_values($ids) as $position => $id) {
            DubClip::where('id', $id)->update(['position' => $position]);
        }

        return response()->json(DatasetPresenter::detail($dataset->fresh()));
    }

    public function show(Request $request, Dataset $dataset, DubClip $dubClip)
    {
        $this->guard($dataset, $dubClip);

        return response()->json($this->clips->detail($dubClip));
    }

    public function putScript(Request $request, Dataset $dataset, DubClip $dubClip)
    {
        $this->guard($dataset, $dubClip);

        $data = $request->validate([
            'characters' => ['present', 'array'],
            'characters.*.ref' => ['required', 'string', 'max:40'],
            'characters.*.display_name' => ['required', 'string', 'max:80'],
            'characters.*.color' => ['nullable', Rule::in(DubClip::HUES)],
            'lines' => ['present', 'array'],
            'lines.*.character_ref' => ['required', 'string', 'max:40'],
            'lines.*.start_ms' => ['required', 'integer', 'min:0'],
            'lines.*.end_ms' => ['required', 'integer', 'min:1'],
            'lines.*.text' => ['nullable', 'string', 'max:500'],
            'clip_start_ms' => ['nullable', 'integer', 'min:0'],
            'clip_end_ms' => ['nullable', 'integer', 'min:1'],
        ]);

        $clip = $this->clips->replaceScript(
            $dubClip,
            $data['characters'],
            $data['lines'],
            $data['clip_start_ms'] ?? null,
            $data['clip_end_ms'] ?? null,
        );

        return response()->json($this->clips->detail($clip));
    }

    public function publish(Request $request, Dataset $dataset, DubClip $dubClip)
    {
        $this->guard($dataset, $dubClip);

        return response()->json($this->clips->detail($this->clips->publish($dubClip)));
    }

    public function unpublish(Request $request, Dataset $dataset, DubClip $dubClip)
    {
        $this->guard($dataset, $dubClip);

        return response()->json($this->clips->detail($this->clips->unpublish($dubClip)));
    }

    public function update(Request $request, Dataset $dataset, DubClip $dubClip)
    {
        $this->guard($dataset, $dubClip);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'video' => ['sometimes', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:40960'],
        ]);

        $this->clips->updateMeta($dubClip, $data, $request->file('video'));

        return response()->json(DatasetPresenter::detail($dataset->fresh()));
    }

    public function destroy(Request $request, Dataset $dataset, DubClip $dubClip)
    {
        $this->guard($dataset, $dubClip);
        $this->clips->delete($dubClip);

        return response()->json(DatasetPresenter::detail($dataset->fresh()));
    }

    // ------------------------------------------------------------------

    private function guard(Dataset $dataset, ?DubClip $clip = null): void
    {
        $this->authorize('update', $dataset);

        if ($dataset->type !== DatasetType::Dub) {
            abort(422, 'This isn’t a dub pack.');
        }

        if ($clip !== null && $clip->dataset_id !== $dataset->id) {
            abort(404);
        }
    }

    /** @return array<string, mixed> */
    private function newClipAttrs(Request $request, Dataset $dataset, string $title): array
    {
        return [
            'source' => 'workshop',
            'dataset_id' => $dataset->id,
            'created_by_user_id' => $request->user()->id,
            'title' => $title,
            'position' => (int) $dataset->dubClips()->max('position') + 1,
        ];
    }

    private function youtubeUrlRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! preg_match('~^https?://(www\.|m\.)?(youtube\.com/(watch\?|shorts/|live/)|youtu\.be/)~i', (string) $value)) {
                $fail('Paste a YouTube video link.');
            }
        };
    }
}
