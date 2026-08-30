<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUpdateUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function show(User $user)
    {
        return response()->json($this->toAdminArray($user));
    }

    public function update(AdminUpdateUserRequest $request, User $user)
    {
        $data = $request->validated();

        $user->name = $data['name'];
        $user->username = $data['username'];
        $user->email = $data['email'];
        $user->is_admin = $data['is_admin'];

        if ($data['email_verified']) {
            $user->email_verified_at = $user->email_verified_at ?? now();
        } else {
            $user->email_verified_at = null;
        }

        $user->save();

        return response()->json($this->toAdminArray($user));
    }

    public function destroy(Request $request, User $user)
    {
        abort_if($user->is($request->user()), 422, 'You cannot delete your own account here.');

        $user->delete();

        return response()->noContent();
    }

    public function ban(Request $request, User $user)
    {
        abort_if($user->is($request->user()), 422, 'You cannot ban your own account.');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $user->forceFill([
            'banned_at' => now(),
            'ban_reason' => $validated['reason'] ?? null,
        ])->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();

        return response()->json($this->toAdminArray($user));
    }

    public function unban(User $user)
    {
        $user->forceFill(['banned_at' => null, 'ban_reason' => null])->save();

        return response()->json($this->toAdminArray($user));
    }

    public function resetXp(User $user)
    {
        $user->forceFill(['xp' => 0])->save();
        $user->seasonProgress()->delete();

        return response()->json($this->toAdminArray($user));
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
