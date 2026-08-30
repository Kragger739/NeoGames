# Admin Dashboard + Public Admin Badge — Design

**Date:** 2026-08-30
**Status:** Approved for planning

## Goal

Give a small set of trusted accounts an in-app admin area to manage user
accounts (edit, delete, and moderate), and show every visitor a badge on
users who are admins.

Two deliverables:

1. **Admin dashboard** — an `/admin` section in the existing React SPA,
   reachable only by admins, with a searchable user list and per-user
   edit / delete / moderation actions, backed by an `/api/admin/*` API.
2. **Public admin badge** — a marker rendered next to admin users
   everywhere a user is shown (self, friends, leaderboard, room player
   lists, DDF player lists, profile), visible to all users including
   logged-out visitors on public room pages.

## Non-goals (YAGNI)

- No manual "create user" form — accounts self-register.
- No role tiers beyond a single `is_admin` boolean (no moderator/support
  levels, no granular permissions, no `spatie/laravel-permission`).
- No generic "admin shell" for datasets / rooms / songs / seasons — this
  is scoped to users only. The route layout leaves room to add siblings
  later, but none are built now.
- No audit log of admin actions.
- No bulk actions.
- No email notification to a user when they are banned or edited.

## Current state (what exists today)

- **Backend:** Laravel (11/12 style — middleware registered in
  `bootstrap/app.php`, no `app/Http/Middleware/` dir). Auth is Sanctum
  SPA session auth (`auth:sanctum` + `verified` groups in
  `routes/api.php`). `users` table is plain: `name`, `username`,
  `email`, `email_verified_at`, `password`, `xp`, `avatar_path`,
  `equipped_cosmetics`, `provider`, `provider_id`. **No role/admin
  concept, no `Gate` definitions.** Policies exist only for `Dataset`
  and `Friendship`.
- **User serialization chokepoint:** `User::avatarPayload()` returns
  `{ avatar_url, level, cosmetics }` and is the single method every
  user-facing surface calls — directly on the `User` model's `avatar`
  append, and via `RoomPlayer`'s `avatar` append, and explicitly in
  `LeaderboardController` and `FriendController`. Threading a new field
  through it reaches every surface at once.
- **Frontend:** React + react-router + zustand. `useAuthStore` holds
  `host: Host | null`. `RequireHost` guards authed routes. Nav links
  live in `HomePage.tsx`'s `<nav>`. The `<Avatar>` component
  (`components/ui/Avatar.tsx`) composites photo + cosmetic layers and is
  used on every surface; `AvatarData` type is in `lib/avatarData`.
- **Login:** `LoginRequest::authenticate()` does throttled
  `Auth::attempt()`. `AuthController::login()` regenerates the session
  and returns `Auth::user()`.

## Data model

New migration `2026_08_30_000001_add_admin_and_moderation_to_users_table.php`:

| Column        | Type                    | Notes                                             |
|---------------|-------------------------|---------------------------------------------------|
| `is_admin`    | `boolean`, default `false`, indexed | Grants admin-area access + public badge. |
| `banned_at`   | `timestamp`, nullable   | Non-null ⇒ account is banned.                     |
| `ban_reason`  | `string`, nullable      | Free text, shown to the banned user on login.     |

Second migration `2026_08_30_000002_promote_initial_admin.php` — data
migration, idempotent: `User::where('email', 'diamondpickminer@gmail.com')
->update(['is_admin' => true])`. No-op if the row doesn't exist yet.
`down()` sets it back to `false`.

### `User` model changes

- Add `'is_admin' => 'boolean'`, `'banned_at' => 'datetime'` to `casts()`.
- Add `is_admin` to the `#[Fillable]` list is **not** done — it is never
  mass-assigned from user input; the admin controller sets it explicitly.
- `avatarPayload()` gains `'is_admin' => (bool) $this->is_admin`. This is
  the only change needed for the badge to appear on every surface.
- Add helper `isBanned(): bool => $this->banned_at !== null`.
- `#[Hidden]` stays as-is (`password`, `remember_token`,
  `equipped_cosmetics`). `is_admin`, `banned_at`, `ban_reason` are
  **not** hidden — `is_admin` is deliberately public (via the payload and
  the `/api/user` blob); `banned_at` / `ban_reason` only ever appear in
  admin responses and the login-rejection body, never in a public
  user listing.

