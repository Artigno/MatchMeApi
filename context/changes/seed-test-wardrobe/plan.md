# Seed Test Wardrobe Implementation Plan

## Overview

Add an Artisan command `app:seed-wardrobe` that provisions a fixed test account with a
fully classified wardrobe: ~12 garments with realistic Polish listing-card data, distinct
photos, and a printed long-lived Sanctum access token. Purpose: verify app behavior
(list, detail, edit, delete, sync flows) via the API without going through `/classify`.

## Current State Analysis

- `DatabaseSeeder` (database/seeders/DatabaseSeeder.php:20) seeds only a bare
  `test@example.com` user — no garments, no photos.
- `GarmentFactory` (database/factories/GarmentFactory.php:18) is stale: EN categories
  (`top`, `bottom`, …) predating the Polish canonical value sets in
  `Garment::CATEGORIES` / `Garment::CONDITIONS` (app/Models/Garment.php:18-22).
  Existing tests pass explicit values, so the drift is dormant.
- Photos live in spatie media library, collection `photos`, `singleFile()`
  (app/Models/Garment.php:32-35). Attach pattern used across tests:
  `$garment->addMedia($file)->toMediaCollection('photos')`.
- `garments` now carries `client_ref` (unique per user) — seeded rows should set it to
  mimic app-created rows.
- Auth: the mobile app reaches an account only via Supabase exchange
  (`firstOrCreate(['supabase_id' => sub])`, app/Http/Controllers/Api/SupabaseController.php).
  A seeded fake user has no Supabase identity → verification is API-level (curl/Postman)
  with a token the command prints. Token pattern:
  `$user->createToken('access', ['access'], $expiry)->plainTextToken` (tests + IssuesTokenPairs).
- `sample.jpeg` (repo root, 1024×572) exists but a wardrobe of identical photos is weak
  for visual verification — decision: distinct bundled fixtures.

## Desired End State

Running `php artisan app:seed-wardrobe` on a migrated database:

1. Ensures the fixed test user exists (`wardrobe-test@example.com`).
2. Deletes the user's existing garments **and their media** (wipe & reseed — deterministic).
3. Creates ~12 garments with curated Polish data covering all 5 categories, all 5
   conditions, null-field cases, and per-row `client_ref` values.
4. Attaches a distinct fixture JPEG to every garment.
5. Prints a summary table + a fresh long-lived (24 h) Sanctum access token.

`GET /api/garments` with the printed token returns the full wardrobe with working
`photo_url`s. Re-running the command yields identical state (fresh ids, same shape).

### Key Discoveries:

- Media attach + `singleFile` pattern: tests/Feature/GarmentRemovalTest.php:35
- Token minting with abilities + expiry: tests/Feature/GarmentStoreTest.php:24
- Wipe must use `forceDelete()` — model is `SoftDeletes`; only a real delete triggers
  spatie media cleanup (app/Http/Controllers/Api/GarmentController.php destroy comment).
- Canonical value sets: `Garment::CATEGORIES`, `Garment::CONDITIONS` — seed data must
  reference the constants, not string literals, so future value-set changes fail loudly.

## What We're NOT Doing

- No Supabase identity for the test user — mobile-app UI login to this account is out of
  scope (API-level verification only). A `--supabase-id` option can be added later.
- Not fixing `GarmentFactory`'s stale EN categories — dormant drift, separate cleanup.
- Not seeding data for real user accounts (decision: fixed fake user).
- No pagination-scale dataset (25+) — 12 rows fits one page by design.
- No changes to production `DatabaseSeeder` behavior.

## Implementation Approach

