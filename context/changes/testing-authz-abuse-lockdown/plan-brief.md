# Authorization & Abuse Lockdown — Plan Brief

> Full plan: `context/changes/testing-authz-abuse-lockdown/plan.md`
> Research: `context/changes/testing-authz-abuse-lockdown/research.md`

## What & Why

Rollout Phase 2 of the project test plan: integration tests proving three risks are locked — IDOR on garment routes (#2), token-cost abuse on the unauthenticated `/classify` endpoint (#3), and raw-client bypass of the 422 validation contract (#5). The abuse risk is the headline: the throttle config exists but **no test anywhere exercises a real 429** — exactly the anti-pattern the test plan forbids.

## Starting Point

Guards all exist: manual `abort(404)` ownership checks per route, `EnsureAppKey` 403 gate, named limiter (10/min per-IP + 100/min global), `ForceJsonResponse` on the whole api group. Coverage is uneven: IDOR is well-tested, app-key 403 is tested, but 429 has zero coverage and only 2 of 4 validating routes have raw no-Accept 422 tests. One real code gap: `category` is free-text server-side despite `Garment::CATEGORIES` existing.

## Desired End State

Suite proves abuse protection by exercising it: 11th same-IP request → 429, global bucket → 429 across spoofed IPs, wrong-key floods never burn limiter budget. `category` rejects out-of-enum values with 422 on store and PATCH. Every validating mutating route has a raw no-Accept 422 regression test. Test-plan cookbook §6.3–6.5 filled; Phase 2 marked complete.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| `category` enum gap | Add `Rule::in(Garment::CATEGORIES)` to store + update | Closes risk #5's "enum limits reject server-side"; mirrors Phase 1's fix of the same asymmetry on the AI-parse side | Plan |
| 429 test mechanics | Production limits, spoofed client IPs | Exercises the real limiter config verbatim — no test-only re-registration (that's the "config present ≠ protected" anti-pattern in disguise) | Plan |
| IDOR scope | Gap-fill in existing files | Matrix already covered across 4 files; consolidation adds churn, zero signal | Plan |
| Middleware-order test | Include (wrong key ×11 → all 403, budget unburned) | Locks the archive-plan invariant that keyless floods can't DoS the global bucket | Plan |
| DELETE no-Accept test | Skip, record reason | Body-less route, no validation path — nothing can 422 | Plan |
| Test-plan sync | Final doc phase in this change | Orchestrator contract: re-running `/10x-test-plan` must resume at Phase 3 | Plan |
| 404-not-403 for non-owners | Keep (assert everywhere) | Established contract — avoids leaking resource existence | Research |
| Per-IP spoofability | Accept; global bucket is the ceiling | `trustProxies('*')` is required on Lambda; archive impl-review already settled this | Research |

## Scope

**In scope:** new `ClassifyThrottleTest` (2× 429 + ordering + JSON shape); `category` enum guard + tests; raw no-Accept tests for PATCH + photo; IDOR matrix audit with gap-fill; test-plan cookbook/status updates.

**Out of scope:** consolidated authz test file; limiter/app-key redesign; `trustProxies` changes; DELETE no-Accept test; CI gate wiring (rollout Phase 4); e2e; mobile client.

## Architecture / Approach

Pure Feature-test additions following existing conventions (`token()` helper, `$owner`/`$other` pattern, `Storage::fake`, `FakeGarmentClassifier` binding, array cache making limiter buckets deterministic per test). One surgical production edit (two validation lines). Each phase ends with a deliberate-break check proving the new tests bite.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Abuse lockdown `/classify` | Real 429s (per-IP + global) + ordering invariant | Global test ≈101 requests — slow-ish; IP spoofing must actually vary `$request->ip()` |
| 2. Validation parity + enum | `category` enum guard + no-Accept net on PATCH/photo | Behavioral API change — non-enum categories start 422ing |
| 3. IDOR matrix audit | Verified complete non-owner-404 matrix | Expected near-empty; risk is auditing too shallowly |
| 4. Test-plan sync | Cookbook §6.3–6.5 + status flips | None (doc-only) |

**Prerequisites:** none — all on `staging`, suite currently green.
**Estimated effort:** ~2 sessions across 4 phases; phases 3–4 are small.

## Open Risks & Assumptions

- Assumes `withServerVariables(['REMOTE_ADDR' => ...])` (or `$this->call`) varies `$request->ip()` under `trustProxies('*')` — verify in Phase 1 first test; fall back to `X-Forwarded-For` header spoofing if not.
- `category` guard assumes mobile client only sends enum values (sourced from `/classify` normalization + picker) — historical out-of-enum rows unaffected on PATCH when field omitted.
- Global-bucket test cost (~101 requests) accepted; isolated in one filterable method.

## Success Criteria (Summary)

- `composer test` + `pint --test` green with new tests; deliberate-break checks fail the right tests when guards are reverted.
- Abuse gates proven by real 403/429 behavior, not config presence.
- Test plan reflects reality: cookbook filled, Phase 2 `complete`, gate row active.
