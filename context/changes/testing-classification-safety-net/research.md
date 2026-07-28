---
date: 2026-06-23T08:43:52+0200
researcher: Hubert Krzysztofiak
git_commit: e4ab1cfb09ab9d99b306d728399d1527e6e2c724
branch: staging
repository: api
topic: "AI classification safety net — does malformed AI output become null, never a guess? (Risk #1)"
tags: [research, codebase, classification, GarmentClassifierService, test-plan-phase-1]
status: complete
last_updated: 2026-06-23
last_updated_by: Hubert Krzysztofiak
---

# Research: AI classification safety net (test-plan Risk #1)

**Date**: 2026-06-23T08:43:52+0200
**Researcher**: Hubert Krzysztofiak
**Git Commit**: e4ab1cf
**Branch**: staging
**Repository**: api

## Research Question

Ground rollout Phase 1 of `context/foundation/test-plan.md` ("AI classification safety net").
Risk #1: AI classification returns a plausible-but-wrong value instead of `null`; the user
trusts it and lists garbage. Verify (not blindly accept) the response intent: prove
malformed/partial/out-of-enum/wrong-typed AI JSON yields `null` for the affected field, never
a guessed value — oracle = PRD null-not-wrong rule, not the parser output. Find where the AI
response is parsed/normalized, which malformed shapes are handled vs not, where the
`Http::fake()` edge sits, and what existing tests cover.

## Summary

The parse/normalize boundary is `GarmentClassifierService::classify()` →
`extractFields()` → `nullableString()` (`app/Services/GarmentClassifierService.php:16-92`).
It handles the **transport** and **type** failure classes well: every exception path maps
cleanly to 504/502, and non-string field types collapse to `null`. But two real defects
break the PRD guardrail, and the entire real path has **zero direct test coverage**:

1. **The genuine leak — `category` and `color` are not enum-validated.** Only `condition`
   is checked against an allow-list (`Garment::CONDITIONS`). `category`, `color`, `brand`,
   `description` pass through **any non-empty string verbatim**. A model-hallucinated
   `category => "sukienka"` or `color => "morski w grochy"` — plausible but outside the 5
   categories the system prompt restricts to — **leaks straight through** to the user. This
   is exactly the Risk #1 failure: plausible-but-wrong instead of `null`. The "must
   challenge" assumption from the test plan ("valid-looking JSON means the fields are
   trustworthy") is **confirmed false** for category/color.

2. **Whitespace condition → silent null (false-negative).** `" dobry "` (a valid value with
   padding) is dropped to `null` because there is no `trim()` before the strict `in_array`
   check. Fails safe (no wrong value), but a confident value becomes empty.

3. **Zero direct coverage.** All 9 classify tests bind `FakeGarmentClassifier` at the
   container level, which returns pre-baked arrays and **never executes** the real
   parse/normalize logic. No test in the suite wires the real `GarmentClassifierService`
   against `Http::fake()`. The oracle problem is live: today nothing proves the real path
   obeys the guardrail.

## Detailed Findings

### Parse/normalize control flow

`classify()` (`app/Services/GarmentClassifierService.php:16-68`):
- `Http::...->timeout(25)->post(...)` wrapped in try/catch for `ConnectionException` →
  `ClassifierTimeoutException` (`:46-49`).
- `! $response->successful()` → `ClassifierUpstreamException` (`:51-53`).
- `$content = $response->json('choices.0.message.content')`; `! is_string($content)` →
  `ClassifierUpstreamException` (`:55-59`).
- `$parsed = json_decode($content, true)`; `! is_array($parsed)` →
  `ClassifierUpstreamException` (`:61-65`).
- `extractFields($parsed)` (`:70-83`).

`nullableString()` (`:85-92`): returns `null` for `null`, `''`, or literal `'null'`; otherwise
returns the value **only if `is_string`**, else `null`. **Does NOT trim.**

`condition` (`:78-80`): `nullableString` first, then
`in_array(mb_strtolower($condition, 'UTF-8'), Garment::CONDITIONS, true)` (strict). Matched →
stores the **lowercased** value; else `null`. `mb_strtolower(..., 'UTF-8')` is correct for
Polish chars (`średni`). Applied **only** to condition.

Controller exception → HTTP mapping (`app/Http/Controllers/Api/ClassifyController.php:43-47`):
`ClassifierTimeoutException` → 504, `ClassifierUpstreamException` → 502.

### Per-shape handling table (oracle = PRD null-not-wrong)

| # | Malformed shape | Location | Result | Verdict |
|---|------------------|----------|--------|---------|
| 1 | ConnectionException / timeout | Service:46-49 → Ctrl:43 | exception → 504 | OK |
| 2 | non-2xx HTTP status | Service:51-53 → Ctrl:45 | exception → 502 | OK |
| 3 | `content` not a string (missing/array/null) | Service:55-59 | exception → 502 | OK |
| 4 | `content` not valid JSON | Service:61-65 | exception → 502 | OK |
| 5 | content parses to non-array (number/string/bool/JSON null) | Service:63-65 | exception → 502 | OK |
| 6 | parsed array missing a field | Service:72,75-81 (`?? null`) | null | OK |
| 7 | field wrong type (`category=>123`, `brand=>[...]`, `description=>{}`) | Service:91 (`is_string`) | null | OK |
| 8 | `condition` valid string out-of-enum (`"good"`, `"doskonały"`) | Service:78-80 | null | OK |
| 9a | `condition => "Dobry"` (wrong case) | Service:78-79 | value `"dobry"` | OK (case-insensitive by design) |
| 9b | `condition => " dobry "` (whitespace) | Service:78-80 | **null** | **FALSE-NEGATIVE** — no trim; valid value dropped |
| 10 | field `""` or literal `"null"` | Service:87 | null | OK |
| 11 | **`category`/`color` out-of-enum plausible string** (`"sukienka"`) | Service:75-77,91 | **value passes verbatim** | **LEAK** — violates guardrail |

