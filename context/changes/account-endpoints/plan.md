---
change_id: account-endpoints
title: Add GET /api/user endpoint (S-01)
status: planned
created: 2026-06-01
updated: 2026-06-01
---

## Context

S-01 roadmap outcome: "user can register, log in, log out, GET /api/user."

With `supabase-auth-scaffold` already shipped:
- Register → Supabase SDK (Expo client, no Laravel route)
- Login → `POST /api/auth/supabase/exchange` ✅
- Refresh → `POST /api/auth/refresh` ✅
- Logout → `POST /api/auth/logout` ✅
- **GET /api/user** ❌ missing

Only remaining deliverable: one endpoint returning the authenticated user's profile.

## What We're NOT Doing

- Password-based register/login routes — Supabase handles credentials
- Profile update (PATCH /api/user) — not in S-01 scope
- Fixing supabase-auth-scaffold impl-review findings (F1–F8) — separate change
- API Resources / transformers — no pattern established yet; plain `->json()` sufficient

---

## Phase 1: Add GET /api/user endpoint

### Overview

Create `UserController` with a `show()` method, wire it to `GET /api/user` inside the existing `auth:sanctum + access` middleware group, and add feature tests.

### Changes Required

1. **`app/Http/Controllers/Api/UserController.php`** — new file
   - `show(Request $request): JsonResponse`
   - Returns `{id, email, name, created_at}` from `$request->user()`
   - No mass-assignment, no extra logic — pure read

2. **`routes/api.php`** — add inside `['auth:sanctum', CheckForAnyAbility::class.':access']` group:
   ```php
   Route::get('/user', [UserController::class, 'show']);
   ```

3. **`tests/Feature/UserEndpointTest.php`** — new file, 3 test cases:
   - `test_returns_authenticated_user()` — access token → 200 with correct fields
   - `test_unauthenticated_returns_401()` — no token → 401
   - `test_refresh_token_rejected()` — refresh token → 403

### Success Criteria

#### Automated
- `composer test` passes (all tests green)
- `GET /api/user` returns 200 with `{id, email, name, created_at}` for access token
- `GET /api/user` returns 401 for missing token
- `GET /api/user` returns 403 for refresh token

#### Manual
- `GET /api/user` with a real Sanctum access token in Postman returns correct user data

---

## Phase 2: Update roadmap

### Overview

Mark S-01 as `done` in `context/foundation/roadmap.md`. Add a note clarifying that register/login are Supabase-side and that the exchange endpoint covers the API-layer login.

### Changes Required

1. **`context/foundation/roadmap.md`**:
   - `S-01 account-endpoints` status: `proposed` → `done`
   - `## At a glance` table: update Status cell
   - `## Done` section: append entry

### Success Criteria

#### Automated
- `grep 'account-endpoints.*done' context/foundation/roadmap.md` → match

#### Manual
- Roadmap `## At a glance` shows S-01 as `done`

---

## Progress

### Phase 1: Add GET /api/user endpoint

#### Automated
- [x] 1.1 Create `app/Http/Controllers/Api/UserController.php`
- [x] 1.2 Add `GET /api/user` route to `routes/api.php`
- [x] 1.3 Create `tests/Feature/UserEndpointTest.php` with 3 tests
- [x] 1.4 `composer test` passes

#### Manual
- [x] 1.5 `GET /api/user` with access token in Postman → 200 + correct fields

### Phase 2: Update roadmap

#### Automated
- [ ] 2.1 Update `context/foundation/roadmap.md` — S-01 status → `done`

#### Manual
- [ ] 2.2 Roadmap `## At a glance` visually confirms S-01 done