## Authorization

### `EnsureUserIsAdmin` middleware

`app/Http/Middleware/EnsureUserIsAdmin.php` — `handle()` returns
`abort(403, 'Admin access required.')` unless
`$request->user()?->is_admin === true`. Registered in `bootstrap/app.php`
via `$middleware->alias(['admin' => EnsureUserIsAdmin::class])`.

Applied as `->middleware(['auth:sanctum', 'verified', 'admin'])` on the
admin route group.

### `EnsureUserNotBanned` middleware

`app/Http/Middleware/EnsureUserNotBanned.php` — if
`$request->user()?->isBanned()`, log the web guard out, invalidate the
session, and return `403` with
`{ message: 'Your account has been suspended.', reason: <ban_reason|null> }`.
Registered as alias `not-banned`.

Applied to the existing top-level `auth:sanctum` group in
`routes/api.php` (wrapping the block that starts at `Route::middleware('auth:sanctum')`),
so a session that was live when the ban landed is rejected on its next
request. `/api/logout` stays reachable (it's fine for a banned user to
hit logout; the middleware already logs them out anyway).

### Login-time block

`LoginRequest::authenticate()` — after a successful `Auth::attempt()`,
check `Auth::user()->isBanned()`. If banned: `Auth::guard('web')->logout()`,
then `throw ValidationException::withMessages(['email' => [<ban_reason
or 'This account has been suspended.'>]])`. Keeps ban enforcement in the
same place brute-force throttling already lives.

### Self-protection invariants (enforced in `AdminUserController`)

An admin acting on **their own** account:

- cannot set their own `is_admin` to `false` (`update`)
- cannot `destroy` their own account via the admin API (they can still
  use the normal `/api/user` self-delete)
- cannot `ban` their own account

Each returns `422` with a clear message. This prevents an admin locking
the whole team out of the admin area by accident. (With multiple admins
the last one is still a risk, but that's an operational choice, not an
accident; not guarded.)

## Backend API

New file `routes/admin.php`? — No. Keep it in `routes/api.php` inside a
prefixed group to match the existing single-file convention:

```php
Route::middleware(['auth:sanctum', 'verified', 'admin'])
    ->prefix('admin')->group(function () {
        Route::get('/users',                 [AdminUserController::class, 'index']);
        Route::get('/users/{user}',          [AdminUserController::class, 'show']);
        Route::patch('/users/{user}',        [AdminUserController::class, 'update']);
        Route::delete('/users/{user}',       [AdminUserController::class, 'destroy']);
        Route::post('/users/{user}/ban',     [AdminUserController::class, 'ban']);
        Route::post('/users/{user}/unban',   [AdminUserController::class, 'unban']);
        Route::post('/users/{user}/reset-xp',[AdminUserController::class, 'resetXp']);
    });
```

### `AdminUserController`

All responses use a dedicated `toAdminArray(User $user): array` shape
(defined privately in the controller) so admin-only fields are explicit
and never leak through the public `User` serialization:

```
{
  id, name, username, email, email_verified (bool), provider,
  xp, level, is_admin (bool), banned_at (iso8601|null),
  ban_reason (string|null), created_at, avatar (avatarPayload)
}
```

- **`index`** — `?search=` filters `name` / `username` / `email` with a
  `LIKE %term%` (case-insensitive). `?page=` — Laravel paginator, 25 per
  page. Ordered `created_at desc`. Returns
  `{ data: [...], meta: { current_page, last_page, total } }`.
- **`show`** — single `toAdminArray`.
- **`update`** — `AdminUpdateUserRequest`: `name` (required, string,
  ≤255), `username` (required, string, ≤30, `alpha_dash`, unique except
  self), `email` (required, email, unique except self), `email_verified`
  (required, boolean), `is_admin` (required, boolean). Setting
  `email_verified` true when currently null sets `email_verified_at =
  now()`; setting it false nulls the column. Self-protection: reject
  `is_admin=false` when `{user}` is the actor.
