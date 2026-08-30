<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $users = User::query()
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)
                        ->orWhere('username', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json([
            'data' => collect($users->items())->map(fn (User $user) => $this->toAdminArray($user))->all(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toAdminArray(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'provider' => $user->provider,
            'xp' => (int) ($user->xp ?? 0),
            'level' => $user->level,
            'is_admin' => (bool) $user->is_admin,
            'banned_at' => optional($user->banned_at)->toIso8601String(),
            'ban_reason' => $user->ban_reason,
            'created_at' => $user->created_at?->toIso8601String(),
            'avatar' => $user->avatarPayload(),
        ];
    }
}
