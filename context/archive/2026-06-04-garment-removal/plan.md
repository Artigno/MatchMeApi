# Garment Removal (S-05) Implementation Plan

## Overview

Implement `DELETE /api/garments/{id}` (FR-005): a user hard-deletes one of their own
garments — both the database row and its S3 photo — inside a single DB transaction,
after recording a `garment_deletions` audit snapshot. The action is irreversible by
design (no restore in MVP); the audit row is the forensic trail.

## Current State Analysis

- **Route placeholder** at `routes/api.php:29` (`// S-05: garment-removal endpoints here`),
  inside the `auth:sanctum` + `CheckForAnyAbility:access` group.
- **`GarmentController`** has `index/show/update/classify` but **no `destroy`**. Ownership
  guard is consistent across methods: `if ($garment->user_id !== $request->user()->id) abort(404);`
  (`GarmentController.php:44,57`).
- **`Garment` model** uses `SoftDeletes` + `InteractsWithMedia` (spatie media-library,
  single-file `photos` collection). Migration `2026_05_27_185627_create_garments_table.php`
  has `softDeletes()` and `user_id` FK `cascadeOnDelete`.
- **No audit infrastructure** exists yet.
- **Test pattern** established in `GarmentListingCardTest`: `RefreshDatabase`, Sanctum
  token via `createToken('access', ['access'], …)`, ownership-404 and unauth-401 cases.
- **Deploy**: Bref/Lambda, `FILESYSTEM_DISK=s3` in prod; queues (SQS) are commented out
  in `serverless.yml` — no async job infra available, so S3 cleanup must be synchronous.

### Key Discoveries:

- **`SoftDeletes` means `delete()` only soft-deletes** — `destroy` MUST call `forceDelete()`
  to truly remove the row AND trigger spatie's media (S3) removal. Spatie deliberately
  skips media deletion on a soft delete and only purges on force/real delete.
- Route-model binding `{garment}` resolves `withoutTrashed` by default; after a hard
  delete the row is gone entirely, so a repeat `DELETE` 404s naturally (idempotency-as-404).
- `index()` already excludes trashed via the SoftDeletes global scope — no catalogue
  change needed for removal to take effect.
- Spatie stores media rows in the `media` table; tests assert removal via
  `assertDatabaseMissing('media', …)` plus `Storage::fake`.

## Desired End State

`DELETE /api/garments/{id}` with a valid `access`-ability Sanctum token:

- Owner deleting their garment → **`204 No Content`**; the `garments` row is gone, the
  `media` row + S3 file are gone, and a `garment_deletions` row holds the snapshot.
- Another user's garment → **`404`**, nothing deleted.
- Unauthenticated → **`401`**; unknown or already-deleted id → **`404`**.
- If S3/media removal fails mid-delete → **fail loud (500)**, transaction rolls back, the
  garments row and audit row survive (retry-able). No orphaned S3 file without its row.

Verify: `composer test` green (new `GarmentRemovalTest`), `pint --test` clean, migration
applies, manual delete removes row + photo.

## What We're NOT Doing

- **No restore/undo endpoint** — deletion is terminal for this slice.
- **No soft-delete semantics for removal** — the model keeps the `SoftDeletes` trait
  (used elsewhere/implicitly), but `destroy` force-deletes.
- **No queue/SQS job** for S3 purge — synchronous removal only (infra not wired).
- **No bulk delete**, no rate-limit changes on the auth group.
- **No catalogue/index changes** — removal is already reflected by existing queries.
- **No audit UI or audit-read endpoint** — the table is write-only forensic storage.

## Implementation Approach

DB layer first (audit table + model), then the endpoint that writes the audit row and
force-deletes within a transaction, then feature tests. The transaction wraps the audit
insert and the `forceDelete()` so that a media-removal failure rolls back the audit row
too — the row and its S3 file live or die together.

## Critical Implementation Details

- **Force delete, not delete.** Because `Garment` uses `SoftDeletes`, `destroy` must call
  `$garment->forceDelete()`. Calling `delete()` would soft-delete and leave the S3 photo
  in place — the exact orphaned-file risk the roadmap flagged.
