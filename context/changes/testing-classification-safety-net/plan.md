# AI Classification Safety Net Implementation Plan

## Overview

Rollout Phase 1 of `context/foundation/test-plan.md` ("AI classification safety net"), covering Risk #1: the AI classifier returning a plausible-but-wrong value instead of `null`, which the user then trusts and lists. This plan closes the two live defects research surfaced in the real parse/normalize path, then locks the guardrail behind a direct unit-test suite that drives malformed AI responses through the *real* `GarmentClassifierService` at the `Http::fake()` seam — the path that today has **zero** direct coverage.

## Current State Analysis

The parse/normalize boundary is `GarmentClassifierService::classify()` → `extractFields()` → `nullableString()` (`app/Services/GarmentClassifierService.php:16-92`). Transport and type failures are handled cleanly (every exception maps to 504/502; non-string field types collapse to `null`). Two defects break the PRD guardrail, and the entire real path is untested:

- **Category leak (`app/Services/GarmentClassifierService.php:75`)** — `extractFields()` enum-guards only `condition` against `Garment::CONDITIONS` (`:78-80`). `category` passes any non-empty string verbatim via `nullableString()`, despite the system prompt (`:99`) restricting it to 5 Polish values. A hallucinated `category => "sukienka"` leaks straight to the user. This is the exact Risk #1 failure.
- **Whitespace condition false-negative (`app/Services/GarmentClassifierService.php:85-92`)** — `nullableString()` never `trim()`s, so `" dobry "` fails the strict `in_array` check (`:78`) and drops to `null`. Fails safe (no wrong value) but silently discards a confident, valid value.
- **Zero direct coverage** — all 9 classify tests (`tests/Feature/ClassifyEndpointTest.php:31-135`) bind `FakeGarmentClassifier` at the container level, which returns canned arrays and never executes `classify()`/`extractFields()`/`nullableString()`. `Http::fake()` appears once in the whole suite (`tests/Feature/SupabaseJwtVerifierTest.php:44-47`) — the reference pattern exists but is not applied to the classifier.

## Desired End State