### The leak in detail

`extractFields()` (`:70-83`) applies enum validation to `condition` only. `category`, `color`,
`brand`, `description` receive only `nullableString()` — a string-or-null type guard. There is
**no allow-list check on `category`** despite the system prompt restricting it to 5 values
(`góra / dół / buty / akcesorium / okrycie wierzchnie`). Any non-empty string the model emits
for category/color is stored and returned. `brand`/`description` are legitimately free-text
(no leak concept), but `category` has a defined value set and is unguarded.

This is the single most important Risk #1 finding: **the guardrail is enforced for condition
and silently absent for category.**

### Existing test coverage — the oracle gap

Classify tests (`tests/Feature/ClassifyEndpointTest.php:31-135`) — all 9 bind
`FakeGarmentClassifier` via `$this->app->instance(GarmentClassifier::class, ...)`
(`:33,53,70,79,89,98,109,117,128`). The fake (`app/Testing/FakeGarmentClassifier.php`) returns
canned arrays or throws the typed exceptions — it **bypasses** `classify()`/`extractFields()`/
`nullableString()` entirely. `test_classify_returns_504_on_timeout` (`:115`) and
`test_classify_returns_502_on_upstream_failure` (`:126`) assert the controller's
exception→status mapping, not the service's response→exception logic.

`GarmentClassifierPromptTest.php:21-46` uses reflection on the real `systemPrompt()` but never
calls `classify()`.

`Http::fake()` appears **once** in the whole suite — `SupabaseJwtVerifierTest.php:46`, for the
JWT verifier, not the classifier. So the pattern exists in-repo but is not applied here.

**Verdict: the real parse/normalize path has zero direct unit coverage.**

## Code References

- `app/Services/GarmentClassifierService.php:16-68` — `classify()` transport + exception split.
- `app/Services/GarmentClassifierService.php:70-83` — `extractFields()`; condition enum check at `:78-80`, category/color/brand/description unguarded at `:75-77,81`.
- `app/Services/GarmentClassifierService.php:85-92` — `nullableString()`; no trim.
- `app/Http/Controllers/Api/ClassifyController.php:43-47` — exception → 504/502.
- `app/Models/Garment.php` — `CONDITIONS` (Polish enum) — the only allow-list in play.
- `app/Testing/FakeGarmentClassifier.php` — the double that bypasses the real path.
- `tests/Feature/ClassifyEndpointTest.php:31-135` — all classify tests, fake-bound.
- `tests/Feature/SupabaseJwtVerifierTest.php:46` — the only `Http::fake()` usage in the suite (reference pattern).
- `phpunit.xml` — in-memory/`testing` DB, `array` cache/queue, `sync` queue.

## Architecture Insights

- The service is the right unit-under-test: `GarmentClassifier` is an interface bound in
  `AppServiceProvider`; tests should instantiate the **real** `GarmentClassifierService`,
  fake the HTTP edge with `Http::fake()`, and assert the returned array — NOT go through the
  endpoint (which the fake short-circuits).
- `Http::fake()` lets you script `choices.0.message.content` as any string, which is exactly
  the seam to drive shapes 3–11 deterministically.
- Oracle discipline: assert each field against the **PRD rule** ("out-of-enum/garbage →
  null; valid → the value"), never against what the parser happens to emit. The category-leak
  finding only surfaces because the oracle is the PRD allow-list, not the code.

## Historical Context (from prior changes)

- `context/archive/2026-06-13-classify-via-laravel/` — extracted `/classify`, introduced the
  typed exceptions + error split (504/502).
- `context/archive/2026-06-17-classify-polish-output/` — localized `CONDITIONS` to Polish,
  added `mb_strtolower` normalization. Its impl-review already flagged (O-level) that
  `category` is a free string with no enum constraint — this research promotes that to the
  actionable Risk #1 leak.
- `context/foundation/lessons.md` — "never plausible-but-wrong; return null" is the governing
  rule and the test oracle.

## Related Research

- None prior for this change. First research artifact in `testing-classification-safety-net`.

## Open Questions

1. **Is the `category` leak in-scope for this test phase, or a code fix?** The test phase can
   *prove the leak exists* (a failing test asserting `category => "sukienka"` should be
   `null`), but making it pass requires a code change (add a category allow-list / `Garment`
   `CATEGORIES` const). Decide in `/10x-plan`: (a) test-only — document the leak as a known
   gap with a skipped/failing test; or (b) test + minimal fix — add a `CATEGORIES` const and
   enum-guard `category` like `condition`. Recommendation: (b) — the leak is the exact Risk #1
   failure and the fix mirrors an existing pattern.
2. **Whitespace condition (9b) — add `trim()`?** A one-line `trim()` before normalization
   recovers a valid value. Cheap; fold into the same plan or note as negative-space.
3. **`color` allow-list?** `color` has no defined value set in the PRD (free-text by design),
   so unlike `category` it is arguably not a leak — confirm in planning whether color stays
   free-text (then only `category` needs guarding).
