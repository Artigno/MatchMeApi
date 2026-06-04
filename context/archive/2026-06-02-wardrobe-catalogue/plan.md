# Plan: GET /api/garments — Paginated Wardrobe Catalogue (S-04)

- **Change ID:** wardrobe-catalogue
- **Roadmap:** S-04
- **PRD refs:** FR-004
- **Status:** planned
- **Created:** 2026-06-02

## Overview

Add `GET /api/garments` — returns a paginated (page 20), auth-scoped list of all non-deleted garments, sorted `created_at desc`. Each item uses the existing `garmentResource()` helper (9 fields). Response envelope: `data[]`, `meta{}`, `links{}`.

## What We're NOT Doing

- No query filters (`?category`, `?condition`, `?per_page`)
- No cursor pagination
- No summary/subset shape — full 9 fields per item
- No eager-loading changes (all fields are on the `garments` table, no extra queries)

## Phase 1: Controller method + route

### Overview

Add `index()` to `GarmentController`, register the route.

### Changes Required

**`app/Http/Controllers/Api/GarmentController.php`**
- Add `index(Request $request): JsonResponse` after `show()`:
  ```php
  public function index(Request $request): JsonResponse
  {
      $paginator = Garment::where('user_id', $request->user()->id)
          ->orderByDesc('created_at')
          ->paginate(20);

      return response()->json([
          'data'  => $paginator->getCollection()->map(fn (Garment $g) => $this->garmentResource($g))->values(),
          'meta'  => [
              'current_page' => $paginator->currentPage(),
              'last_page'    => $paginator->lastPage(),
              'per_page'     => $paginator->perPage(),
              'total'        => $paginator->total(),
          ],
          'links' => [
              'first' => $paginator->url(1),
              'last'  => $paginator->url($paginator->lastPage()),
              'prev'  => $paginator->previousPageUrl(),
              'next'  => $paginator->nextPageUrl(),
          ],
      ]);
  }
  ```
  Note: `SoftDeletes` scope on the `Garment` model automatically adds `deleted_at IS NULL` — no manual `whereNull` needed.

**`routes/api.php`**
- Replace `// S-04: wardrobe-catalogue endpoints here` with:
  ```php
  Route::get('/garments', [GarmentController::class, 'index']);
  ```

### Success Criteria

Automated:
- `composer test` passes — all existing tests plus new phase passes

Manual:
- `GET /api/garments` with valid Bearer token returns HTTP 200 with `data`, `meta`, `links` keys
- `meta.per_page` = 20, `meta.total` reflects row count
- Items ordered newest-first (`created_at desc`)
- Soft-deleted garments absent from response

---

## Phase 2: Feature tests

### Overview

Create `tests/Feature/WardrobeCatalogueTest.php` with 4 tests.

### Changes Required

**`tests/Feature/WardrobeCatalogueTest.php`** (new file)

4 tests:
1. `test_index_returns_paginated_garments` — creates 3 garments for auth user, asserts 200, `data` has 3 items, each item has 9 required keys, `meta.total = 3`, `meta.per_page = 20`, first item is newest (`created_at` desc order)
2. `test_index_returns_empty_data_for_empty_wardrobe` — no garments, asserts 200, `data = []`, `meta.total = 0`
3. `test_index_requires_authentication` — no token, asserts 401
4. `test_index_does_not_return_other_users_garments` — user A has 2 garments, user B has 1; auth as B, asserts `meta.total = 1` and data contains only B's garment

Structure mirrors `GarmentListingCardTest`: `RefreshDatabase`, `createGarment(User $user)` helper, `token(User $user)` helper, `Bearer` auth header.

### Success Criteria

Automated:
- `composer test` — all 4 new tests pass, 0 regressions

Manual:
- Test names are descriptive and match the 4 scenarios
- No `Garment::factory()->create()` without `->for($user)` (ownership always scoped)

---

## Progress

### Phase 1: Controller method + route
#### Automated
- [x] 1.1 composer test — existing suite still green after route + method added
#### Manual
- [x] 1.2 GET /api/garments with Bearer token returns 200 + correct envelope shape (data, meta, links) — covered by `test_index_returns_paginated_garments` (asserts data/meta/links structure, per_page=20, total, desc order)

### Phase 2: Feature tests
#### Automated
- [x] 2.1 composer test — all 4 new WardrobeCatalogueTest tests pass, 0 regressions (36 passed, 128 assertions)
#### Manual
- [x] 2.2 Review test file: 4 tests present, each covers its named scenario, factory usage scoped to user (`->for($user)`)
