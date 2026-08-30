# Admin Dashboard + Public Admin Badge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give admins an in-app `/admin` area to manage user accounts (edit, delete, ban/unban, reset XP), and show every visitor a badge on users who are admins.

**Architecture:** A single `is_admin` boolean plus `banned_at` / `ban_reason` columns on `users`. An `EnsureUserIsAdmin` middleware guards a new `/api/admin/*` route group served by `AdminUserController`. An `EnsureUserNotBanned` middleware plus a check in `LoginRequest` enforce bans. The public badge is threaded through the existing `User::avatarPayload()` chokepoint so it reaches every surface via one `<Avatar>` change. The SPA gets `/admin` + `/admin/users/:id` routes behind a `RequireAdmin` guard, an `adminStore`, and an admin-only nav link.

**Tech Stack:** Laravel + Sanctum (SPA session auth), PHPUnit (`RefreshDatabase`, model factories); React 19 + react-router 7 + zustand 5 + axios, Vite (`tsc -b` typecheck), oxlint.

**Spec:** `docs/superpowers/specs/2026-08-30-admin-dashboard-design.md`

## Global Constraints

- **Backend follows TDD:** every backend task writes a failing PHPUnit feature test first, watches it fail, implements, watches it pass, commits.
- **Frontend has no test harness** (no vitest/testing-library in the repo). Frontend tasks verify with `cd frontend && npm run build` (runs `tsc -b && vite build`) and `cd frontend && npm run lint` (oxlint), plus the manual smoke steps written into each task. Do **not** add a test runner.
- **Backend test command:** run from `backend/`: `php artisan test --filter=<ClassOrMethod>`. Full suite: `php artisan test`.
- **No new dependencies**, backend or frontend.
- **Username validation** matches the existing rule everywhere: `['required','string','min:2','max:24','alpha_dash', Rule::unique('users','username')->ignore($id)]`.
- **`is_admin` is never mass-assignable** — it is not added to `User`'s `#[Fillable]`; the admin controller sets it via explicit property assignment + `save()`.
- **Self-protection:** an admin acting on their own account cannot set their own `is_admin` false, cannot `destroy` it, cannot `ban` it. Each returns HTTP 422.
- **Commit after every task** (each task's final step). Commit messages: `feat:` / `test:` prefix, imperative.
- New backend middleware lives in `backend/app/Http/Middleware/` (the dir does not exist yet — create it). Middleware aliases are registered in `backend/bootstrap/app.php` inside the `->withMiddleware(function (Middleware $middleware) { ... })` closure via `$middleware->alias([...])`.

---

## File Structure

**Backend — create:**
- `backend/database/migrations/2026_08_30_000001_add_admin_and_moderation_to_users_table.php` — schema: `is_admin`, `banned_at`, `ban_reason`.
- `backend/database/migrations/2026_08_30_000002_promote_initial_admin.php` — data migration promoting `diamondpickminer@gmail.com`.
- `backend/app/Http/Middleware/EnsureUserIsAdmin.php` — 403s non-admins.
- `backend/app/Http/Middleware/EnsureUserNotBanned.php` — 403s + logs out banned users.
- `backend/app/Http/Controllers/Api/Admin/AdminUserController.php` — index/show/update/destroy/ban/unban/resetXp + private `toAdminArray()`.
- `backend/app/Http/Requests/Admin/AdminUpdateUserRequest.php` — validation for `update`.
- `backend/tests/Feature/Admin/AdminUserManagementTest.php`
- `backend/tests/Feature/Admin/BannedUserTest.php`

**Backend — modify:**
- `backend/app/Models/User.php` — casts (`is_admin`, `banned_at`), `avatarPayload()` gains `is_admin`, add `isBanned()`.
- `backend/bootstrap/app.php` — register `admin` + `not-banned` aliases.
- `backend/routes/api.php` — add admin group; wrap the authed `auth:sanctum` group with `not-banned`.
- `backend/app/Http/Requests/Auth/LoginRequest.php` — reject banned users after `Auth::attempt()`.
- `backend/tests/Feature/Cosmetics/AvatarPayloadTest.php` — assert `is_admin` in the payload.

**Frontend — create:**
- `frontend/src/stores/adminStore.ts` — zustand store for the admin user list + mutations.
- `frontend/src/components/RequireAdmin.tsx` — route guard.
- `frontend/src/pages/AdminUsersPage.tsx` — searchable, paginated list.
- `frontend/src/pages/AdminUserDetailPage.tsx` — edit form + moderation actions.

**Frontend — modify:**
- `frontend/src/lib/avatarData.ts` — `AvatarData.is_admin?: boolean`; `EMPTY_AVATAR` gains `is_admin: false`.
- `frontend/src/stores/authStore.ts` — `Host.is_admin: boolean`.
- `frontend/src/components/ui/Avatar.tsx` — render an admin badge layer.
- `frontend/src/styles/avatar.css` — `.avatar-admin-badge` rule.
- `frontend/src/App.tsx` — `/admin` and `/admin/users/:id` routes.
- `frontend/src/pages/HomePage.tsx` — admin-only nav `<Link>`.

---

## Task 1: Schema, model flag, and payload

**Files:**
- Create: `backend/database/migrations/2026_08_30_000001_add_admin_and_moderation_to_users_table.php`
- Create: `backend/database/migrations/2026_08_30_000002_promote_initial_admin.php`
- Modify: `backend/app/Models/User.php`
- Test: `backend/tests/Feature/Cosmetics/AvatarPayloadTest.php` (extend)

**Interfaces:**
- Produces:
  - `users.is_admin` (bool, default false, indexed), `users.banned_at` (nullable timestamp), `users.ban_reason` (nullable string).
  - `User::casts()` includes `'is_admin' => 'boolean'`, `'banned_at' => 'datetime'`.
  - `User::isBanned(): bool` — `$this->banned_at !== null`.
  - `User::avatarPayload(): array` — now also returns `'is_admin' => (bool) $this->is_admin` alongside `avatar_url`, `level`, `cosmetics`.

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Feature/Cosmetics/AvatarPayloadTest.php`:

```php
public function test_avatar_payload_includes_the_admin_flag(): void
{
    $admin = User::factory()->create(['is_admin' => true]);
    $plain = User::factory()->create();

    $this->actingAs($admin)->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('avatar.is_admin', true);

    $this->actingAs($plain)->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('avatar.is_admin', false);
}

public function test_is_banned_reflects_the_column(): void
{
    $user = User::factory()->create();
    $this->assertFalse($user->isBanned());

    $user->update(['banned_at' => now()]);
    $this->assertTrue($user->fresh()->isBanned());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AvatarPayloadTest`
Expected: FAIL — `is_admin` column missing / `isBanned()` undefined.

- [ ] **Step 3: Write the schema migration**

`backend/database/migrations/2026_08_30_000001_add_admin_and_moderation_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->index()->after('password');
            $table->timestamp('banned_at')->nullable()->after('is_admin');
            $table->string('ban_reason')->nullable()->after('banned_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'banned_at', 'ban_reason']);
        });
    }
};
```

- [ ] **Step 4: Write the promote migration**

`backend/database/migrations/2026_08_30_000002_promote_initial_admin.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const EMAIL = 'diamondpickminer@gmail.com';

    public function up(): void
    {
        DB::table('users')->where('email', self::EMAIL)->update(['is_admin' => true]);
    }

    public function down(): void
    {
        DB::table('users')->where('email', self::EMAIL)->update(['is_admin' => false]);
    }
};
```

- [ ] **Step 5: Update the `User` model**

In `backend/app/Models/User.php`:

Add to the array returned by `casts()`:

```php
'is_admin' => 'boolean',
'banned_at' => 'datetime',
```

Add `'is_admin' => (bool) $this->is_admin` to the array returned by `avatarPayload()` (keep the existing keys):

```php
public function avatarPayload(): array
{
    return [
        'avatar_url' => $this->avatar_url,
        'level' => $this->level,
        'cosmetics' => $this->resolveEquippedCosmetics(),
        'is_admin' => (bool) $this->is_admin,
    ];
}
```

Update the `@return` docblock above `avatarPayload()` to include `is_admin: bool`.

Add this method (near the other helpers, e.g. after `resolveEquippedCosmetics()`):

```php
/** An account with a non-null banned_at cannot authenticate or use the API. */
public function isBanned(): bool
{
    return $this->banned_at !== null;
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=AvatarPayloadTest`
Expected: PASS (all methods, including the pre-existing ones).

- [ ] **Step 7: Commit**

```bash
git add backend/database/migrations backend/app/Models/User.php backend/tests/Feature/Cosmetics/AvatarPayloadTest.php
git commit -m "feat: add is_admin + ban columns, expose is_admin in avatar payload"
```

---

## Task 2: Admin guard + user list endpoint

**Files:**
- Create: `backend/app/Http/Middleware/EnsureUserIsAdmin.php`
- Create: `backend/app/Http/Controllers/Api/Admin/AdminUserController.php`
- Modify: `backend/bootstrap/app.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/AdminUserManagementTest.php`

**Interfaces:**
- Consumes: `User::isBanned()`, `User::avatarPayload()` (Task 1).
- Produces:
  - Middleware alias `admin` → `EnsureUserIsAdmin`.
  - `GET /api/admin/users?search=&page=` → `{ data: AdminUser[], meta: { current_page, last_page, total } }`, 25 per page, ordered `created_at desc`. `search` matches `name` / `username` / `email` with `LIKE %term%`.
  - `AdminUserController::toAdminArray(User $user): array` (private) → `{ id, name, username, email, email_verified: bool, provider, xp, level, is_admin: bool, banned_at: string|null, ban_reason: string|null, created_at: string, avatar: array }`.
  - Route group: `Route::middleware(['auth:sanctum', 'verified', 'admin'])->prefix('admin')->group(...)` in `routes/api.php`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Admin/AdminUserManagementTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/admin/users')->assertUnauthorized();
    }

    public function test_non_admins_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_admin_can_list_users(): void
    {
        $admin = $this->admin();
        User::factory()->count(3)->create();

        $this->actingAs($admin)->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonStructure([
                'data' => [['id', 'name', 'username', 'email', 'email_verified', 'is_admin', 'banned_at', 'avatar']],
                'meta' => ['current_page', 'last_page', 'total'],
            ]);
    }

    public function test_list_paginates_at_25_per_page(): void
    {
        $admin = $this->admin();
        User::factory()->count(25)->create(); // 26 total with the admin

        $first = $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();
        $this->assertCount(25, $first->json('data'));
        $first->assertJsonPath('meta.last_page', 2);

        $this->actingAs($admin)->getJson('/api/admin/users?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_list_search_matches_name_username_or_email(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Zebra Person', 'username' => 'zeb', 'email' => 'zeb@example.com']);
        User::factory()->create(['name' => 'Other', 'username' => 'other', 'email' => 'other@example.com']);

        $res = $this->actingAs($admin)->getJson('/api/admin/users?search=zebra')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('zeb', $res->json('data.0.username'));

        $res2 = $this->actingAs($admin)->getJson('/api/admin/users?search=other@example')->assertOk();
        $this->assertCount(1, $res2->json('data'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminUserManagementTest`
Expected: FAIL — route `/api/admin/users` not defined (404 / MethodNotAllowed).

- [ ] **Step 3: Create the middleware**

`backend/app/Http/Middleware/EnsureUserIsAdmin.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_admin === true, 403, 'Admin access required.');

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the alias**

In `backend/bootstrap/app.php`, inside the `->withMiddleware(function (Middleware $middleware): void { ... })` closure, after `$middleware->throttleApi();`, add:

```php
$middleware->alias([
    'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
]);
```

- [ ] **Step 5: Create the controller with `index` + `toAdminArray`**

`backend/app/Http/Controllers/Api/Admin/AdminUserController.php`:

```php
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
```

- [ ] **Step 6: Add the route group**

In `backend/routes/api.php`, add `use App\Http\Controllers\Api\Admin\AdminUserController;` to the imports, and add this group at the end of the file (top level, not nested in another group):

```php
Route::middleware(['auth:sanctum', 'verified', 'admin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/users', [AdminUserController::class, 'index']);
    });
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=AdminUserManagementTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Http/Middleware/EnsureUserIsAdmin.php backend/app/Http/Controllers/Api/Admin/AdminUserController.php backend/bootstrap/app.php backend/routes/api.php backend/tests/Feature/Admin/AdminUserManagementTest.php
git commit -m "feat: admin guard middleware and GET /api/admin/users"
```

---

## Task 3: Show a single user

**Files:**
- Modify: `backend/app/Http/Controllers/Api/Admin/AdminUserController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/AdminUserManagementTest.php`

**Interfaces:**
- Produces: `GET /api/admin/users/{user}` → `toAdminArray($user)` (200), 404 for an unknown id, 403 for non-admins.

- [ ] **Step 1: Write the failing test**

Add to `AdminUserManagementTest`:

```php
public function test_admin_can_view_a_single_user(): void
{
    $admin = $this->admin();
    $target = User::factory()->create(['name' => 'Target', 'xp' => 300]);

    $this->actingAs($admin)->getJson("/api/admin/users/{$target->id}")
        ->assertOk()
        ->assertJsonPath('id', $target->id)
        ->assertJsonPath('name', 'Target')
        ->assertJsonPath('level', 3)
        ->assertJsonPath('is_admin', false);
}

