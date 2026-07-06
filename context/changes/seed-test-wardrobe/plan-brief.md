# Seed Test Wardrobe — Plan Brief

> Full plan: `context/changes/seed-test-wardrobe/plan.md`

## What & Why

Test data to verify the app end-to-end without going through `/classify`: an Artisan
command that seeds a fixed test account with ~12 fully classified garments (Polish
canonical values, photos, filled fields) and prints a ready-to-use API token.

## Starting Point

`DatabaseSeeder` only creates a bare user. `GarmentFactory` exists but carries stale EN
categories. Photos go through spatie media library (`photos`, singleFile). The mobile
app can only log into Supabase-linked accounts, so a seeded fake user is verified at
the API level with a minted token.

## Desired End State

`php artisan app:seed-wardrobe` → deterministic wardrobe: 12 garments covering all 5
categories and all 5 conditions, realistic Polish data, null-field cases, `client_ref`s,
one distinct fixture photo each, plus a printed 24 h Sanctum access token. Re-running
wipes and reseeds.

## Key Decisions Made

| Decision   | Choice                            | Why (1 sentence)                                                        |
| ---------- | --------------------------------- | ----------------------------------------------------------------------- |
| Account    | Fixed fake user (`wardrobe-test@example.com`) | Zero-param command; API-level verification is sufficient.  |
| Photos     | Bundled GD-generated fixtures     | Offline-deterministic, visually distinct, replaceable with real photos. |
| Dataset    | ~12 rows, full coverage           | Exercises all categories/conditions + null-handling in one seed.        |
| Mechanism  | Artisan command                   | Self-documenting, callable on any env, parameterizable later.           |
| Re-run     | Wipe & reseed                     | Deterministic state every verification session.                         |
| Access     | Command prints Sanctum token (24 h) | Instant curl/Postman access; no Supabase digging.                      |

## Scope

**In scope:** fixture generator script + 12 committed JPEGs, curated dataset, seed
command with wipe/reseed + token output, feature test.

**Out of scope:** Supabase identity for the test user (no mobile-app UI login),
fixing `GarmentFactory` EN drift, pagination-scale dataset, `DatabaseSeeder` changes.

## Architecture / Approach

Single self-contained console command with the dataset inline; fixtures under
`database/seeders/fixtures/` mapped positionally to rows (`NN-<slug>.jpg`). Wipe uses
per-model `forceDelete()` (SoftDeletes + spatie media cleanup); attach uses
`preservingOriginal()` so fixtures survive runs.

## Phases at a Glance

| Phase                            | What it delivers                              | Key risk                                        |
| -------------------------------- | --------------------------------------------- | ----------------------------------------------- |
| 1. Fixture assets + dataset      | 12 distinct JPEGs + curated 12-row dataset    | GD text rendering quality (cosmetic only)       |
| 2. Seed command + feature test   | Runnable `app:seed-wardrobe` + green test     | Wipe leaving media orphans if not per-model forceDelete |

**Prerequisites:** migrated local DB; ext-gd (bundled).
**Estimated effort:** ~1 session, 2 phases.

## Open Risks & Assumptions

- Local `photo_url` resolution depends on the media disk config — manual curl check
  verifies; if URLs don't resolve locally, that's a config finding, not a seeder bug.
- Assumes API-level verification is enough (no mobile UI login for this account).

## Success Criteria (Summary)

- One command produces a complete, deterministic, fully classified test wardrobe.
- Printed token immediately works against `GET /api/garments` (12 rows, photo URLs).
- Re-run resets state; suite + Pint stay green.