- **`destroy`** — hard delete (no soft-deletes in this codebase).
  Cascades already defined on FKs handle child rows; where a FK is
  `cascadeOnDelete` this is fine, and where it's `nullOnDelete` the
  content (e.g. datasets `owner_id`) is orphaned by design. Self-protect.
  Returns `204`.
- **`ban`** — `{ reason?: string ≤255 }`. Sets `banned_at = now()`,
  `ban_reason = reason`. Deletes the user's Sanctum tokens
  (`$user->tokens()->delete()`) and their `sessions` rows
  (`DB::table('sessions')->where('user_id', $user->id)->delete()`) so
  they're kicked immediately, not just on next request. Self-protect.
  Returns updated `toAdminArray`.
- **`unban`** — nulls `banned_at` and `ban_reason`. Returns
  `toAdminArray`.
- **`resetXp`** — sets `xp = 0` and deletes the user's `season_progress`
  rows (the leaderboard reads from `season_progress`, so both must go for
  the reset to be visible). Does **not** touch `xp_events` history.
  Returns `toAdminArray`.

### `/api/user` + auth responses

`Host` payload (`AuthController` register/login/`user`) already returns
the full `User` model, so `is_admin` rides along automatically once it's
cast and unhidden. No controller change needed there.

## Frontend

### Types

- `Host` (`stores/authStore.ts`) gains `is_admin: boolean`.
- `AvatarData` (`lib/avatarData.ts`) gains `is_admin?: boolean`.

### `<Avatar>` badge

Add a final layer in `Avatar.tsx`, after the cosmetic layers:

```tsx
{data.is_admin && (
  <span className="avatar-admin-badge" title="Admin" aria-label="Admin">
    <ShieldCheck /* lucide */ />
  </span>
)}
```

Positioned bottom-right of the avatar box via a new `.avatar-admin-badge`
rule in `src/styles/avatar.css` (where the other `.avatar-*` rules live) —
a small circular chip with the app's accent colour, scaled per
`avatar-{size}`.
Because every surface renders the user through `<Avatar data={...}>` with
the `avatarPayload` blob, this is the only render-site change. Surfaces
that show a username as plain text with no `<Avatar>` (rare) are left
alone for now.

### `RequireAdmin` guard

`components/RequireAdmin.tsx` — same shape as `RequireHost` (waits for
`status === 'ready'`, redirects to `/login` if no host, to
`/verify-email` if unverified) plus: redirect to `/` if
`!host.is_admin`. Wraps the admin routes.

### Routes (`App.tsx`)

```tsx
<Route path="/admin" element={<RequireAdmin><AdminUsersPage /></RequireAdmin>} />
<Route path="/admin/users/:id" element={<RequireAdmin><AdminUserDetailPage /></RequireAdmin>} />
```

### `adminStore` (zustand)

`stores/adminStore.ts` — state: `users`, `meta`, `search`, `page`,
`status`, `selected` (detail). Actions: `fetchUsers()`,
`setSearch(term)` (debounced by the page), `fetchUser(id)`,
`updateUser(id, payload)`, `deleteUser(id)`, `banUser(id, reason)`,
`unbanUser(id)`, `resetXp(id)`. Each calls the matching `/api/admin/*`
endpoint via the shared `api` client and updates local state from the
response. Errors bubble to the page for toast display.

### Pages

- **`AdminUsersPage`** (`/admin`) — page header, search input (filters
  server-side via `setSearch`), a table: avatar+username, email,
  verified tick, admin tick, XP/level, joined date, "banned" pill if
  banned. Row click → `/admin/users/:id`. Pagination controls bound to
  `meta`. Matches the visual language of existing list pages
  (`LeaderboardPage`, `FriendsPage`) — same card / table styling, no new
  design system.
- **`AdminUserDetailPage`** (`/admin/users/:id`) — read of `selected`
  via `fetchUser`. An edit form (name, username, email, email-verified
  toggle, is-admin toggle) with save. A "Moderation" section: ban (with
  optional reason field) / unban, reset XP, delete (each behind a
  confirm — use an inline confirm/second-click or the existing modal
  pattern, **not** `window.confirm`, per the no-dialogs rule).
  Self-protection: the is-admin toggle, ban button, and delete button
  are disabled with a tooltip when `id === host.id`.

