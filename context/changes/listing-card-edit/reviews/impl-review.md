<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Listing Card Review / Edit

- **Plan**: `context/changes/listing-card-edit/plan.md`
- **Scope**: Full plan (Phase 1 + Phase 2)
- **Date**: 2026-06-01
- **Verdict**: APPROVED (all findings fixed during triage)
- **Findings**: 0 critical  2 warnings  3 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING → FIXED |
| Architecture | PASS |
| Pattern Consistency | WARNING → FIXED |
| Success Criteria | PASS |

## Findings

### F1 — fresh() returns nullable, fatal under strict_types

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `app/Http/Controllers/Api/GarmentController.php:42`
- **Detail**: `$garment->fresh()` returns `Garment|null`. With `declare(strict_types=1)` and `garmentResource(Garment $garment)` typed strictly, a null return causes a TypeError at runtime.
- **Fix**: Replaced `$garment->fresh()` with `$garment->refresh()` — mutates in place, never null.
- **Decision**: FIXED

### F2 — IDOR guard on update() is untested

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Pattern Consistency
- **Location**: `tests/Feature/GarmentListingCardTest.php` — missing test
- **Detail**: `show()` had an ownership test but `update()` did not. An IDOR regression on PATCH would go undetected.
- **Fix**: Added `test_update_returns_404_for_another_users_garment`.
- **Decision**: FIXED

### F3 — classify() response shape diverges from garmentResource()

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `app/Http/Controllers/Api/GarmentController.php:66–75`
- **Detail**: `classify()` returned 8 fields; `garmentResource()` returns 9 (adds `updated_at`). Two shapes for one resource.
- **Fix**: Refactored `classify()` to call `$this->garmentResource($garment)`. POST now returns 9 fields.
- **Decision**: FIXED

### F4 — condition allowed set duplicated in two places

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Architecture
- **Location**: `app/Services/GarmentClassifierService.php` + `GarmentController.php:36`
- **Detail**: Five condition values hardcoded in both the classifier service and the PATCH validation rule.
- **Fix**: Extracted to `Garment::CONDITIONS`; both now reference the constant. Also fixed `GarmentFactory` which had stale values (`like_new`, `poor`).
- **Decision**: FIXED

### F5 — Garment factory not used in tests

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `tests/Feature/GarmentListingCardTest.php:14–25`
- **Detail**: `createGarment()` used manual model instantiation while siblings use `Garment::factory()`.
- **Fix**: Refactored helper to `Garment::factory()->for($user)->create([...])`.
- **Decision**: FIXED
