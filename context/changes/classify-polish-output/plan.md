# Classify Output in Polish — Implementation Plan

## Overview

Make `POST /classify` return a fully Polish listing card for the Polish-speaking mobile
client. This supersedes the earlier "free-text only" plan: **all** display fields go
Polish — `color`, `description` (free text), `category` and `condition` (was English
codes) — while `brand` stays verbatim. `condition` is a validated enum, so localizing it
means changing the canonical `Garment::CONDITIONS` source end-to-end (prompt, normalization,
store + PATCH validation, contract, tests). `category` is not enum-validated, so it is a
prompt + documentation change only.

## Current State Analysis

`/classify` runs through `GarmentClassifierService::classify()`; persisted garments are
validated in `GarmentController`. The enum is a single constant reused at three points.

- `Garment::CONDITIONS = ['new', 'like new', 'good', 'fair', 'worn']`
  (`app/Models/Garment.php:16`) — the one source of truth.
- Validation: `GarmentController::store` (`:98`) and `update` (`:60`) both use
  `Rule::in(Garment::CONDITIONS)`. Localizing the constant auto-localizes both rules.
- Normalization: `GarmentClassifierService::extractFields` (`:78`) does
  `in_array(strtolower($condition), Garment::CONDITIONS, true)`. With PL values this still
  works **except** `strtolower` is ASCII-only — it will not lowercase `Ś` in `"Średni"`, so a
  capitalized AI value would fail the match and become `null`.
- Prompt: `systemPrompt()` (`app/Services/GarmentClassifierService.php`) — partially edited
  in the prior (aborted) Phase 1 run: `color`/`description` already instructed Polish;
  `category`/`condition` currently still pinned to English codes and must flip to Polish.
- Contract: `openapi.json` is **Scramble-generated and gitignored** — the `condition` enum in
  the `store`/`PATCH` request bodies is introspected from `Rule::in(Garment::CONDITIONS)`, so
  it follows the constant automatically on regeneration (no hand-edit). The mobile-side copy
  lives in `implemented-on-api.md`.
- Tests referencing English enum literals: `GarmentStoreTest`, `GarmentListingCardTest`,
  `WardrobeCatalogueTest`, `GarmentSchemaTest`, `GarmentRemovalTest`, `ClassifyEndpointTest`,
  and the new `GarmentClassifierPromptTest` (currently asserts English codes).
- `condition` DB column is a string (`garments` table) — no migration needed to change
  allowed values.

### Key Discoveries:

- One constant (`Garment::CONDITIONS`) drives validation at both write paths; flipping it to
  Polish is the lever, and the two `Rule::in` sites need no edit.
- `strtolower` ASCII gotcha (`GarmentClassifierService:78`) — must become `mb_strtolower` so a
  capitalized Polish AI value (`"Średni"`, `"Nowy"`) still normalizes to the canonical token.
- `category` has no enum constant and `store` treats it as `nullable|string`, so making it
  Polish is purely a prompt + doc change — no validation impact.
- `openapi.json` regenerates from the validation rules; the contract sync is "regenerate +
  verify", not a hand-edit (same mechanism as the classify-via-laravel Phase 4).

## Desired End State

`POST /classify` returns:

- `category`: Polish (`góra` | `dół` | `buty` | `akcesorium` | `okrycie wierzchnie`) or `null`.
- `brand`: verbatim (e.g. `"Zara"`) or `null`.
- `color`: Polish free text or `null`.
- `condition`: Polish canonical code (`nowy` | `jak nowy` | `dobry` | `średni` | `znoszony`)
  or `null`.
- `description`: Polish free text or `null`.

`POST /garments` and `PATCH /garments/{id}` accept and validate the Polish `condition`
values. `openapi.json` shows the Polish `condition` enum. `implemented-on-api.md` documents
the Polish values and drops the EN→PL client-mapping note.

**Verification:** full suite green with Polish enum values; `GarmentClassifierPromptTest`
asserts Polish condition + category tokens; manual `curl` against `/classify` returns a fully
Polish card (brand verbatim); a garment persisted with a Polish `condition` validates and a
PATCH to a Polish `condition` succeeds.

## What We're NOT Doing

