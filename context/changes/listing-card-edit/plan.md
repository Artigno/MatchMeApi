# Listing Card Review / Edit Implementation Plan

## Overview

Implements S-03: `GET /api/garments/{id}` and `PATCH /api/garments/{id}` — lets the authenticated user retrieve and partially edit a classified listing card. PATCH uses present-key semantics: a key present in the body updates the field (null clears it); an absent key leaves the field unchanged.

## Current State Analysis

- `GarmentController` has only `classify()` — no show/update methods
- `routes/api.php:27` has placeholder comment `// S-03: listing-card-edit endpoints here`
- `Garment` model: 5 nullable fillable fields (`category`, `brand`, `color`, `condition`, `description`), `user_id` NOT in `$fillable`, `SoftDeletes`, `BelongsTo` user, Spatie media collection `photos`
- Ownership check must be manual — route model binding resolves by `id` regardless of `user_id`; `abort(404)` is the ownership guard
- Test pattern: `RefreshDatabase`, `User::factory()`, `createToken('access', ['access'], now()->addMinutes(5))`, Bearer header

## Desired End State

- `GET /api/garments/{id}` with valid token → 200 `{id, category, brand, color, condition, description, photo_url, created_at, updated_at}` for own garment; 404 for unknown or another user's garment; 401 without token
- `PATCH /api/garments/{id}` with valid token → 200 same 9-field shape after partial update; fields absent from body unchanged; field present as `null` cleared; invalid condition → 422; 404/401 same as above

### Key Discoveries

- `Garment::$fillable`: `['category', 'brand', 'color', 'condition', 'description']` — all 5 patchable fields are fillable, IDOR-safe (`app/Models/Garment.php:16`)
- `GarmentClassifierService` validates `condition` against `['new', 'like new', 'good', 'fair', 'worn']` — same enum applies on PATCH
- `SoftDeletes` scope auto-excludes soft-deleted garments from route model binding — deleted garment → 404 for free
- `$garment->update($request->validated())` with `sometimes` rules includes only request-present keys — correct present-key semantics with no extra code
- `GarmentClassifyTest::test_classify_creates_garment_and_returns_resource` uses `assertJsonStructure` on 8 fields — adding `updated_at` to GET/PATCH shape does not touch POST or its tests

## What We're NOT Doing

- Changing `classify()` POST response shape — stays 8 fields (no `updated_at`)
- Photo replacement via PATCH — `photo_url` is read-only after creation
- Bulk PATCH
- Wardrobe list (`GET /api/garments`) — S-04
- Garment deletion — S-05

## Implementation Approach

Add `show()` and `update()` to existing `GarmentController`. Extract private `garmentResource(Garment $garment): array` returning 9 fields — both new methods use it. Both enforce ownership via `abort(404)` after route model binding resolves. `update()` validates with `sometimes|nullable` rules, `condition` additionally constrained to the five-value enum, then calls `$garment->update($request->validated())`.

---

## Phase 1: Controller methods + routes

### Overview

Add `show()`, `update()`, and private `garmentResource()` to `GarmentController`. Replace the S-03 placeholder comment in `routes/api.php` with the two routes.

### Changes Required

#### 1. GarmentController — show()

**File**: `app/Http/Controllers/Api/GarmentController.php`

**Intent**: Retrieve an owned garment by ID and return the 9-field listing card resource.

**Contract**: `public function show(Request $request, Garment $garment): JsonResponse` — route model binding resolves `{garment}` by primary key with SoftDeletes scope applied; `abort(404)` when `$garment->user_id !== $request->user()->id`; returns `response()->json($this->garmentResource($garment))`.

#### 2. GarmentController — update()

**File**: `app/Http/Controllers/Api/GarmentController.php`

**Intent**: Partially update classification fields using present-key semantics.

**Contract**: `public function update(Request $request, Garment $garment): JsonResponse` — same ownership check; validates:

```
category    → sometimes|nullable|string|max:255
brand       → sometimes|nullable|string|max:255
color       → sometimes|nullable|string|max:255
condition   → sometimes|nullable|string|in:new,like new,good,fair,worn
description → sometimes|nullable|string|max:5000
```

Calls `$garment->update($request->validated())`; returns `response()->json($this->garmentResource($garment->fresh()))`.

#### 3. GarmentController — garmentResource() helper

**File**: `app/Http/Controllers/Api/GarmentController.php`

**Intent**: Single 9-field response shape shared by show() and update().

**Contract**: `private function garmentResource(Garment $garment): array` — keys: `id`, `category`, `brand`, `color`, `condition`, `description`, `photo_url` (via `$garment->getFirstMediaUrl('photos')`), `created_at`, `updated_at`.

