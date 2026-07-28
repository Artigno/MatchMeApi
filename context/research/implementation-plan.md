---
project: MirrorMatch
type: implementation-strategy
generated: 2026-06-03
scope: not-implemented roadmap items only (S-04, S-05)
foundation_verified: prd.md, roadmap.md, tech-stack.md, lessons.md, env-config.md, infrastructure.md, shape-notes.md
---

# Implementation Strategy — Remaining MVP Items

> One strategy per open issue. S-01/S-02/S-03 implemented (S-03 needs roadmap-mark + archive only).
> Grounded in: existing `GarmentController` (index/show/update/classify), spatie media-library photos,
> `Garment` model with `SoftDeletes`, Sanctum `auth:sanctum+access` guard, `RefreshDatabase` test pattern.

---

## Issue 1 — S-04 wardrobe-catalogue (FINISH, ~40% → 100%)

**State:** `index()` + `GET /api/garments` route already written (uncommitted working tree). `composer test` green (32).
**Left:** feature tests + commit + manual verify + archive. Phase 1 code done; only Phase 2 remains.

**Single strategy:**

1. **Commit Phase 1** (already-written code)
   - `GarmentController::index()` (lines 18–39) + route `routes/api.php:26`.
   - Commit: `feat(wardrobe-catalogue): index() + GET /api/garments route (p1)`.

2. **Phase 2 — feature tests** → new `tests/Feature/WardrobeCatalogueTest.php`, 4 tests:
   - `test_index_returns_paginated_garments` — 3 garments, assert 200, `data` 3 items × 9 keys, `meta.total=3`, `meta.per_page=20`, newest-first order.
   - `test_index_returns_empty_data_for_empty_wardrobe` — `data=[]`, `meta.total=0`.
   - `test_index_requires_authentication` — no token → 401.
   - `test_index_does_not_return_other_users_garments` — user-B scoped, `meta.total=1`.
   - Mirror `GarmentListingCardTest`: `RefreshDatabase`, `createGarment(User)`, `token(User)`, `Bearer` header. Every `Garment::factory()` scoped `->for($user)`.
   - Commit: `test(wardrobe-catalogue): WardrobeCatalogueTest — 4 feature tests (p2)`.

3. **Verify** — `composer test` → expect 36 green (32 + 4). Manual: GET returns `{data,meta,links}`, `per_page=20`, newest-first, soft-deleted absent.

4. **Close** — `/10x-impl-review wardrobe-catalogue` → apply findings → `/10x-archive wardrobe-catalogue`.

**Risk (roadmap):** N+1 if fields lazy — all 9 fields on `garments` table, no join. `getFirstMediaUrl('photos')` per row is 1 media query/item; acceptable at page=20 (pre-existing pattern in show/index). If it bites later: eager-load `->with('media')`.

**Files touched:** `tests/Feature/WardrobeCatalogueTest.php` (new). Controller + route already present.

---

## Issue 2 — S-05 garment-removal (NEW, 0% → 100%)

**State:** no change folder. Not started.
**Decision (Q2): SOFT-DELETE.** Model has `SoftDeletes`; `$garment->delete()` sets `deleted_at`, row hidden from all existing queries (index/show already scoped). Reversible, no S3-orphan risk. Hard-delete rejected: would need media purge + S3 `DeleteObject` in same tx/job (orphan risk per roadmap S-05 risk note).

**Single strategy:**

1. **Open change** — `/10x-new garment-removal`.

2. **Phase 1 — controller method + route**
   - `GarmentController::destroy(Request, Garment): JsonResponse` after `update()`:
     ```php
     public function destroy(Request $request, Garment $garment): JsonResponse
     {
         if ($garment->user_id !== $request->user()->id) {
             abort(404);
         }
         $garment->delete();             // SoftDeletes → sets deleted_at; media retained
         return response()->json(null, 204);
     }
     ```
   - `routes/api.php` add: `Route::delete('/garments/{garment}', [GarmentController::class, 'destroy']);`
   - Ownership guard mirrors `show()`/`update()` exactly (404 not 403 — don't leak existence; same as existing pattern + S-01 lesson on generic errors).
   - Commit: `feat(garment-removal): destroy() + DELETE /api/garments/{id} (p1)`.

3. **Phase 2 — feature tests** → new `tests/Feature/GarmentRemovalTest.php`, 4 tests:
   - `test_destroy_soft_deletes_own_garment` — DELETE → 204; `assertSoftDeleted('garments', ['id'=>$g->id])`.
   - `test_destroyed_garment_absent_from_index` — after delete, GET /api/garments `meta.total=0`.
   - `test_destroy_other_users_garment_returns_404` — user-B garment → 404; still present in DB (not deleted).
   - `test_destroy_requires_authentication` — no token → 401.
   - Same `RefreshDatabase` + helpers pattern.
   - Commit: `test(garment-removal): GarmentRemovalTest — 4 feature tests (p2)`.

4. **Verify** — `composer test` → expect 40 green (36 + 4). Manual: DELETE own → 204; GET {id} after → 404; index excludes it; DELETE other-user → 404.

5. **Close** — `/10x-impl-review garment-removal` → apply findings → `/10x-archive garment-removal`.

**Files touched:** `app/Http/Controllers/Api/GarmentController.php` (+destroy), `routes/api.php` (+DELETE), `tests/Feature/GarmentRemovalTest.php` (new).

**Out of scope:** hard-delete, S3 media purge, restore/undelete endpoint, bulk delete.

---

## Sequencing

S-04 and S-05 both touch `GarmentController` + `routes/api.php` + a new test file. No conflict (different methods/routes/files). Recommended order: **S-04 first** (finish in-flight work, get to clean committed tree), then S-05. `/clear` between issues per the change chain.

| Step | Command | Lands at |
| --- | --- | --- |
| 1 | commit S-04 p1, write S-04 p2 tests | 36 tests, S-04 done |
| 2 | `/10x-impl-review wardrobe-catalogue` → `/10x-archive wardrobe-catalogue` | ~92% MVP |
| 3 | `/10x-new garment-removal` → `/10x-plan garment-removal` | change opened |
| 4 | `/10x-implement garment-removal phase 1` then `phase 2` | 40 tests, S-05 done |
| 5 | `/10x-impl-review garment-removal` → `/10x-archive garment-removal` | 100% MVP |
| 6 | sync `roadmap.md` At-a-glance + Done for S-03, S-04, S-05 | roadmap current |

## Test count ladder

32 (now) → 36 (S-04) → 40 (S-05).