- **Ordering inside the transaction.** Snapshot → insert `garment_deletions` row →
  `forceDelete()` (spatie's `deleting`/`deleted` event removes the media file). If S3
  delete throws, the exception propagates out of `DB::transaction`, rolling back the audit
  insert and the row delete. The one non-transactional edge: S3 may have already removed
  the file before the throw — acceptable, rare, and acknowledged (S3 has no rollback).

## Phase 1: Audit schema

### Overview

Add the `garment_deletions` table and its model so the endpoint can persist a snapshot
of every removed garment.

### Changes Required:

#### 1. Migration

**File**: `database/migrations/<timestamp>_create_garment_deletions_table.php`

**Intent**: Persist an irreversible-delete audit trail. Stores who deleted what and a
JSON snapshot of the garment's attributes at deletion time (the garments row itself is
gone, so the snapshot is denormalized on purpose).

**Contract**: Table `garment_deletions` with: `id`; `user_id` foreignId constrained to
`users` (`cascadeOnDelete`); `garment_id` unsignedBigInteger (the original id, **no FK** —
the garments row no longer exists); `snapshot` json nullable; `timestamps()`.

#### 2. Model

**File**: `app/Models/GarmentDeletion.php`

**Intent**: Eloquent model for the audit table; lets the controller create a row and
tests assert on it.

**Contract**: `GarmentDeletion extends Model`. `$fillable = ['user_id', 'garment_id', 'snapshot']`;
`$casts = ['snapshot' => 'array']`; `user()` BelongsTo `User`.

### Success Criteria:

#### Automated Verification:

- Migration applies: `php artisan migrate:fresh` exits 0
- Table exists with expected columns (e.g. `php artisan db:table garment_deletions` or a tinker schema check)
- Pint clean: `./vendor/bin/pint --test`
- Existing suite still green: `composer test`

#### Manual Verification:

- `GarmentDeletion::create([...])` round-trips with `snapshot` cast to array

**Implementation Note**: After automated verification passes, pause for manual
confirmation before Phase 2.

---

## Phase 2: DELETE endpoint

### Overview

Add the route and `GarmentController@destroy` that authorizes, snapshots, audits, and
hard-deletes within a transaction, returning `204`.

### Changes Required:

#### 1. Route

**File**: `routes/api.php`

**Intent**: Wire `DELETE /api/garments/{garment}` to `destroy`, replacing the S-05
placeholder comment, inside the existing auth group.

**Contract**: `Route::delete('/garments/{garment}', [GarmentController::class, 'destroy']);`

#### 2. Controller method

**File**: `app/Http/Controllers/Api/GarmentController.php`

**Intent**: Authorize ownership (mirror existing 404 guard), build a snapshot from the
existing `garmentResource()` shape, write the audit row and `forceDelete()` the garment
inside `DB::transaction`, return `204`.

**Contract**: `public function destroy(Request $request, Garment $garment): Response` (or
`JsonResponse` with no content). Ownership: `$garment->user_id !== $request->user()->id → abort(404)`.
Inside `DB::transaction(function () { … })`: `GarmentDeletion::create(['user_id' => …, 'garment_id' => $garment->id, 'snapshot' => $this->garmentResource($garment)])` then `$garment->forceDelete();`.
Return `response()->noContent()` (204). No catch around the transaction — media-failure
propagates as 500 (fail loud).

### Success Criteria:

#### Automated Verification:

- Pint clean: `./vendor/bin/pint --test`
- Route registered: `php artisan route:list --path=api/garments` shows the DELETE route
- Full suite green: `composer test`

#### Manual Verification:

- Deleting an owned garment returns 204; row + photo removed; `garment_deletions` row present
- Deleting another user's garment returns 404 and removes nothing

**Implementation Note**: After automated verification passes, pause for manual
confirmation before Phase 3.

---

## Phase 3: Feature tests

### Overview

Add `GarmentRemovalTest` covering the three locked edge-case groups, with faked storage
so media removal is asserted without S3.

### Changes Required:

#### 1. Feature test

**File**: `tests/Feature/GarmentRemovalTest.php`

