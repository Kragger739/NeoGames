<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DubClip;
use App\Support\DubPresenter;
use Illuminate\Http\Request;

/**
 * "Dub Together" clip library. PHASE 1: read-only listing that backs the
 * lobby clip picker. Phase 2 adds host upload, the review-and-fix editor
 * endpoints (updateCharacters / updateLines / publish) and the sidecar
 * webhook; Phase 3 adds the processing pipeline.
 */
class DubClipController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('sanctum');

        $clips = DubClip::query()
            ->where('status', 'ready')
            ->where(function ($q) use ($user) {
                $q->where(fn ($q) => $q->where('source', 'library')->where('is_public', true));

                if ($user) {
                    $q->orWhere('created_by_user_id', $user->id);
                }
            })
            ->with(['characters', 'lines'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (DubClip $clip) => [
                'id' => $clip->id,
                'title' => $clip->title,
                'source' => $clip->source,
                'duration_ms' => $clip->duration_ms,
                'video_url' => DubPresenter::url($clip->source_video_path),
                'character_count' => $clip->characters->count(),
                'line_count' => $clip->lines->count(),
            ]);

        return response()->json(['clips' => $clips]);
    }
}