public function test_show_404s_for_an_unknown_user(): void
{
    $this->actingAs($this->admin())
        ->getJson('/api/admin/users/999999')
        ->assertNotFound();
}

public function test_non_admin_cannot_view_a_user(): void
{
    $target = User::factory()->create();
    $this->actingAs(User::factory()->create())
        ->getJson("/api/admin/users/{$target->id}")
        ->assertForbidden();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminUserManagementTest`
Expected: FAIL on the new methods — route not defined.

- [ ] **Step 3: Add the `show` method**

In `AdminUserController`, after `index()`:

```php
public function show(User $user)
{
    return response()->json($this->toAdminArray($user));
}
```

- [ ] **Step 4: Add the route**

In the admin group in `routes/api.php`:

```php
Route::get('/users/{user}', [AdminUserController::class, 'show']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=AdminUserManagementTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Api/Admin/AdminUserController.php backend/routes/api.php backend/tests/Feature/Admin/AdminUserManagementTest.php
git commit -m "feat: GET /api/admin/users/{user}"
```

---

## Task 4: Update a user

**Files:**
- Create: `backend/app/Http/Requests/Admin/AdminUpdateUserRequest.php`
- Modify: `backend/app/Http/Controllers/Api/Admin/AdminUserController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/AdminUserManagementTest.php`

**Interfaces:**
- Consumes: `toAdminArray()` (Task 2).
- Produces: `PATCH /api/admin/users/{user}` with body `{ name, username, email, email_verified: bool, is_admin: bool }` → `toAdminArray` (200). Toggling `email_verified` true sets `email_verified_at = now()` if currently null; false nulls it. Setting `is_admin=false` on **the acting admin's own account** → 422 with `is_admin` validation error, no change persisted. `username` / `email` uniqueness ignores the target's own current value.

- [ ] **Step 1: Write the failing test**

Add to `AdminUserManagementTest`:

```php
public function test_admin_can_update_core_fields(): void
{
    $admin = $this->admin();
    $target = User::factory()->unverified()->create(['name' => 'Old', 'username' => 'old', 'email' => 'old@example.com']);

    $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}", [
        'name' => 'New Name',
        'username' => 'newname',
        'email' => 'new@example.com',
        'email_verified' => true,
        'is_admin' => true,
    ])->assertOk()
        ->assertJsonPath('name', 'New Name')
        ->assertJsonPath('username', 'newname')
        ->assertJsonPath('email_verified', true)
        ->assertJsonPath('is_admin', true);

    $target->refresh();
    $this->assertNotNull($target->email_verified_at);
    $this->assertTrue($target->is_admin);
}

