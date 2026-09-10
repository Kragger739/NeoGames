<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\DatasetType;
use App\Http\Controllers\Controller;
use App\Models\Dataset;
use App\Support\DubPresenter;
use Illuminate\Http\Request;

/**
 * Admin review queue for user-authored "Dub Together" packs. A pack a user
 * publishes lands here as 'pending'; approving it lets everyone play it,
 * rejecting keeps it out with a note the owner sees.
 */
class AdminDubPackController extends Controller
{
    public function index()
    {
        $order = ['pending' => 0, 'rejected' => 1, 'approved' => 2];

        return response()->json([
            'packs' => Dataset::query()
                ->where('type', DatasetType::Dub->value)
                ->whereIn('review_status', ['pending', 'approved', 'rejected'])
                ->with('owner:id,username,name')
                ->withCount('dubClips')
                ->get()
                ->sortBy(fn (Dataset $d) => [$order[$d->review_status] ?? 9, -$d->updated_at->timestamp])
                ->values()
                ->map(fn (Dataset $d) => $this->row($d)),
        ]);
    }

    public function approve(Dataset $dataset)
    {
        $this->assertPack($dataset);
        $dataset->update(['review_status' => 'approved', 'review_note' => null]);

        return response()->json($this->row($dataset->fresh()->loadCount('dubClips')));
    }

    public function reject(Request $request, Dataset $dataset)
    {
        $this->assertPack($dataset);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $dataset->update(['review_status' => 'rejected', 'review_note' => $data['note'] ?? null]);

        return response()->json($this->row($dataset->fresh()->loadCount('dubClips')));
    }

    private function assertPack(Dataset $dataset): void
    {
        abort_unless($dataset->type === DatasetType::Dub, 404);
    }

    /** @return array<string, mixed> */
    private function row(Dataset $dataset): array
    {
        return [
            'id' => $dataset->id,
            'name' => $dataset->name,
            'owner_username' => $dataset->owner?->username ?? $dataset->owner?->name,
            'review_status' => $dataset->review_status,
            'review_note' => $dataset->review_note,
            'clip_count' => $dataset->dub_clips_count ?? $dataset->dubClips()->count(),
            'ready_clip_count' => $dataset->dubClips()->where('status', 'ready')->count(),
            'clips' => $dataset->dubClips()->orderBy('position')->get()->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'status' => $c->status,
                'video_url' => DubPresenter::url($c->source_video_path),
            ]),
            'updated_at' => $dataset->updated_at,
        ];
    }
}