### Nav entry

In `HomePage.tsx`'s `<nav>`, after the Friends link, render only when
`host?.is_admin`:

```tsx
{host?.is_admin && (
  <Link to="/admin">
    <ShieldCheck size={16} strokeWidth={2.25} />
    Admin
  </Link>
)}
```

(`host` comes from `useAuthStore`.)

## Testing

### Backend (Pest/PHPUnit feature tests)

`tests/Feature/Admin/AdminUserManagementTest.php`:

- non-admin (and guest) → `403` on every `/api/admin/*` route
- admin can list users; `?search=` narrows by name/username/email
- `index` paginates (26 users ⇒ `last_page = 2`)
- `update` changes fields; `email_verified` toggle sets/clears
  `email_verified_at`; username/email uniqueness rejects a collision but
  allows the user's own current value
- `update` with `is_admin=false` on **self** → `422`, unchanged in DB
- `destroy` removes the user; on **self** → `422`
- `ban` sets `banned_at`/`ban_reason`, deletes their sessions + tokens;
  on **self** → `422`
- `unban` clears both fields
- `resetXp` zeroes `xp` and deletes `season_progress` rows; `xp_events`
  untouched

`tests/Feature/Admin/BannedUserTest.php`:

- banned user cannot log in — `authenticate()` throws, message is the
  ban reason
- a banned user with a still-valid session is `403`'d by
  `EnsureUserNotBanned` on the next authed API call and their session is
  invalidated
- `/api/logout` still succeeds for a banned user

`tests/Feature/AvatarPayloadTest.php` (or extend an existing user/model
test):

- `avatarPayload()` / `/api/user` / leaderboard entry / friend entry
  include `is_admin` and it reflects the column

### Frontend

- `RequireAdmin` — renders children for an admin host; redirects a
  non-admin host to `/`; redirects a guest to `/login` (mirror the
  existing `RequireHost` test if there is one; otherwise a focused
  component test).
- `adminStore` — `updateUser` / `banUser` / `resetXp` call the right
  endpoint and merge the response (mock `api`).
- `<Avatar>` — renders `.avatar-admin-badge` when `data.is_admin`, omits
  it otherwise.

Manual smoke (documented, not automated): promote a second account from
the dashboard, confirm the badge appears on the leaderboard for both,
ban it, confirm it's logged out and cannot log back in, unban.

## Rollout

1. Ship migrations — `is_admin` defaults false, so existing users are
   unaffected; the promote migration makes `diamondpickminer@gmail.com`
   the first admin.
2. No env/config changes. No new dependencies.
3. `docker-compose` / deploy runs `php artisan migrate` as it already
   does.

## Files touched

**Backend (new):**
- `database/migrations/2026_08_30_000001_add_admin_and_moderation_to_users_table.php`
- `database/migrations/2026_08_30_000002_promote_initial_admin.php`
- `app/Http/Middleware/EnsureUserIsAdmin.php`
- `app/Http/Middleware/EnsureUserNotBanned.php`
- `app/Http/Controllers/Api/Admin/AdminUserController.php`
- `app/Http/Requests/Admin/AdminUpdateUserRequest.php`
- `tests/Feature/Admin/AdminUserManagementTest.php`
- `tests/Feature/Admin/BannedUserTest.php`

**Backend (edit):**
- `app/Models/User.php` — casts, `avatarPayload()`, `isBanned()`
- `bootstrap/app.php` — middleware aliases
- `routes/api.php` — admin group; wrap authed group with `not-banned`
- `app/Http/Requests/Auth/LoginRequest.php` — ban check

**Frontend (new):**
- `src/components/RequireAdmin.tsx`
- `src/stores/adminStore.ts`
- `src/pages/AdminUsersPage.tsx`
- `src/pages/AdminUserDetailPage.tsx`
- corresponding test files

**Frontend (edit):**
- `src/App.tsx` — routes
- `src/stores/authStore.ts` — `Host.is_admin`
- `src/lib/avatarData.ts` — `AvatarData.is_admin`
- `src/components/ui/Avatar.tsx` — badge layer
- `src/styles/avatar.css` — `.avatar-admin-badge`
- `src/pages/HomePage.tsx` — conditional nav link