public function test_update_can_unverify_an_email(): void
{
    $admin = $this->admin();
    $target = User::factory()->create(); // verified by factory default

    $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}", [
        'name' => $target->name,
        'username' => $target->username,
        'email' => $target->email,
        'email_verified' => false,
        'is_admin' => false,
    ])->assertOk()->assertJsonPath('email_verified', false);

    $this->assertNull($target->refresh()->email_verified_at);
}

public function test_update_rejects_a_taken_username_but_allows_the_users_own(): void
{
    $admin = $this->admin();
    User::factory()->create(['username' => 'taken']);
    $target = User::factory()->create(['username' => 'mine']);

    $base = fn (array $over) => array_merge([
        'name' => $target->name, 'username' => 'mine', 'email' => $target->email,
        'email_verified' => true, 'is_admin' => false,
    ], $over);

    $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}", $base(['username' => 'taken']))
        ->assertUnprocessable()->assertJsonValidationErrors('username');

    $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}", $base([]))
        ->assertOk();
}

public function test_admin_cannot_remove_their_own_admin_access(): void
{
    $admin = $this->admin();

    $this->actingAs($admin)->patchJson("/api/admin/users/{$admin->id}", [
        'name' => $admin->name,
        'username' => $admin->username,
        'email' => $admin->email,
        'email_verified' => true,
        'is_admin' => false,
    ])->assertUnprocessable()->assertJsonValidationErrors('is_admin');

    $this->assertTrue($admin->refresh()->is_admin);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminUserManagementTest`
Expected: FAIL — `PATCH` route not defined.

- [ ] **Step 3: Create the form request**

`backend/app/Http/Requests/Admin/AdminUpdateUserRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is already behind the `admin` middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $targetId = $this->route('user')->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'min:2', 'max:24', 'alpha_dash',
                Rule::unique('users', 'username')->ignore($targetId),
            ],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($targetId),
            ],
            'email_verified' => ['required', 'boolean'],
            'is_admin' => ['required', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $target = $this->route('user');
            if ($target->is($this->user()) && $this->boolean('is_admin') === false) {
                $validator->errors()->add('is_admin', 'You cannot remove your own admin access.');
            }
        });
    }
}
```

- [ ] **Step 4: Add the `update` method**

In `AdminUserController` add the import `use App\Http\Requests\Admin\AdminUpdateUserRequest;`, then after `show()`:

```php
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
```

- [ ] **Step 5: Add the route**

In the admin group in `routes/api.php`:

```php
Route::patch('/users/{user}', [AdminUserController::class, 'update']);
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=AdminUserManagementTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Requests/Admin backend/app/Http/Controllers/Api/Admin/AdminUserController.php backend/routes/api.php backend/tests/Feature/Admin/AdminUserManagementTest.php
git commit -m "feat: PATCH /api/admin/users/{user} with self de-admin guard"
```

---

## Task 5: Delete a user

**Files:**
- Modify: `backend/app/Http/Controllers/Api/Admin/AdminUserController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/AdminUserManagementTest.php`

**Interfaces:**
- Produces: `DELETE /api/admin/users/{user}` → 204, row removed. On the acting admin's own id → 422, row kept.

- [ ] **Step 1: Write the failing test**

Add to `AdminUserManagementTest`:

```php
public function test_admin_can_delete_a_user(): void
{
    $admin = $this->admin();
    $target = User::factory()->create();

    $this->actingAs($admin)->deleteJson("/api/admin/users/{$target->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('users', ['id' => $target->id]);
}

public function test_admin_cannot_delete_their_own_account_via_admin_api(): void
{
    $admin = $this->admin();

    $this->actingAs($admin)->deleteJson("/api/admin/users/{$admin->id}")
        ->assertStatus(422);

    $this->assertDatabaseHas('users', ['id' => $admin->id]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminUserManagementTest`
Expected: FAIL — `DELETE` route not defined.

- [ ] **Step 3: Add the `destroy` method**

In `AdminUserController`, after `update()`:

```php
public function destroy(Request $request, User $user)
{
    abort_if($user->is($request->user()), 422, 'You cannot delete your own account here.');

    $user->delete();

    return response()->noContent();
}
```

- [ ] **Step 4: Add the route**

In the admin group in `routes/api.php`:

```php
Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=AdminUserManagementTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Api/Admin/AdminUserController.php backend/routes/api.php backend/tests/Feature/Admin/AdminUserManagementTest.php
git commit -m "feat: DELETE /api/admin/users/{user} with self-delete guard"
```

---

## Task 6: Ban / unban

**Files:**
- Modify: `backend/app/Http/Controllers/Api/Admin/AdminUserController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/AdminUserManagementTest.php`

**Interfaces:**
- Consumes: `toAdminArray()`.
- Produces:
  - `POST /api/admin/users/{user}/ban` body `{ reason?: string, max 255 }` → sets `banned_at = now()`, `ban_reason = reason`, deletes the user's `sessions` rows and Sanctum tokens; returns `toAdminArray` (200). Self → 422.
  - `POST /api/admin/users/{user}/unban` → nulls `banned_at` + `ban_reason`; returns `toAdminArray` (200).

- [ ] **Step 1: Write the failing test**

Add to `AdminUserManagementTest`:

```php
public function test_admin_can_ban_and_unban_a_user(): void
{
    $admin = $this->admin();
    $target = User::factory()->create();
    \DB::table('sessions')->insert([
        'id' => 'sess-1', 'user_id' => $target->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time(),
    ]);

    $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/ban", ['reason' => 'Spam'])
        ->assertOk()
        ->assertJsonPath('ban_reason', 'Spam')
        ->assertJson(fn ($json) => $json->where('banned_at', fn ($v) => $v !== null)->etc());

    $this->assertNotNull($target->refresh()->banned_at);
    $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);

    $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/unban")
        ->assertOk()
        ->assertJsonPath('banned_at', null)
        ->assertJsonPath('ban_reason', null);

    $this->assertNull($target->refresh()->banned_at);
}

public function test_ban_reason_is_optional(): void
{
    $admin = $this->admin();
    $target = User::factory()->create();

    $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/ban")
        ->assertOk()
        ->assertJsonPath('ban_reason', null);
}

public function test_admin_cannot_ban_themselves(): void
{
    $admin = $this->admin();

    $this->actingAs($admin)->postJson("/api/admin/users/{$admin->id}/ban")
        ->assertStatus(422);

    $this->assertNull($admin->refresh()->banned_at);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminUserManagementTest`
Expected: FAIL — ban routes not defined.

- [ ] **Step 3: Add `ban` + `unban`**

In `AdminUserController` add `use Illuminate\Support\Facades\DB;`, then after `destroy()`:

```php
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
```

(`forceFill` is used because `banned_at` / `ban_reason` are not in `#[Fillable]`.)

- [ ] **Step 4: Add the routes**

In the admin group in `routes/api.php`:

```php
Route::post('/users/{user}/ban', [AdminUserController::class, 'ban']);
Route::post('/users/{user}/unban', [AdminUserController::class, 'unban']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=AdminUserManagementTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Api/Admin/AdminUserController.php backend/routes/api.php backend/tests/Feature/Admin/AdminUserManagementTest.php
git commit -m "feat: ban/unban endpoints with session + token purge"
```

---

## Task 7: Reset XP

**Files:**
- Modify: `backend/app/Http/Controllers/Api/Admin/AdminUserController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/AdminUserManagementTest.php`

**Interfaces:**
- Produces: `POST /api/admin/users/{user}/reset-xp` → sets `xp = 0`, deletes the user's `season_progress` rows; returns `toAdminArray` (200). Leaves `xp_events` untouched.

- [ ] **Step 1: Write the failing test**

Add to `AdminUserManagementTest` (add `use App\Models\Season;` and `use App\Models\SeasonProgress;` to the test's imports):

```php
public function test_admin_can_reset_a_users_xp(): void
{
    $admin = $this->admin();
    $target = User::factory()->create(['xp' => 5000]);
    $season = Season::factory()->create();
    SeasonProgress::create(['season_id' => $season->id, 'user_id' => $target->id, 'xp' => 4200]);

    $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/reset-xp")
        ->assertOk()
        ->assertJsonPath('xp', 0);

    $this->assertSame(0, (int) $target->refresh()->xp);
    $this->assertDatabaseMissing('season_progress', ['user_id' => $target->id]);
}
```

> If `Season::factory()` does not exist, create the season row directly with the columns the `seasons` migration defines (check `backend/database/migrations/2026_09_03_000001_create_seasons_table.php`) instead of the factory.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminUserManagementTest`
Expected: FAIL — `reset-xp` route not defined.

- [ ] **Step 3: Add the `resetXp` method**

In `AdminUserController`, after `unban()`:

```php
public function resetXp(User $user)
{
    $user->forceFill(['xp' => 0])->save();
    $user->seasonProgress()->delete();

    return response()->json($this->toAdminArray($user));
}
```

- [ ] **Step 4: Add the route**

In the admin group in `routes/api.php`:

```php
Route::post('/users/{user}/reset-xp', [AdminUserController::class, 'resetXp']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=AdminUserManagementTest`
Expected: PASS. Also run the fuller `php artisan test --filter=Admin` to confirm nothing else regressed.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Api/Admin/AdminUserController.php backend/routes/api.php backend/tests/Feature/Admin/AdminUserManagementTest.php
git commit -m "feat: reset-xp endpoint"
```

---

## Task 8: Enforce bans (middleware + login)

**Files:**
- Create: `backend/app/Http/Middleware/EnsureUserNotBanned.php`
- Modify: `backend/bootstrap/app.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Http/Requests/Auth/LoginRequest.php`
- Test: `backend/tests/Feature/Admin/BannedUserTest.php`

**Interfaces:**
- Consumes: `User::isBanned()` (Task 1).
- Produces:
  - Middleware alias `not-banned` → `EnsureUserNotBanned`. When the authenticated user is banned: logs the `web` guard out, invalidates the session, returns 403 `{ message: 'Your account has been suspended.', reason: string|null }`.
  - Applied to the existing `Route::middleware('auth:sanctum')->group(...)` block in `routes/api.php` (change to `Route::middleware(['auth:sanctum', 'not-banned'])`).
  - `LoginRequest::authenticate()` throws a `ValidationException` on `email` (message = ban reason, or `'This account has been suspended.'`) when the authenticated user is banned, and logs them back out.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Admin/BannedUserTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BannedUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_banned_user_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'ban@example.com',
            'password' => Hash::make('secret123'),
            'banned_at' => now(),
            'ban_reason' => 'Cheating',
        ]);

        $this->postJson('/api/login', ['email' => 'ban@example.com', 'password' => 'secret123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_a_banned_users_live_session_is_rejected_on_the_next_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/user')->assertOk();

        $user->update(['banned_at' => now(), 'ban_reason' => 'Spam']);

        $this->actingAs($user)->getJson('/api/user')
            ->assertForbidden()
            ->assertJsonPath('reason', 'Spam');
    }

    public function test_a_banned_user_can_still_log_out(): void
    {
        $user = User::factory()->create(['banned_at' => now()]);

        $this->actingAs($user)->postJson('/api/logout')->assertNoContent();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BannedUserTest`
Expected: FAIL — bans not enforced (login succeeds, `/api/user` returns 200).

- [ ] **Step 3: Create the middleware**

`backend/app/Http/Middleware/EnsureUserNotBanned.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->isBanned()) {
            $reason = $user->ban_reason;

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Your account has been suspended.',
                'reason' => $reason,
            ], 403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the alias**

In `backend/bootstrap/app.php`, extend the `$middleware->alias([...])` array added in Task 2:

```php
$middleware->alias([
    'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
    'not-banned' => \App\Http\Middleware\EnsureUserNotBanned::class,
]);
```

- [ ] **Step 5: Apply the middleware to the authed group**

In `backend/routes/api.php`, find `Route::middleware('auth:sanctum')->group(function () {` (the large block that contains `/logout`, `/user`, `/ping`, email verification, and the nested `verified` group) and change it to:

```php
Route::middleware(['auth:sanctum', 'not-banned'])->group(function () {
```

Leave the OAuth routes, the `auth:player` groups, and the `auth:player,sanctum` leave route unchanged. The new `/api/admin/*` group also gets `not-banned` — add it there too:

```php
Route::middleware(['auth:sanctum', 'not-banned', 'verified', 'admin'])
    ->prefix('admin')
    ->group(function () { /* ... */ });
```

- [ ] **Step 6: Block banned users at login**

In `backend/app/Http/Requests/Auth/LoginRequest.php`, add `use Illuminate\Support\Facades\Auth;` (already imported) and update `authenticate()` — after the successful-attempt block that calls `RateLimiter::clear(...)`, append:

```php
if (Auth::user()->isBanned()) {
    $reason = Auth::user()->ban_reason ?: 'This account has been suspended.';

    Auth::guard('web')->logout();

    throw ValidationException::withMessages([
        'email' => [$reason],
    ]);
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=BannedUserTest`
Then the whole admin suite: `php artisan test --filter=Admin`
Then the auth suite to check for regressions: `php artisan test --filter=Auth`
Expected: all PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Http/Middleware/EnsureUserNotBanned.php backend/bootstrap/app.php backend/routes/api.php backend/app/Http/Requests/Auth/LoginRequest.php backend/tests/Feature/Admin/BannedUserTest.php
git commit -m "feat: enforce bans at login and on live sessions"
```

- [ ] **Step 9: Run the full backend suite**

Run: `php artisan test`
Expected: green. If anything unrelated breaks, investigate before moving to the frontend.

---

## Task 9: Frontend types + admin badge on `<Avatar>`

**Files:**
- Modify: `frontend/src/lib/avatarData.ts`
- Modify: `frontend/src/stores/authStore.ts`
- Modify: `frontend/src/components/ui/Avatar.tsx`
- Modify: `frontend/src/styles/avatar.css`

**Interfaces:**
- Consumes: `avatar.is_admin` from the API payload (Task 1).
- Produces:
  - `AvatarData.is_admin?: boolean`; `EMPTY_AVATAR.is_admin = false`.
  - `Host.is_admin: boolean`.
  - `<Avatar>` renders `<span class="avatar-admin-badge">` with a lucide `ShieldCheck` icon when `data.is_admin` is true.

- [ ] **Step 1: Extend `AvatarData`**

In `frontend/src/lib/avatarData.ts`:

```ts
export interface AvatarData {
  avatar_url: string | null;
  level: number;
  cosmetics: Partial<Record<CosmeticSlot, EquippedCosmetic>>;
  is_admin?: boolean;
}

/** A safe empty payload for the brief window before `host` has loaded. */
export const EMPTY_AVATAR: AvatarData = { avatar_url: null, level: 1, cosmetics: {}, is_admin: false };
```

- [ ] **Step 2: Extend `Host`**

In `frontend/src/stores/authStore.ts`, add to the `Host` interface (after `provider`):

```ts
  /** Grants the /admin area and the public admin badge. */
  is_admin: boolean;
```

- [ ] **Step 3: Render the badge in `<Avatar>`**

In `frontend/src/components/ui/Avatar.tsx`:

- Add the import: `import { ShieldCheck } from "lucide-react";`
- After the last `<CosmeticLayer .../>` line and before the closing `</span>` of the outer `<span className={boxClass}>`, add:

```tsx
      {data.is_admin && (
        <span className="avatar-admin-badge" aria-label="Admin" title="Admin">
          <ShieldCheck className="avatar-admin-badge-icon" strokeWidth={2.5} />
        </span>
      )}
```

- [ ] **Step 4: Style the badge**

Append to `frontend/src/styles/avatar.css`:

```css
/* Public "this user is an admin" marker, bottom-right of the avatar box.
   Shown on every surface a user appears, to every visitor. */
.avatar-admin-badge {
  position: absolute;
  right: -2px;
  bottom: -2px;
  display: grid;
  place-items: center;
  width: 45%;
  height: 45%;
  border-radius: 999px;
  background: var(--color-grape, #7c3aed);
  color: #fff;
  box-shadow: 0 0 0 2px var(--color-surface, #fff);
  z-index: 40;
}

.avatar-admin-badge-icon {
  width: 70%;
  height: 70%;
}

.avatar-xs .avatar-admin-badge {
  box-shadow: 0 0 0 1.5px var(--color-surface, #fff);
}
```

> Check the top of `frontend/src/styles/avatar.css` for the actual CSS custom-property names in use (e.g. `--color-grape`, `--surface`, etc.) and match them. If `.avatar` is not already `position: relative`, add `position: relative;` to the `.avatar` rule so the badge anchors correctly.

- [ ] **Step 5: Typecheck + lint**

Run: `cd frontend && npm run build`
Expected: no TypeScript errors.
Run: `cd frontend && npm run lint`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/lib/avatarData.ts frontend/src/stores/authStore.ts frontend/src/components/ui/Avatar.tsx frontend/src/styles/avatar.css
git commit -m "feat: admin badge on Avatar; is_admin on Host and AvatarData"
```

---

## Task 10: `adminStore`

**Files:**
- Create: `frontend/src/stores/adminStore.ts`

**Interfaces:**
- Consumes: `api` from `frontend/src/lib/api.ts`; the `/api/admin/*` endpoints (Tasks 2–7).
- Produces: `useAdminStore` with:
  - state: `users: AdminUser[]`, `meta: { current_page: number; last_page: number; total: number } | null`, `search: string`, `page: number`, `status: "idle" | "loading" | "ready"`, `selected: AdminUser | null`, `selectedStatus: "idle" | "loading" | "ready"`.
  - `AdminUser` type: `{ id: number; name: string; username: string | null; email: string; email_verified: boolean; provider: string | null; xp: number; level: number; is_admin: boolean; banned_at: string | null; ban_reason: string | null; created_at: string | null; avatar: AvatarData }`.
  - actions: `fetchUsers(): Promise<void>` (uses current `search` + `page`), `setSearch(term: string): void` (resets `page` to 1, then `fetchUsers`), `setPage(page: number): void` (then `fetchUsers`), `fetchUser(id: number): Promise<void>`, `updateUser(id, payload): Promise<AdminUser>`, `deleteUser(id): Promise<void>`, `banUser(id, reason: string): Promise<AdminUser>`, `unbanUser(id): Promise<AdminUser>`, `resetXp(id): Promise<AdminUser>`.
  - `updatePayload` type for `updateUser`: `{ name: string; username: string; email: string; email_verified: boolean; is_admin: boolean }`.

- [ ] **Step 1: Write the store**

Create `frontend/src/stores/adminStore.ts`:

```ts
import { create } from "zustand";

import { api } from "../lib/api";
import type { AvatarData } from "../lib/avatarData";

export interface AdminUser {
  id: number;
  name: string;
  username: string | null;
  email: string;
  email_verified: boolean;
  provider: string | null;
  xp: number;
  level: number;
  is_admin: boolean;
  banned_at: string | null;
  ban_reason: string | null;
  created_at: string | null;
  avatar: AvatarData;
}

export interface AdminUserUpdate {
  name: string;
  username: string;
  email: string;
  email_verified: boolean;
  is_admin: boolean;
}

interface ListMeta {
  current_page: number;
  last_page: number;
  total: number;
}

interface AdminState {
  users: AdminUser[];
  meta: ListMeta | null;
  search: string;
  page: number;
  status: "idle" | "loading" | "ready";
  selected: AdminUser | null;
  selectedStatus: "idle" | "loading" | "ready";
  fetchUsers: () => Promise<void>;
  setSearch: (term: string) => void;
  setPage: (page: number) => void;
  fetchUser: (id: number) => Promise<void>;
  updateUser: (id: number, payload: AdminUserUpdate) => Promise<AdminUser>;
  deleteUser: (id: number) => Promise<void>;
  banUser: (id: number, reason: string) => Promise<AdminUser>;
  unbanUser: (id: number) => Promise<AdminUser>;
  resetXp: (id: number) => Promise<AdminUser>;
}

interface ListResponse {
  data: AdminUser[];
  meta: ListMeta;
}

export const useAdminStore = create<AdminState>((set, get) => ({
  users: [],
  meta: null,
  search: "",
  page: 1,
  status: "idle",
  selected: null,
  selectedStatus: "idle",

  fetchUsers: async () => {
    set({ status: "loading" });
    const { search, page } = get();
    const response = await api.get<ListResponse>("/api/admin/users", {
      params: { search: search || undefined, page },
    });
    set({ users: response.data.data, meta: response.data.meta, status: "ready" });
  },

  setSearch: (term) => {
    set({ search: term, page: 1 });
    void get().fetchUsers();
  },

  setPage: (page) => {
    set({ page });
    void get().fetchUsers();
  },

  fetchUser: async (id) => {
    set({ selectedStatus: "loading", selected: null });
    const response = await api.get<AdminUser>(`/api/admin/users/${id}`);
    set({ selected: response.data, selectedStatus: "ready" });
  },

  updateUser: async (id, payload) => {
    const response = await api.patch<AdminUser>(`/api/admin/users/${id}`, payload);
    set((state) => ({
      selected: response.data,
      users: state.users.map((u) => (u.id === id ? response.data : u)),
    }));
    return response.data;
  },

  deleteUser: async (id) => {
    await api.delete(`/api/admin/users/${id}`);
    set((state) => ({ users: state.users.filter((u) => u.id !== id) }));
  },

  banUser: async (id, reason) => {
    const response = await api.post<AdminUser>(`/api/admin/users/${id}/ban`, {
      reason: reason || undefined,
    });
    set((state) => ({
      selected: response.data,
      users: state.users.map((u) => (u.id === id ? response.data : u)),
    }));
    return response.data;
  },

  unbanUser: async (id) => {
    const response = await api.post<AdminUser>(`/api/admin/users/${id}/unban`);
    set((state) => ({
      selected: response.data,
      users: state.users.map((u) => (u.id === id ? response.data : u)),
    }));
    return response.data;
  },

  resetXp: async (id) => {
    const response = await api.post<AdminUser>(`/api/admin/users/${id}/reset-xp`);
    set((state) => ({
      selected: response.data,
      users: state.users.map((u) => (u.id === id ? response.data : u)),
    }));
    return response.data;
  },
}));
```

- [ ] **Step 2: Typecheck + lint**

Run: `cd frontend && npm run build`
Run: `cd frontend && npm run lint`
Expected: clean (the store is unused for now — that's fine, it's imported next task; if oxlint flags an unused export, ignore, it is consumed in Task 12).

- [ ] **Step 3: Commit**

```bash
git add frontend/src/stores/adminStore.ts
git commit -m "feat: adminStore for user management"
```

---

## Task 11: `RequireAdmin` guard, routes, and nav link

**Files:**
- Create: `frontend/src/components/RequireAdmin.tsx`
- Modify: `frontend/src/App.tsx`
- Modify: `frontend/src/pages/HomePage.tsx`

**Interfaces:**
- Consumes: `useAuthStore` (`host`, `status`, `fetchHost`); `Host.is_admin` (Task 9).
- Produces:
  - `RequireAdmin` component (default-exported named export `RequireAdmin`, same shape as `RequireHost`): shows `Loading…` until `status === "ready"`; `<Navigate to="/login">` if no host; `<Navigate to="/verify-email">` if `!host.email_verified`; `<Navigate to="/">` if `!host.is_admin`; otherwise renders children.
  - Routes `"/admin"` → `AdminUsersPage` and `"/admin/users/:id"` → `AdminUserDetailPage`, each wrapped in `<RequireAdmin>`.
  - An admin-only `<Link to="/admin">` in `HomePage`'s `<nav>`.

- [ ] **Step 1: Create `RequireAdmin`**

Create `frontend/src/components/RequireAdmin.tsx` (mirrors `RequireHost.tsx`):

```tsx
import { type PropsWithChildren, useEffect } from "react";
import { Navigate } from "react-router-dom";

import { useAuthStore } from "../stores/authStore";

export function RequireAdmin({ children }: PropsWithChildren) {
  const { host, status, fetchHost } = useAuthStore();

  useEffect(() => {
    if (status === "idle") {
      void fetchHost();
    }
  }, [status, fetchHost]);

  if (status !== "ready") {
    return <p>Loading…</p>;
  }

  if (!host) {
    return <Navigate to="/login" replace />;
  }

  if (!host.email_verified) {
    return <Navigate to="/verify-email" replace />;
  }

  if (!host.is_admin) {
    return <Navigate to="/" replace />;
  }

  return <>{children}</>;
}
```

- [ ] **Step 2: Wire the routes**

In `frontend/src/App.tsx`:

- Add imports:

```tsx
import { RequireAdmin } from "./components/RequireAdmin";
import { AdminUsersPage } from "./pages/AdminUsersPage";
import { AdminUserDetailPage } from "./pages/AdminUserDetailPage";
```

- Add these routes inside `<Routes>` (next to the other `RequireHost`-wrapped routes):

```tsx
        <Route
          path="/admin"
          element={
            <RequireAdmin>
              <AdminUsersPage />
            </RequireAdmin>
          }
        />
        <Route
          path="/admin/users/:id"
          element={
            <RequireAdmin>
              <AdminUserDetailPage />
            </RequireAdmin>
          }
        />
```

> `AdminUsersPage` / `AdminUserDetailPage` don't exist yet — they're built in Tasks 12–13. The build will fail until then; that's expected. Do Steps 1–2 here, then continue to Task 12, and run the typecheck at the end of Task 13.

- [ ] **Step 3: Add the nav link**

In `frontend/src/pages/HomePage.tsx`:

- Add `ShieldCheck` to the lucide import: `import { Hammer, ShieldCheck, Trophy, UserRound, Users2 } from "lucide-react";`
- In the `<nav>`, after the `<Link to="/friends">…</Link>` block, add:

```tsx
        {host?.is_admin && (
          <Link to="/admin">
            <ShieldCheck size={16} strokeWidth={2.25} />
            Admin
          </Link>
        )}
```

- [ ] **Step 4: Commit** (typecheck deferred to Task 13)

```bash
git add frontend/src/components/RequireAdmin.tsx frontend/src/App.tsx frontend/src/pages/HomePage.tsx
git commit -m "feat: RequireAdmin guard, /admin routes, admin nav link"
```

---

## Task 12: `AdminUsersPage` (list + search + pagination)

**Files:**
- Create: `frontend/src/pages/AdminUsersPage.tsx`

**Interfaces:**
- Consumes: `useAdminStore` (`users`, `meta`, `search`, `page`, `status`, `fetchUsers`, `setSearch`, `setPage`); `<Avatar>`, `<Badge>`, `EMPTY_AVATAR`; `react-router` `Link`.
- Produces: `AdminUsersPage` named export — a page at `/admin` listing users with a search box (debounced ~300ms into `setSearch`), rows linking to `/admin/users/:id`, and prev/next pagination bound to `meta`.

- [ ] **Step 1: Build the page**

Create `frontend/src/pages/AdminUsersPage.tsx`:

```tsx
import { useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";

import { EMPTY_AVATAR } from "../lib/avatarData";
import { useAdminStore } from "../stores/adminStore";
import { Avatar } from "../components/ui/Avatar";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";

export function AdminUsersPage() {
  const { users, meta, status, search, page, fetchUsers, setSearch, setPage } = useAdminStore();
  const [term, setTerm] = useState(search);
  const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    void fetchUsers();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function onTermChange(value: string) {
    setTerm(value);
    if (debounce.current) clearTimeout(debounce.current);
    debounce.current = setTimeout(() => setSearch(value.trim()), 300);
  }

  return (
    <div className="admin-page">
      <p>
        <Link to="/">← Home</Link>
      </p>
      <h1>Users</h1>

      <input
        type="search"
        placeholder="Search name, username, or email"
        value={term}
        onChange={(e) => onTermChange(e.target.value)}
        className="admin-search"
      />

      {status !== "ready" && users.length === 0 ? (
        <p className="hint">Loading…</p>
      ) : users.length === 0 ? (
        <p className="hint">No users match “{search}”.</p>
      ) : (
        <ul className="player-list admin-user-list">
          {users.map((user) => (
            <li key={user.id}>
              <Link to={`/admin/users/${user.id}`} className="admin-user-row">
                <Avatar data={user.avatar ?? EMPTY_AVATAR} size="sm" animated={false} />
                <span className="admin-user-identity">
                  <strong>{user.username ?? user.name}</strong>
                  <span className="hint">{user.email}</span>
                </span>
                <span className="admin-user-tags">
                  {user.is_admin && <Badge tone="grape">Admin</Badge>}
                  {user.banned_at && <Badge tone="coral">Banned</Badge>}
                  {!user.email_verified && <Badge tone="sunflower">Unverified</Badge>}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}

      {meta && meta.last_page > 1 && (
        <div className="admin-pagination">
          <Button variant="ghost" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            Previous
          </Button>
          <span className="hint">
            Page {meta.current_page} of {meta.last_page} · {meta.total} users
          </span>
          <Button
            variant="ghost"
            disabled={page >= meta.last_page}
            onClick={() => setPage(page + 1)}
          >
            Next
          </Button>
        </div>
      )}
    </div>
  );
}
```

> If oxlint has no `react-hooks/exhaustive-deps` rule, remove the disable comment. Match whatever the other pages do about mount-effects (`LeaderboardPage` just lists `[fetch]`).

- [ ] **Step 2: Add minimal styles**

Append to `frontend/src/styles/app.css` (or the stylesheet that holds `.leaderboard-page` / `.player-list` — grep for `.player-list` to confirm):

```css
.admin-search {
  width: 100%;
  margin: 0.75rem 0 1rem;
  padding: 0.55rem 0.75rem;
  border-radius: 0.6rem;
  border: 1px solid var(--color-border, #d4d4d8);
  font: inherit;
}

.admin-user-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  width: 100%;
  text-decoration: none;
  color: inherit;
}

.admin-user-identity {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.admin-user-tags {
  margin-left: auto;
  display: flex;
  gap: 0.35rem;
  flex-shrink: 0;
}

.admin-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-top: 1rem;
}
```

- [ ] **Step 3: Commit** (typecheck at end of Task 13)

```bash
git add frontend/src/pages/AdminUsersPage.tsx frontend/src/styles/app.css
git commit -m "feat: AdminUsersPage list with search and pagination"
```

---

## Task 13: `AdminUserDetailPage` (edit + moderation)

**Files:**
- Create: `frontend/src/pages/AdminUserDetailPage.tsx`

**Interfaces:**
- Consumes: `useAdminStore` (`selected`, `selectedStatus`, `fetchUser`, `updateUser`, `deleteUser`, `banUser`, `unbanUser`, `resetXp`); `useAuthStore` (`host`); `firstValidationError` from `../lib/errors`; `react-router` `useParams` / `useNavigate` / `Link`.
- Produces: `AdminUserDetailPage` named export — page at `/admin/users/:id` with an edit form (name, username, email, email-verified checkbox, is-admin checkbox) and a moderation section (ban with reason / unban, reset XP, delete). Actions on the acting admin's own account (`selected.id === host.id`) have the is-admin checkbox, ban, and delete controls disabled. Destructive actions use a two-step inline confirm (a second click), never `window.confirm`.

- [ ] **Step 1: Build the page**

Create `frontend/src/pages/AdminUserDetailPage.tsx`:

```tsx
import { useEffect, useState, type FormEvent } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";

import { firstValidationError } from "../lib/errors";
import { useAdminStore } from "../stores/adminStore";
import { useAuthStore } from "../stores/authStore";
import { Button } from "../components/ui/Button";

export function AdminUserDetailPage() {
  const { id } = useParams();
  const userId = Number(id);
  const navigate = useNavigate();

  const host = useAuthStore((state) => state.host);
  const { selected, selectedStatus, fetchUser, updateUser, deleteUser, banUser, unbanUser, resetXp } =
    useAdminStore();

  const [name, setName] = useState("");
  const [username, setUsername] = useState("");
  const [email, setEmail] = useState("");
  const [emailVerified, setEmailVerified] = useState(false);
  const [isAdmin, setIsAdmin] = useState(false);

  const [banReason, setBanReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirm, setConfirm] = useState<"delete" | "resetXp" | null>(null);

  useEffect(() => {
    if (Number.isFinite(userId)) void fetchUser(userId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [userId]);

  useEffect(() => {
    if (!selected) return;
    setName(selected.name);
    setUsername(selected.username ?? "");
    setEmail(selected.email);
    setEmailVerified(selected.email_verified);
    setIsAdmin(selected.is_admin);
  }, [selected]);

  if (selectedStatus !== "ready" || !selected) {
    return (
      <div className="admin-page">
        <p>
          <Link to="/admin">← Users</Link>
        </p>
        <p className="hint">Loading…</p>
      </div>
    );
  }

  const isSelf = host?.id === selected.id;

  async function run<T>(fn: () => Promise<T>, ok: string) {
    setError(null);
    setNotice(null);
    setBusy(true);
    try {
      await fn();
      setNotice(ok);
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  }

  function onSave(e: FormEvent) {
    e.preventDefault();
    void run(
      () => updateUser(selected!.id, { name, username, email, email_verified: emailVerified, is_admin: isAdmin }),
      "Saved.",
    );
  }

  return (
    <div className="admin-page">
      <p>
        <Link to="/admin">← Users</Link>
      </p>
      <h1>{selected.username ?? selected.name}</h1>
      <p className="hint">
        Joined {selected.created_at?.slice(0, 10) ?? "—"} · Level {selected.level} · {selected.xp} XP
        {selected.provider ? ` · ${selected.provider} account` : ""}
      </p>

      {error && <p className="form-error">{error}</p>}
      {notice && <p className="hint">{notice}</p>}

      <form onSubmit={onSave} className="admin-form">
        <label>
          Name
          <input value={name} onChange={(e) => setName(e.target.value)} required />
        </label>
        <label>
          Username
          <input value={username} onChange={(e) => setUsername(e.target.value)} required />
        </label>
        <label>
          Email
          <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </label>
        <label className="admin-check">
          <input
            type="checkbox"
            checked={emailVerified}
            onChange={(e) => setEmailVerified(e.target.checked)}
          />
          Email verified
        </label>
        <label className="admin-check">
          <input
            type="checkbox"
            checked={isAdmin}
            disabled={isSelf}
            onChange={(e) => setIsAdmin(e.target.checked)}
          />
          Admin{isSelf ? " (can’t change your own)" : ""}
        </label>
        <Button type="submit" disabled={busy}>
          Save changes
        </Button>
      </form>

      <section className="admin-moderation">
        <h2>Moderation</h2>

        {selected.banned_at ? (
          <div>
            <p className="hint">
              Banned{selected.ban_reason ? ` — ${selected.ban_reason}` : ""}.
            </p>
            <Button variant="ghost" disabled={busy} onClick={() => void run(() => unbanUser(selected.id), "Unbanned.")}>
              Unban
            </Button>
          </div>
        ) : (
          <div className="admin-ban-box">
            <input
              placeholder="Reason (optional)"
              value={banReason}
              onChange={(e) => setBanReason(e.target.value)}
              disabled={isSelf}
            />
            <Button
              variant="danger"
              disabled={busy || isSelf}
              onClick={() => void run(() => banUser(selected.id, banReason), "Banned.")}
            >
              {isSelf ? "Can’t ban yourself" : "Ban user"}
            </Button>
          </div>
        )}

        <div>
          <Button
            variant="ghost"
            disabled={busy}
            onClick={() =>
              confirm === "resetXp"
                ? void run(() => resetXp(selected.id), "XP reset.")
                : setConfirm("resetXp")
            }
          >
            {confirm === "resetXp" ? "Click again to confirm reset" : "Reset XP"}
          </Button>
        </div>

        <div>
          <Button
            variant="danger"
            disabled={busy || isSelf}
            onClick={() => {
              if (isSelf) return;
              if (confirm === "delete") {
                void run(async () => {
                  await deleteUser(selected.id);
                  navigate("/admin");
                }, "Deleted.");
              } else {
                setConfirm("delete");
              }
            }}
          >
            {isSelf
              ? "Can’t delete yourself"
              : confirm === "delete"
                ? "Click again to permanently delete"
                : "Delete user"}
          </Button>
        </div>
      </section>
    </div>
  );
}
```

> `.form-error` is the class other auth/profile forms use for errors — grep `form-error` to confirm; if the repo uses a different class, match it.

- [ ] **Step 2: Add minimal styles**

Append to the same stylesheet used in Task 12 Step 2:

```css
.admin-form {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  max-width: 28rem;
  margin: 1rem 0 2rem;
}

.admin-form label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-weight: 600;
}

.admin-form input[type="text"],
.admin-form input:not([type]),
.admin-form input[type="email"] {
  padding: 0.5rem 0.7rem;
  border-radius: 0.5rem;
  border: 1px solid var(--color-border, #d4d4d8);
  font: inherit;
}

.admin-check {
  flex-direction: row !important;
  align-items: center;
  gap: 0.5rem !important;
}

.admin-moderation {
  border-top: 1px solid var(--color-border, #d4d4d8);
  padding-top: 1rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 28rem;
}

.admin-ban-box {
  display: flex;
  gap: 0.5rem;
}

.admin-ban-box input {
  flex: 1;
  padding: 0.5rem 0.7rem;
  border-radius: 0.5rem;
  border: 1px solid var(--color-border, #d4d4d8);
  font: inherit;
}
```

- [ ] **Step 3: Typecheck + lint the whole frontend**

Run: `cd frontend && npm run build`
Expected: no TypeScript errors (Tasks 11–13 now resolve — routes, pages, store all exist).
Run: `cd frontend && npm run lint`
Expected: clean. Fix any oxlint findings (unused imports, etc.).

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/AdminUserDetailPage.tsx frontend/src/styles/app.css
git commit -m "feat: AdminUserDetailPage with edit form and moderation actions"
```

---

## Task 14: Manual end-to-end smoke + docs

**Files:**
- Modify: `backend/.env.example` / project `README` only if a note is warranted (optional).

**Interfaces:** none — this is a verification task.

- [ ] **Step 1: Run both full suites**

- `cd backend && php artisan test` — all green.
- `cd frontend && npm run build && npm run lint` — clean.

- [ ] **Step 2: Migrate a local DB and seed an admin**

```bash
cd backend && php artisan migrate
```

If your local `users` table has no `diamondpickminer@gmail.com` row, promote whatever account you log in with:
`php artisan tinker --execute="App\Models\User::where('email','you@example.com')->update(['is_admin'=>true])"`

- [ ] **Step 3: Manual smoke (document results in the PR description)**

Start the app (`composer dev` in `backend/`, `npm run dev` in `frontend/`) and verify:

1. As the admin account, the home page shows an **Admin** nav link; a non-admin account does not.
2. `/admin` lists users; searching narrows the list; pagination appears with >25 users.
3. Open a user → change their name and username → Save → the list row reflects it.
4. Toggle **Email verified** off then on → saved state persists on reload.
5. Promote a second test account to admin from the detail page → the leaderboard / friends / room player list now shows the purple shield badge on that user for **all** viewers (check while logged in as a different, non-admin account).
6. Ban the second account with a reason → it is logged out immediately; logging back in shows the ban reason; it cannot reach any `/api/*` route.
7. Unban it → it can log in again.
8. Reset XP on a test account with XP → its XP shows 0 and it drops off the leaderboard.
9. On your **own** admin detail page: the Admin checkbox, Ban, and Delete controls are disabled.
10. Delete a throwaway test account → it disappears from the list and cannot log in.

- [ ] **Step 4: Final commit (if any doc notes were added)**

```bash
git add -A
git commit -m "docs: note admin bootstrap in setup instructions"
```

If no doc changes were needed, skip this step.

---

## Self-Review

**Spec coverage:**

| Spec section | Task(s) |
| --- | --- |
| `is_admin` / `banned_at` / `ban_reason` migration | Task 1 |
| Promote `diamondpickminer@gmail.com` migration | Task 1 |
| `User` casts, `isBanned()`, `avatarPayload()` gains `is_admin` | Task 1 |
| `EnsureUserIsAdmin` middleware + `admin` alias | Task 2 |
| `EnsureUserNotBanned` middleware + `not-banned` alias, wired to authed group | Task 8 |
| Ban blocks login (`LoginRequest`) | Task 8 |
| `AdminUserController` index (search + paginate) + `toAdminArray` | Task 2 |
| `show` | Task 3 |
| `update` (+ `AdminUpdateUserRequest`, email_verified toggle, self de-admin guard) | Task 4 |
| `destroy` (+ self guard) | Task 5 |
| `ban` / `unban` (+ session/token purge, self guard) | Task 6 |
| `resetXp` (xp=0 + season_progress delete) | Task 7 |
| `/api/user` carries `is_admin` automatically | Task 1 (no code — model cast + un-hidden) |
| `Host.is_admin`, `AvatarData.is_admin` | Task 9 |
| `<Avatar>` badge + `.avatar-admin-badge` CSS | Task 9 |
| `RequireAdmin` guard | Task 11 |
| `/admin` + `/admin/users/:id` routes | Task 11 |
| `adminStore` | Task 10 |
| Admin-only nav entry | Task 11 |
| `AdminUsersPage` (list, search, pagination) | Task 12 |
| `AdminUserDetailPage` (edit form, moderation, self-protection, no `window.confirm`) | Task 13 |
| Backend feature tests (middleware 403, CRUD, moderation, self-protection, ban-blocks-login, payload) | Tasks 1–8 |
| Frontend verification (no test harness → typecheck + lint + manual smoke) | Tasks 9–14 (per Global Constraints) |
| Rollout: migrate, no env/deps changes | Task 14 |

**Deviations from the spec (approved by the user during planning):**
- The spec's "Frontend tests" subsection (RequireAdmin / adminStore / Avatar-badge unit tests) is **not** implemented — the repo has no frontend test runner and the user chose to match that. Coverage is typecheck + oxlint + the Task 14 manual smoke script instead.

**Placeholder scan:** No `TBD`/`TODO`/"add error handling"-style placeholders. Every code step contains full code. The `> If ...` notes point at concrete grep checks for repo-specific names (CSS custom properties, error class names, oxlint rules), not deferred work.

**Type consistency:** `toAdminArray` shape (backend Task 2) matches `AdminUser` (frontend Task 10) field-for-field: `id, name, username, email, email_verified, provider, xp, level, is_admin, banned_at, ban_reason, created_at, avatar`. `AdminUserUpdate` (`{name, username, email, email_verified, is_admin}`) matches `AdminUpdateUserRequest::rules()`. Store action names used in Tasks 12–13 (`fetchUsers`, `setSearch`, `setPage`, `fetchUser`, `updateUser`, `deleteUser`, `banUser`, `unbanUser`, `resetXp`) are exactly those defined in Task 10. Middleware aliases `admin` / `not-banned` are consistent between `bootstrap/app.php` and `routes/api.php` across Tasks 2 and 8.