A single self-contained console command holding the curated dataset inline (an array of
~12 rows). Fixture photos are small deterministic JPEGs committed under
`database/seeders/fixtures/` — generated once with GD (solid background color matching
the garment's `color`, category label text) so they are visually distinct in any client,
offline-safe, and replaceable with real photos later without code changes (the command
globs the fixtures directory). Wipe & reseed keeps every run deterministic.

## Critical Implementation Details

**Wipe must mirror the API's delete semantics.** `Garment` uses `SoftDeletes`;
`delete()` would soft-delete and leave media rows + files behind. The wipe step must
`forceDelete()` each row (loop, not mass query — media cleanup hooks fire per model).
No `GarmentDeletion` audit rows for the wipe — this is a maintenance operation, not a
user-initiated delete.

**Fixture ↔ garment mapping is positional, not random.** Fixtures are named
`NN-<slug>.jpg` and the dataset references filenames explicitly, so the photo matches
the garment's description (e.g. blue top gets the blue-top fixture). `addMedia()` moves
the source file by default — use `preservingOriginal()` so fixtures survive the run.

## Phase 1: Fixture assets + curated dataset

### Overview

Produce the committed fixture images and define the 12-garment dataset.

### Changes Required:

#### 1. Fixture generator script (one-shot, committed for regeneration)

**File**: `database/seeders/fixtures/generate.php`

**Intent**: Standalone PHP-GD script that renders one small JPEG per dataset row
(~640×640, solid background in the garment's color, centered category + brand label
text) and writes `database/seeders/fixtures/NN-<slug>.jpg`. Committed so fixtures can
be regenerated or extended; run once during this phase to produce the images.

**Contract**: Running `php database/seeders/fixtures/generate.php` (re)creates all
`NN-*.jpg` files deterministically. Requires only ext-gd (bundled with the project's
PHP). Each output ≤ ~40 KB.

#### 2. Fixture images (generated output, committed)

**File**: `database/seeders/fixtures/01-*.jpg` … `12-*.jpg`

**Intent**: 12 distinct JPEGs, one per dataset row, named positionally to match the
dataset entries. Total footprint well under 0.5 MB.

**Contract**: Filename pattern `NN-<slug>.jpg` where `NN` = dataset row index (01–12).

#### 3. Curated dataset definition

**File**: `app/Console/Commands/SeedWardrobe.php` (dataset lives inline in the command —
created here as data, wired to execution in Phase 2)

**Intent**: A constant/method returning 12 rows of realistic Polish listing-card data.
Coverage matrix: every `Garment::CATEGORIES` value appears ≥ 2× (5 categories × 2 = 10,
plus 2 extra on popular categories); every `Garment::CONDITIONS` value appears ≥ 1×;
2 rows carry `null` in some optional fields (one `brand: null`, one
`description: null` + `color: null`); every row has `client_ref` = `seed-<NN>-<slug>`;
brands mix real-world names (Zara, H&M, Nike, 4F, Reserved…); colors and descriptions
in natural Polish, description style mirroring the classifier's output register
(1–2 sentences, e.g. "Ładny niebieski top w dobrym stanie.").

**Contract**: Each row: `[client_ref, category, brand, color, condition, description,
fixture]`. `category` / `condition` values referenced via `Garment::CATEGORIES` /
`Garment::CONDITIONS` constants, never string literals.

### Success Criteria:

#### Automated Verification:

- Fixture regeneration works: `php database/seeders/fixtures/generate.php` exits 0
- 12 fixture JPEGs exist: `ls database/seeders/fixtures/*.jpg | wc -l` → 12
- Dataset compiles: `php -l app/Console/Commands/SeedWardrobe.php`

#### Manual Verification:

- Open a few fixtures — visually distinct, label readable, color plausibly matches the row

---

## Phase 2: Seed command + feature test

### Overview

Wire the dataset into a runnable `app:seed-wardrobe` command with wipe-&-reseed
semantics and token output; cover it with a feature test.

### Changes Required:

#### 1. Console command execution logic

**File**: `app/Console/Commands/SeedWardrobe.php`

**Intent**: `php artisan app:seed-wardrobe` — ensure test user
(`User::firstOrCreate(['email' => 'wardrobe-test@example.com'], …)`), wipe existing
garments via per-model `forceDelete()`, create the 12 garments, attach each row's
fixture with `preservingOriginal()`, mint a fresh `access`-ability token with 24 h
expiry, print a summary (count, categories) + the plaintext token + a ready-to-paste
curl example.

**Contract**: Signature `app:seed-wardrobe`. Exit 0 on success; non-zero with a clear
message when the fixtures directory is empty/missing. Deletes the user's previous
tokens named `seed-access` before minting a new one (no token pile-up), token name
`seed-access`.

#### 2. Feature test

**File**: `tests/Feature/SeedWardrobeCommandTest.php`

**Intent**: Verify the command's contract: creates user + 12 garments each with one
media item; covers all categories and conditions; second run leaves exactly 12 garments
(wipe works, no media orphans in the `media` table); output contains a usable token
(assert via authenticated `GET /api/garments` with the token extracted from output, or
by asserting the token row exists with `access` ability).

**Contract**: Uses `RefreshDatabase` + `Storage::fake('local')` like sibling tests;
runs the command via `$this->artisan('app:seed-wardrobe')`.

### Success Criteria:

#### Automated Verification:

- Full suite green: `composer test`
- Style clean: `./vendor/bin/pint --test`
- Command runs on a fresh local DB: `php artisan migrate:fresh && php artisan app:seed-wardrobe`

#### Manual Verification:

- `curl -H "Authorization: Bearer <printed token>" -H "Accept: application/json" http://localhost:8000/api/garments` returns 12 garments with non-empty `photo_url`s
- A `photo_url` opens in the browser and shows the expected fixture image
- Re-run command → wardrobe reset, new token works, old token rejected

**Implementation Note**: After completing this phase and all automated verification
passes, pause for manual confirmation that the curl verification succeeded.

---

## Testing Strategy

### Unit Tests:

- None — logic is thin orchestration; feature test covers the contract.

### Integration Tests:

- `SeedWardrobeCommandTest`: creation, coverage matrix, media attach, wipe-on-rerun,
  token usability (see Phase 2).

### Manual Testing Steps:

1. `php artisan app:seed-wardrobe` on local dev DB.
2. `GET /api/garments` with printed token — 12 rows, Polish values, photo URLs resolve.
3. `PATCH /api/garments/{id}` with the token — edit works on seeded row.
4. Re-run seed — state resets.

## Performance Considerations

None — 12 rows, 12 small files, local command.

## Migration Notes

No schema changes. Command is additive; safe on any env with migrations applied.
Do NOT run against production data with a real user's email (fixed fake email guards this).

## References

- Media attach pattern: `tests/Feature/GarmentRemovalTest.php:35`
- Token minting: `tests/Feature/GarmentStoreTest.php:24`
- forceDelete + media cleanup rationale: `app/Http/Controllers/Api/GarmentController.php` (destroy)
- Canonical value sets: `app/Models/Garment.php:18-22`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Fixture assets + curated dataset

#### Automated

- [x] 1.1 Fixture regeneration works: `php database/seeders/fixtures/generate.php` exits 0 — 5201452
- [x] 1.2 12 fixture JPEGs exist: `ls database/seeders/fixtures/*.jpg | wc -l` → 12 — 5201452
- [x] 1.3 Dataset compiles: `php -l app/Console/Commands/SeedWardrobe.php` — 5201452

#### Manual

- [x] 1.4 Fixtures visually distinct, labels readable, colors match rows — 5201452

### Phase 2: Seed command + feature test

#### Automated

- [ ] 2.1 Full suite green: `composer test`
- [ ] 2.2 Style clean: `./vendor/bin/pint --test`
- [ ] 2.3 Command runs on fresh DB: `php artisan migrate:fresh && php artisan app:seed-wardrobe`

#### Manual

- [ ] 2.4 curl with printed token returns 12 garments with working `photo_url`s
- [ ] 2.5 `photo_url` opens in browser showing the fixture image
- [ ] 2.6 Re-run resets wardrobe; new token works, old token rejected
