<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DubClip;
use App\Services\Dub\DubClipManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD for the "Dub Together" clip library. Shape-validates the
 * request, then delegates to DubClipManager (shared with the Workshop
 * pack routes). Two create paths: a multipart video upload, or a video
 * link that yt-dlp downloads asynchronously (status processing -> review).
 */
class AdminDubClipController extends Controller
{
    public function __construct(private readonly DubClipManager $clips) {}

    public function index()
    {
        return response()->json([
            'clips' => DubClip::query()
                ->withCount(['characters', 'lines'])
                ->orderByDesc('id')
                ->get()
                ->map(fn (DubClip $clip) => $this->clips->row($clip)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'video' => ['required', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:40960'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $clip = $this->clips->createFromUpload(
            ['source' => 'library', 'created_by_user_id' => $request->user()->id, 'title' => $data['title']],
            $request->file('video'),
            $request->integer('duration_ms') ?: null,
        );

        return response()->json($this->clips->row($clip), 201);
    }

    public function storeYoutube(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:500', $this->youtubeUrlRule()],
        ]);

        $clip = $this->clips->createFromLink(
            ['source' => 'library', 'created_by_user_id' => $request->user()->id, 'title' => $data['title']],
            $data['url'],
        );

        return response()->json($this->clips->row($clip), 201);
    }

    public function reingest(DubClip $dubClip)
    {
        $this->clips->reingest($dubClip);

        return response()->json($this->clips->row($dubClip->fresh()->loadCount(['characters', 'lines'])));
    }

    public function show(DubClip $dubClip)
    {
        return response()->json($this->clips->detail($dubClip));
    }

    /** POST (multipart) so a replacement video can ride along with the field edits. */
    public function update(Request $request, DubClip $dubClip)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'is_public' => ['sometimes', 'boolean'],
            'video' => ['sometimes', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:40960'],
        ]);

        $clip = $this->clips->updateMeta($dubClip, $data, $request->file('video'));

        return response()->json($this->clips->row($clip));
    }

    public function putScript(Request $request, DubClip $dubClip)
    {
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

    public function publish(DubClip $dubClip)
    {
        return response()->json($this->clips->row($this->clips->publish($dubClip)));
    }

    public function unpublish(DubClip $dubClip)
    {
        return response()->json($this->clips->row($this->clips->unpublish($dubClip)));
    }

    public function destroy(DubClip $dubClip)
    {
        $this->clips->delete($dubClip);

        return response()->noContent();
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
