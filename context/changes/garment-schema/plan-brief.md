---
change_id: garment-schema
plan_version: 1
complexity: LOW
phases: 2
estimated_files_changed: 4
---

# Garment Schema (F-02) — Plan Brief

> Full plan: `context/changes/garment-schema/plan.md`

## What & Why

Add `garments` table with listing card fields + soft delete. F-02 is a pure foundation — no API endpoints, just schema + model. S-02, S-04, S-05 cannot build without this.

## Desired End State

`Garment::factory()->create(['category' => null])` works. Soft-delete hides records from default queries. `GarmentSchemaTest` passes. Model ready for S-02 to build on.

## Key Decisions Made

| Decision | Choice | Why |
|---|---|---|
| Classification fields nullability | All nullable | AI returns null for uncertain fields (lessons.md rule) |
| `condition` column type | string (varchar) | Enum adds migration overhead; validation belongs in service layer |
| `photo_path` nullability | nullable | Flexibility for edge cases and testing |
| Scope | Migration + model + factory + smoke test | S-02 needs model immediately; factory needed for tests |

## Scope

**In scope:** `garments` migration, `Garment` model (SoftDeletes), `GarmentFactory`, `GarmentSchemaTest`

**Out of scope:** API endpoints, AI classification logic, `User::hasMany(Garment::class)` relation, photo upload

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Migration + model + factory | `garments` table live, model wired, factory ready | None — straightforward schema |
| 2. Smoke test + style | 2-test suite passes, pint clean | None |

**Prerequisites:** F-01 (auth-scaffold) done — `users` table exists for FK

## Success Criteria

- `php artisan test --filter=GarmentSchemaTest` → 2 PASS
- `Garment::factory()->create(['category' => null])` → no DB error
- `$garment->delete()` → `deleted_at` set, record hidden from default scope
