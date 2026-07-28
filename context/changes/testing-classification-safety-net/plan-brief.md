# AI Classification Safety Net — Plan Brief

> Full plan: `context/changes/testing-classification-safety-net/plan.md`
> Research: `context/changes/testing-classification-safety-net/research.md`

## What & Why

Rollout Phase 1 of the test plan, covering Risk #1: the AI classifier returning a plausible-but-wrong value instead of `null`, which the user trusts and lists as garbage. We close the two live defects research found in the real parse path and lock the guardrail behind a direct unit suite the current tests never exercise.

## Starting Point

`GarmentClassifierService::classify()` → `extractFields()` → `nullableString()` handles transport/type failures well, but enum-guards only `condition`. `category` passes any string verbatim (the leak), and `" dobry "` drops to `null` for lack of a `trim()`. All 9 classify tests bind `FakeGarmentClassifier`, so the real normalize logic has zero direct coverage.

## Desired End State

An out-of-enum plausible `category` ("sukienka") resolves to `null`; a padded valid `condition` recovers to its value; and a new unit file drives the real service against `Http::fake()` across every malformed shape, asserting each field against the PRD null-not-wrong rule. Suite green, test-plan Phase 1 marked complete.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Category leak | Test + minimal fix | Add `Garment::CATEGORIES` + enum-guard `category` like `condition` — closes the actual Risk #1 leak, not just documents it | Plan |
| Whitespace condition | Fold in `trim()` + test | Cheap recovery of a valid value; fails-safe today but loses real data | Plan |
| Color allow-list | Keep free-text | PRD gives color no fixed value set — no "wrong" value to guard against | Plan |
| Fix-vs-test order | Fix first, then tests | Oracle tests land green, not red/skipped — shipping a known leak violates lessons.md | Plan |
| Unit-under-test | Real service + `Http::fake()` | The fake short-circuits the parse path; only the real service proves the guardrail | Research |

## Scope

**In scope:** `Garment::CATEGORIES` const, `category` enum-guard, `condition` `trim()`, new `GarmentClassifierSafetyNetTest`, test-plan §3/§6.1/§5 updates.

**Out of scope:** color/brand/description guards, controller/HTTP mapping, existing endpoint tests, integration/authz/abuse/422 (Phase 2), hooks/CI gate (Phase 4).

## Architecture / Approach

Two small `app/` edits mirroring the existing `condition` pattern, then a `tests/Feature/` file that instantiates the real `GarmentClassifierService`, fakes the OpenRouter endpoint, scripts `choices.0.message.content` per malformed shape, and asserts the returned array against the PRD rule.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Harden classifier | `CATEGORIES` const + `category` guard + `condition` `trim()` | Category values must match the system prompt verbatim |
| 2. Safety-net unit tests | Real-service `Http::fake()` suite over shapes 6–11 + fixes | Oracle drift — asserting parser output instead of the PRD rule |
| 3. Test-plan wiring | §3 status → complete, §6.1 cookbook, §5 gate | Table markdown breakage |

**Prerequisites:** research.md (done); existing test infra (`Http::fake()`, in-memory SQLite) already present.
**Estimated effort:** ~1 session across 3 phases.

## Open Risks & Assumptions

- The 5 Polish category values are taken from the system prompt as the authoritative set — confirm they match if the prompt changes.
- New tests must drive the real service; a stray `FakeGarmentClassifier` binding would silently invalidate the whole suite (Phase 2 manual check guards this).

## Success Criteria (Summary)

- `category => "sukienka"` → `null`; `category => "buty"` → `"buty"`.
- `condition => " dobry "` → `"dobry"`.
- New suite green and exercising the real parse path; full `composer test` green; Pint clean.