- Not supporting `Accept-Language` / multi-locale — Polish is hardcoded.
- Not changing field **names**, nullability, or the response shape (still five fields).
- Not adding a backend PL↔EN translation layer — Polish is now the canonical stored value.
- Not backfilling existing dev rows holding English `condition` (dev-only SQLite; a stale row
  simply won't re-validate on edit — accepted).
- Not localizing `brand`.
- Not editing the mobile client in this repo.

## Critical Implementation Details

- **`mb_strtolower`, not `strtolower`.** `GarmentClassifierService:78` lowercases the AI's
  `condition` before matching `Garment::CONDITIONS`. Polish letters (`Ś`→`ś`) need
  `mb_strtolower($condition, 'UTF-8')`; the ASCII `strtolower` would leave `"Średni"`
  unmatched and null it out.
- **`condition` value mapping** (canonical, stored): `new`→`nowy`, `like new`→`jak nowy`,
  `good`→`dobry`, `fair`→`średni`, `worn`→`znoszony`.

## Phase 1: Localize the enum + prompt

### Overview

Flip the canonical condition enum to Polish, fix normalization for Polish casing, rewrite the
prompt so `condition` and `category` are Polish, and update every test that hard-codes the old
English literals.

### Changes Required:

#### 1. Canonical condition enum

**File**: `app/Models/Garment.php`

**Intent**: Make Polish the canonical, stored, validated set of condition values.

**Contract**: `Garment::CONDITIONS` becomes `['nowy', 'jak nowy', 'dobry', 'średni',
'znoszony']`. Order mirrors the old new→worn quality scale. Both `Rule::in(...)` sites consume
this unchanged.

#### 2. Normalization casing fix

**File**: `app/Services/GarmentClassifierService.php`

**Intent**: Keep condition normalization correct for Polish letters so a capitalized AI value
still matches the canonical token.

**Contract**: In `extractFields()` (`:78`), replace `strtolower($condition)` with
`mb_strtolower($condition, 'UTF-8')`. The `in_array(..., Garment::CONDITIONS, true)` check is
otherwise unchanged.

#### 3. System prompt — condition + category to Polish

**File**: `app/Services/GarmentClassifierService.php`

**Intent**: Instruct the model to return Polish for every field except `brand`, using the new
canonical Polish `condition` codes and Polish `category` labels.

**Contract**: Rewrite `systemPrompt()` so: `category` ∈ `góra / dół / buty / akcesorium /
okrycie wierzchnie` (or null); `condition` ∈ `nowy / jak nowy / dobry / średni / znoszony` (or
null); `color`, `description` in Polish; `brand` verbatim. Keep the JSON-only and
null-not-guess rules. Remove the now-obsolete "return the English code, do not translate"
lines. Optional: the Polish user text message added earlier stays.

#### 4. Prompt-content test

**File**: `tests/Feature/GarmentClassifierPromptTest.php`

**Intent**: Re-point the guard at the Polish contract.

**Contract**: Assert the prompt contains each Polish `condition` token (from
`Garment::CONDITIONS`) and each Polish `category` token (`góra`, `dół`, `buty`, `akcesorium`,
`okrycie wierzchnie`), and that it instructs Polish for `color`/`description`. Drop the
English-code and "do not translate" assertions.

#### 5. Update tests using English enum literals

**File**: `tests/Feature/GarmentStoreTest.php`, `tests/Feature/GarmentListingCardTest.php`,
`tests/Feature/WardrobeCatalogueTest.php`, `tests/Feature/GarmentSchemaTest.php`,
`tests/Feature/GarmentRemovalTest.php`, `tests/Feature/ClassifyEndpointTest.php`

**Intent**: Replace English `condition` literals with the Polish canonical values so the suite
reflects the new contract; the invalid-condition negative test still uses a value outside the
set.

**Contract**: Swap `'good'`/`'like new'`/etc. for the Polish equivalents in valid-path
assertions and fixtures. Keep the `condition` => `422` negative test asserting on a value not
in the Polish set (e.g. `'pristine'` or `'doskonały'`). Where `FakeGarmentClassifier` returns a
canned `condition`, update it to a Polish value.

### Success Criteria:

#### Automated Verification:

- Full suite passes: `composer test`
- Prompt test passes: `php artisan test --filter=GarmentClassifierPromptTest`
- Style clean: `./vendor/bin/pint --test`

#### Manual Verification:

- `curl` `/classify` (real photo + valid `X-App-Key`) returns Polish `category`, `condition`,
  `color`, `description`; `brand` verbatim.
- `POST /garments` with a Polish `condition` persists (no `422`).
- `PATCH /garments/{id}` to a Polish `condition` succeeds; an out-of-set value → `422`.

**Implementation Note**: After automated verification passes, pause for manual confirmation
(needs a live AI call) before Phase 2.

---

## Phase 2: Sync contract + client doc

### Overview

Regenerate the OpenAPI contract from the new validation rules and update the mobile
integration doc to the Polish values.

### Changes Required:

#### 1. Regenerate `openapi.json`

**File**: `openapi.json` (Scramble-generated, gitignored)

**Intent**: Bring the published contract's `condition` enum in line with the Polish constant.

**Contract**: Run `php artisan scramble:export --path=openapi.json`. Verify the `store` and
`PATCH /garments/{garment}` request-body `condition` enums now list the Polish values, and the
`/classify` response documents them too where applicable. No hand-edit — the enum is
introspected from `Rule::in(Garment::CONDITIONS)`.

#### 2. Update integration doc

**File**: `implemented-on-api.md`

**Intent**: Tell the client every field except `brand` is Polish now, with the canonical
`condition` value list — and remove the obsolete EN→PL mapping guidance.

**Contract**: Update the `/classify` and garments sections: `condition` enum →
`nowy / jak nowy / dobry / średni / znoszony`; note `category` is Polish (`góra / dół / buty /
akcesorium / okrycie wierzchnie`), `color`/`description` Polish, `brand` verbatim. Remove the
"enum codes stay English, client maps to PL" note and any EN→PL tables.

### Success Criteria:

#### Automated Verification:

- `openapi.json` valid JSON: `php -r '$d=json_decode(file_get_contents("openapi.json"),true); echo json_last_error()===JSON_ERROR_NONE?"ok":"bad";'`
- Polish enum present: `grep -q "znoszony" openapi.json && grep -q "znoszony" implemented-on-api.md`
- Full suite still green: `composer test`

#### Manual Verification:

- Spec `condition` enum matches `Garment::CONDITIONS` exactly.
- `implemented-on-api.md` reads correctly and no longer tells the client to map EN→PL.

**Implementation Note**: Final phase — confirm contract + doc line up with the Polish constant.

---

## Testing Strategy

### Unit / Feature Tests:

- `GarmentClassifierPromptTest`: prompt carries Polish `condition` + `category` tokens and
  instructs Polish for `color`/`description`.
- `GarmentStoreTest` / `GarmentListingCardTest` / `WardrobeCatalogueTest` / `GarmentSchemaTest`
  / `GarmentRemovalTest` / `ClassifyEndpointTest`: updated to Polish `condition` values; the
  negative `422` case uses an out-of-set value.
- Regression guard: the whole suite must stay green after the enum flip.

### Manual Testing Steps:

1. `curl` `/classify` with a real photo → fully Polish card, `brand` verbatim.
2. `POST /garments` with Polish `condition` → persists; out-of-set `condition` → `422`.
3. `PATCH /garments/{id}` to a Polish `condition` → succeeds.
4. Eyeball `openapi.json` + `implemented-on-api.md` against `Garment::CONDITIONS`.

## Performance Considerations

None — same single synchronous classify call; no added work.

## Migration Notes

No schema migration (string column). Existing dev rows with English `condition` are left as-is
by decision; they read fine but will fail re-validation on edit until manually updated.

## References

- Enum source: `app/Models/Garment.php:16`.
- Validation: `app/Http/Controllers/Api/GarmentController.php:60,98`.
- Normalization + prompt: `app/Services/GarmentClassifierService.php:78`, `systemPrompt()`.
- Contract: `openapi.json` (Scramble), `implemented-on-api.md`.
- Lesson: never emit plausible-but-wrong values — return null (`context/foundation/lessons.md`).

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Localize the enum + prompt

#### Automated

- [x] 1.1 Full suite passes: `composer test` — d8d434f
- [x] 1.2 Prompt test passes: `php artisan test --filter=GarmentClassifierPromptTest` — d8d434f
- [x] 1.3 Style clean: `./vendor/bin/pint --test` — d8d434f

#### Manual

- [x] 1.4 `/classify` returns Polish category/condition/color/description, brand verbatim — d8d434f
- [x] 1.5 `POST /garments` with Polish condition persists (no 422) — d8d434f
- [x] 1.6 `PATCH` to Polish condition succeeds; out-of-set → 422 — d8d434f

### Phase 2: Sync contract + client doc

#### Automated

- [x] 2.1 `openapi.json` valid JSON
- [x] 2.2 Polish enum present in openapi.json + implemented-on-api.md (grep)
- [x] 2.3 Full suite still green: `composer test`

#### Manual

- [x] 2.4 Spec condition enum matches Garment::CONDITIONS
- [x] 2.5 implemented-on-api.md reads correctly, no EN→PL mapping note