#### 4. Routes

**File**: `routes/api.php`

**Intent**: Wire the two new routes in the authenticated group, replacing the placeholder comment.

**Contract**: Replace `// S-03: listing-card-edit endpoints here` with:
```
Route::get('/garments/{garment}', [GarmentController::class, 'show']);
Route::patch('/garments/{garment}', [GarmentController::class, 'update']);
```
Both inherit the `['auth:sanctum', CheckForAnyAbility::class.':access']` group middleware.

### Success Criteria

#### Automated Verification

- `composer test` passes (25 existing tests still green)
- `php artisan route:list | grep garments` shows GET, POST, and PATCH routes for `/api/garments`

#### Manual Verification

- `GET /api/garments/{id}` with valid token → 200 with 9 fields including `updated_at`
- `PATCH /api/garments/{id}` with `{"brand": "Nike"}` → 200, only brand changed, other fields unchanged
- `PATCH /api/garments/{id}` with `{"brand": null}` → 200, brand is null
- `PATCH /api/garments/{id}` with `{"condition": "ancient"}` → 422 validation error
- `GET /api/garments/{id}` without token → 401
- `GET /api/garments/99999` (non-existent) → 404

**Implementation Note**: Pause here for manual confirmation before proceeding to Phase 2.

---

## Phase 2: Feature tests

### Overview

5 test cases covering the full S-03 surface: GET/PATCH happy paths, auth guard, 404 (not-found), and 404 (ownership).

### Changes Required

#### 1. Test file

**File**: `tests/Feature/GarmentListingCardTest.php`

**Intent**: Prove GET and PATCH endpoints work end-to-end — own garments accessible, auth enforced, other users' garments return 404.

**Test cases**:
1. `test_show_returns_garment_resource` — GET own garment → 200, `assertJsonStructure` with all 9 fields
2. `test_update_returns_updated_resource` — PATCH own garment with `{'brand': 'Nike'}` → 200, brand updated, remaining fields unchanged
3. `test_show_requires_authentication` — GET without token → 401
4. `test_show_returns_404_for_unknown_garment` — GET non-existent ID → 404
5. `test_show_returns_404_for_another_users_garment` — create second user + their garment; first user GETs it → 404

**Contract**: Each test uses `RefreshDatabase`. Garment created via direct model instantiation (no factory needed): `$garment = new Garment([...]); $garment->user_id = $user->id; $garment->save()`. No media attachment in tests — `photo_url` will be empty string from Spatie, acceptable.

### Success Criteria

#### Automated Verification

- `composer test` passes (30 total tests green — 25 existing + 5 new)
- All 5 new test methods appear with ✓ in output

#### Manual Verification

- `php artisan test --filter=GarmentListingCardTest` lists all 5 methods

---

## References

- Roadmap S-03: `context/foundation/roadmap.md:131`
- S-02 plan (controller/route/test patterns): `context/changes/ai-classification/plan.md`
- GarmentController: `app/Http/Controllers/Api/GarmentController.php`
- Garment model: `app/Models/Garment.php`
- Routes: `routes/api.php`
- Lessons (null rule): `context/foundation/lessons.md`

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when step lands. Do not rename step titles.

### Phase 1: Controller methods + routes

#### Automated

- [x] 1.1 Add `show()` to `GarmentController` — 4445ecd
- [x] 1.2 Add `update()` to `GarmentController` — 4445ecd
- [x] 1.3 Add private `garmentResource()` helper to `GarmentController` — 4445ecd
- [x] 1.4 Update `routes/api.php` — add GET and PATCH routes — 4445ecd
- [x] 1.5 `composer test` passes (25 existing green) — 4445ecd
- [x] 1.6 `php artisan route:list | grep garments` shows GET, POST, PATCH — 4445ecd

#### Manual

- [x] 1.7 GET own garment → 200 with 9 fields — 4445ecd
- [x] 1.8 PATCH partial update → 200 with updated resource — 4445ecd
- [x] 1.9 PATCH `{"brand": null}` → 200 with brand null — 4445ecd
- [x] 1.10 GET without token → 401 — 4445ecd
- [x] 1.11 GET non-existent ID → 404 — 4445ecd

### Phase 2: Feature tests

#### Automated

- [x] 2.1 Create `tests/Feature/GarmentListingCardTest.php` (5 test cases) — 97f9723
- [x] 2.2 `composer test` passes (30 total tests green) — 97f9723

#### Manual

- [x] 2.3 All 5 methods appear with ✓ in `php artisan test --filter=GarmentListingCardTest` output — 97f9723