- `Garment::CATEGORIES` const exists (Polish 5-value allow-list); `extractFields()` enum-guards `category` exactly as it guards `condition`; an out-of-enum plausible `category` returns `null`.
- `condition` normalization `trim()`s before the enum check, so `" dobry "` → `"dobry"`.
- A new unit test file wires the **real** `GarmentClassifierService` against `Http::fake()`, driving research shapes 3–11 plus the two fixed defects, asserting each field against the **PRD null-not-wrong rule** (not the parser's output). All tests green.
- `test-plan.md` §3 Phase 1 status = `complete`; §6.1 cookbook filled; §5 quality-gate line for classifier-normalization confirmed.

**Verify:** `composer test` green (existing 25 + new); `./vendor/bin/pint --test` clean; `php artisan test --filter=GarmentClassifierSafetyNet` exercises the real service (no `FakeGarmentClassifier` binding present in the new file).

### Key Discoveries:

- Enum-guard pattern to mirror: `app/Services/GarmentClassifierService.php:78-80` (`condition` against `Garment::CONDITIONS` with `mb_strtolower(..., 'UTF-8')` for Polish chars).
- Const pattern to mirror: `app/Models/Garment.php:18` (`public const CONDITIONS = [...]`).
- `Http::fake()` seam + scripting a response body: `tests/Feature/SupabaseJwtVerifierTest.php:44-47`. For the classifier, script `choices.0.message.content` as any string to drive shapes 3–11 deterministically.
- Oracle discipline (research Architecture Insights): assert each field against the PRD rule, never against what the parser emits — the category-leak finding only surfaces because the oracle is the allow-list.
- Governing rule: `context/foundation/lessons.md` — "Nigdy nie generuj wartości plausible-but-wrong; zwróć null."

## What We're NOT Doing

- **No `color` allow-list.** PRD gives `color` no fixed value set (free-text by design); there is no "wrong" color to guard against — not a Risk #1 leak.
- **No `brand`/`description` guards** — legitimately free-text.
- **Not touching the controller exception→HTTP mapping** (`ClassifyController.php:43-47`) — already covered and correct.
- **Not removing or rewriting the existing `FakeGarmentClassifier`-bound endpoint tests** — they cover the controller contract, a different seam.
- **No integration/authz/abuse/422 tests** — those are test-plan §3 Phases 2–3.
- **No hooks/CI gate wiring** — that is §3 Phase 4.

## Implementation Approach

Fix the two defects first (Phase 1) so the oracle tests land green rather than red/skipped — shipping a known leak behind a red test violates the lessons.md guardrail. Then add the direct unit suite (Phase 2) that would have caught both defects, wiring the real service at the HTTP seam. Finally update the test-plan artifact (Phase 3) to reflect the phase is complete and record the cookbook pattern for the next author.

## Phase 1: Harden the classifier

### Overview

Close the category leak and the whitespace false-negative with minimal changes that mirror the existing `condition` pattern.

### Changes Required:

#### 1. Category allow-list constant

**File**: `app/Models/Garment.php`

**Intent**: Add a canonical Polish category value set so `category` can be enum-guarded like `condition`. Sources the 5 values from the system prompt (`GarmentClassifierService.php:99`).

**Contract**: `public const CATEGORIES = ['góra', 'dół', 'buty', 'akcesorium', 'okrycie wierzchnie'];` — sibling of the existing `CONDITIONS` const (`Garment.php:18`).

#### 2. Enum-guard `category` in normalization

**File**: `app/Services/GarmentClassifierService.php`

**Intent**: Apply the same allow-list gate to `category` that `condition` already has, so an out-of-enum plausible string returns `null` instead of passing verbatim.

**Contract**: In `extractFields()` (`:70-83`), `category` resolves through `nullableString()` then a strict `in_array(mb_strtolower(...), Garment::CATEGORIES, true)` check — matched → the lowercased value, else `null`. Mirror the `condition` expression at `:78-80`.

#### 3. Trim before condition enum check

**File**: `app/Services/GarmentClassifierService.php`

**Intent**: Recover a valid but whitespace-padded condition (`" dobry "`) that currently drops to `null`.

**Contract**: `trim()` the value before the `in_array` comparison in the `condition` (and `category`) normalization path. Do not alter `nullableString()`'s null/empty-string/`'null'` short-circuit semantics.

### Success Criteria:

#### Automated Verification:

- Pint clean: `./vendor/bin/pint --test`
- Full suite green (no regressions): `composer test`

#### Manual Verification:

- `Garment::CATEGORIES` values match the system prompt's category list verbatim (`GarmentClassifierService.php:99`).
- Diff touches only `Garment.php` + `GarmentClassifierService.php`.

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation before proceeding.

---

## Phase 2: Safety-net unit tests

### Overview

Add a direct unit suite that exercises the real `GarmentClassifierService` through `Http::fake()`, proving every malformed AI shape resolves to `null` for the affected field and every valid value survives — oracle = PRD rule.

### Changes Required:

#### 1. New unit test file for the real classifier path

**File**: `tests/Feature/GarmentClassifierSafetyNetTest.php` (new)

**Intent**: Instantiate the real `GarmentClassifierService` (no container `instance()` binding), fake the OpenRouter HTTP edge, script `choices.0.message.content` per shape, call `classify()`, and assert the returned array against the PRD null-not-wrong oracle.

**Contract**: `extends Tests\TestCase`. A helper fakes `config('services.openrouter.base_url').'/chat/completions'` with a `Http::response(['choices' => [['message' => ['content' => $content]]]])` where `$content` is the scripted (possibly malformed) JSON string. Config for `services.openrouter.*` set in `setUp()`. Each test calls `(new GarmentClassifierService)->classify($b64, $mime)` and asserts on the returned field. Covers, at minimum, the research per-shape table:

- Shape 6 — missing field → `null`.
- Shape 7 — wrong-typed field (`category => 123`, `brand => [...]`, `description => {}`) → `null`.
- Shape 8 — `condition` valid string out-of-enum (`"doskonały"`) → `null`.
- Shape 9a — `condition => "Dobry"` (case) → `"dobry"`.
- Shape 9b — `condition => " dobry "` (whitespace, **Phase 1 fix**) → `"dobry"`.
- Shape 10 — field `""` / literal `"null"` → `null`.
- Shape 11 — **category leak (Phase 1 fix)**: `category => "sukienka"` (plausible, out-of-enum) → `null`; and a valid `category => "buty"` → `"buty"`.
- Happy path — all 5 valid fields survive verbatim (lowercased where enum-guarded).

**Contract note (exception shapes)**: Shapes 1–5 (timeout, non-2xx, non-string content, non-JSON content, non-array parse) already have controller-level coverage; assert them here at the service level via `expectException(ClassifierTimeoutException::class)` / `ClassifierUpstreamException::class` only where it strengthens the service oracle — do not duplicate the controller's HTTP-status assertions.

### Success Criteria:

#### Automated Verification:

- New suite passes and hits the real service: `php artisan test --filter=GarmentClassifierSafetyNet`
- Full suite green: `composer test`
- Pint clean: `./vendor/bin/pint --test`

#### Manual Verification:

- The new file contains no `FakeGarmentClassifier` / `$this->app->instance(GarmentClassifier::class, ...)` binding — confirm it drives the real parse path.
- Every assertion checks against the PRD allow-list / null rule, not against a value copied from the parser's own output.
- Removing the Phase 1 fixes makes shapes 9b and 11 fail (spot-check by reverting locally if unsure) — confirms the tests actually guard the defects.

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation before proceeding.

---

## Phase 3: Test-plan wiring

### Overview

Reflect the completed phase in the durable test-plan artifact so the orchestrator and future authors see accurate state.

### Changes Required:

#### 1. Flip Phase 1 status

**File**: `context/foundation/test-plan.md`

**Intent**: Mark §3 Phase 1 row status `complete` (from `researched`).

**Contract**: §3 Phased Rollout table, row 1, Status column → `complete`.

#### 2. Fill cookbook §6.1

**File**: `context/foundation/test-plan.md`

**Intent**: Replace the §6.1 "TBD" with the concrete pattern this phase established.

**Contract**: §6.1 documents: real `GarmentClassifierService` (not the fake) + `Http::fake()` on the OpenRouter endpoint, scripting `choices.0.message.content`, oracle = PRD null-not-wrong; reference test `tests/Feature/GarmentClassifierSafetyNetTest.php`; run via `php artisan test --filter=GarmentClassifierSafetyNet`.

#### 3. Confirm quality-gate line

**File**: `context/foundation/test-plan.md`

**Intent**: Ensure §5's "unit on classifier normalization … required after §3 Phase 1" row reflects that the gate is now live.

**Contract**: §5 Quality Gates table — verify/annotate the classifier-normalization row; no wording change needed beyond confirming it is enforced.

### Success Criteria:

#### Automated Verification:

- Markdown intact (no broken table): `git diff --stat context/foundation/test-plan.md` shows only the intended edits.

#### Manual Verification:

- §3 Phase 1 reads `complete`; §6.1 no longer says "TBD"; §5 classifier row is accurate.

**Implementation Note**: This phase edits docs only; no code gate. Confirm the table renders before closing.

---

## Testing Strategy

### Unit Tests:

- Real `GarmentClassifierService` + `Http::fake()` covering research shapes 6–11 and the happy path (see Phase 2 contract).
- Oracle is always the PRD rule (out-of-enum/garbage → `null`; valid → the value), never the parser's emitted value.

### Integration Tests:

- None in this phase — the endpoint contract is covered by the existing `FakeGarmentClassifier`-bound tests; integration authz/abuse/422 is §3 Phase 2.

### Manual Testing Steps:

1. `composer test` — full suite green.
2. `php artisan test --filter=GarmentClassifierSafetyNet` — new suite green and exercising the real service.
3. Revert Phase 1 fixes locally → confirm shapes 9b + 11 fail (the tests bite); restore.

## References

- Research: `context/changes/testing-classification-safety-net/research.md`
- Test plan (Phase 1 source): `context/foundation/test-plan.md` §2 Risk #1, §3, §6.1
- Enum-guard pattern: `app/Services/GarmentClassifierService.php:78-80`
- Const pattern: `app/Models/Garment.php:18`
- `Http::fake()` reference: `tests/Feature/SupabaseJwtVerifierTest.php:44-47`
- Governing rule: `context/foundation/lessons.md` (never plausible-but-wrong)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Harden the classifier

#### Automated

- [x] 1.1 Pint clean: `./vendor/bin/pint --test`
- [x] 1.2 Full suite green (no regressions): `composer test`

#### Manual

- [x] 1.3 `Garment::CATEGORIES` values match the system prompt's category list verbatim
- [x] 1.4 Diff touches only `Garment.php` + `GarmentClassifierService.php`

### Phase 2: Safety-net unit tests

#### Automated

- [ ] 2.1 New suite passes and hits the real service: `php artisan test --filter=GarmentClassifierSafetyNet`
- [ ] 2.2 Full suite green: `composer test`
- [ ] 2.3 Pint clean: `./vendor/bin/pint --test`

#### Manual

- [ ] 2.4 New file contains no `FakeGarmentClassifier` binding — drives the real parse path
- [ ] 2.5 Every assertion checks against the PRD allow-list / null rule, not parser output
- [ ] 2.6 Reverting Phase 1 fixes makes shapes 9b and 11 fail — tests guard the defects

### Phase 3: Test-plan wiring

#### Automated

- [ ] 3.1 Markdown intact: `git diff --stat context/foundation/test-plan.md` shows only intended edits

#### Manual

- [ ] 3.2 §3 Phase 1 reads `complete`; §6.1 no longer says "TBD"; §5 classifier row accurate