**Intent**: Lock the contract: happy-path hard-delete (row + media gone + audit written),
cross-tenant protection, and auth/idempotency edges. Mirror `GarmentListingCardTest`
helpers (token, createGarment).

**Contract**: `RefreshDatabase`, `Storage::fake()` for the media disk. Tests:
- `test_owner_deletes_garment` → 204; `assertDatabaseMissing('garments', ['id' => …])`;
  `assertDatabaseMissing('media', ['model_id' => …])`; `assertDatabaseHas('garment_deletions', ['garment_id' => …, 'user_id' => …])`.
- `test_cannot_delete_another_users_garment` → 404; `assertDatabaseHas('garments', ['id' => …])`.
- `test_delete_requires_authentication` → 401 (no token).
- `test_delete_unknown_garment_returns_404` and repeat-delete → 404.

Attach a real photo in the happy-path setup (`Garment::factory()…->addMedia(UploadedFile::fake()->image(...))->toMediaCollection('photos')`) so the media-removal assertion is meaningful.

### Success Criteria:

#### Automated Verification:

- New tests pass: `php artisan test --filter=GarmentRemovalTest`
- Full suite green: `composer test`
- Pint clean: `./vendor/bin/pint --test`

#### Manual Verification:

- Test names read as the behavioural contract; happy-path asserts media + audit, not just status

**Implementation Note**: Final phase — confirm the suite is green before closing.

---

## Testing Strategy

### Unit Tests:

- None — behaviour lives at the HTTP boundary; covered by feature tests.

### Integration Tests:

- `GarmentRemovalTest` runs against in-memory sqlite (`:memory:` per phpunit.xml) with
  `Storage::fake` — real DB delete + cascade + media-row removal, faked S3.

### Manual Testing Steps:

1. Auth as user A, create a garment with a photo, `DELETE /api/garments/{id}` → 204;
   confirm row gone, photo gone, `garment_deletions` row written.
2. As user B, `DELETE` user A's garment → 404; confirm A's garment intact.
3. Repeat the first delete → 404 (already gone).

## Performance Considerations

Negligible — single-row delete + one audit insert + one S3 object delete per call. No
N+1, no scale concern at MVP volumes.

## Migration Notes

Additive: one new table (`garment_deletions`). No change to `garments`. Rollback = drop
the table + remove the endpoint. Existing data unaffected.

## References

- Roadmap slice: `context/foundation/roadmap.md` → `### S-05: Garment removal`
- PRD: FR-005 (`context/foundation/prd.md:77`)
- Ownership + resource pattern: `app/Http/Controllers/Api/GarmentController.php:44`
- Test pattern: `tests/Feature/GarmentListingCardTest.php`
- Lessons: `context/foundation/lessons.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Audit schema

#### Automated

- [x] 1.1 Migration applies: `php artisan migrate:fresh` exits 0 — d4f920a
- [x] 1.2 `garment_deletions` table exists with expected columns — d4f920a
- [x] 1.3 Pint clean: `./vendor/bin/pint --test` — d4f920a
- [x] 1.4 Existing suite green: `composer test` — d4f920a

#### Manual

- [x] 1.5 `GarmentDeletion::create([...])` round-trips with `snapshot` cast to array — d4f920a

### Phase 2: DELETE endpoint

#### Automated

- [x] 2.1 Pint clean: `./vendor/bin/pint --test` — f749a48
- [x] 2.2 DELETE route registered: `php artisan route:list --path=api/garments` — f749a48
- [x] 2.3 Full suite green: `composer test` — f749a48

#### Manual

- [x] 2.4 Owned garment → 204; row + photo removed; audit row present — f749a48
- [x] 2.5 Another user's garment → 404; nothing removed — f749a48

### Phase 3: Feature tests

#### Automated

- [x] 3.1 New tests pass: `php artisan test --filter=GarmentRemovalTest` — 03f7670
- [x] 3.2 Full suite green: `composer test` — 03f7670
- [x] 3.3 Pint clean: `./vendor/bin/pint --test` — 03f7670

#### Manual

- [x] 3.4 Test names read as the behavioural contract; happy-path asserts media + audit — 03f7670
