# Authorization & Abuse Lockdown (Test Rollout Phase 2) Implementation Plan

## Overview

Implement rollout Phase 2 of `context/foundation/test-plan.md`: integration (Feature) tests locking three risks — #2 IDOR on garment routes, #3 cost abuse on the unauthenticated `/classify` endpoint, #5 raw-client 422-contract bypass / untrusted input. One deliberate production change rides along: enum-guarding `category` server-side (closes the risk-#5 "enum limits reject server-side" gap). Everything else is tests + test-plan documentation.

## Current State Analysis

Grounded by `context/changes/testing-authz-abuse-lockdown/research.md` (commit 5201452):

- **IDOR (#2)**: manual ownership guards `abort(404)` in `app/Http/Controllers/GarmentController.php:47,56,109,178`; index scoped `:22-25`. Non-owner 404 tests already exist for show/update/destroy/tombstone-redelete/photo + index scoping (`GarmentListingCardTest.php:140,151`, `GarmentRemovalTest.php:64,109`, `GarmentPhotoReplacementTest.php:96`, `WardrobeCatalogueTest.php:66`). Contract is 404-not-403 (`context/changes/listing-card-edit/plan-brief.md:22`).
- **Abuse (#3)**: `EnsureAppKey` gate (`app/Http/Middleware/EnsureAppKey.php:23-27`, 403 tested ×3 in `ClassifyEndpointTest.php:68,77,86`). Named limiter `classify` = 10/min per-IP + 100/min global key `'classify-global'` (`app/Providers/AppServiceProvider.php:31-36`), route `routes/api.php:15-16`. **No test anywhere exercises a 429.** Deterministic in-process: `CACHE_STORE=array` (`phpunit.xml:26`), limiter unconditional, no testing-env bypass.
- **Parity (#5)**: `ForceJsonResponse` prepended to whole api group (`bootstrap/app.php:26`). Raw no-Accept 422 tests exist only for `POST /garments` (`GarmentStoreTest.php:200-210`) and `POST /classify` (`ClassifyEndpointTest.php:105-113`). PATCH and photo routes: none — reverting `bootstrap/app.php:26` passes their suites today.
- **Untrusted input (#5b)**: `user_id` not fillable, set server-side (`GarmentController.php:157`). `category` validates as free `string, max:255` on store (`:132`) and update (`:78`) despite `Garment::CATEGORIES` existing (`app/Models/Garment.php:22`); only `condition` has `Rule::in` (`:135`, `:80`).

## Desired End State

- `php artisan test` green with new tests proving: real 429 on both limiter buckets, app-key-before-throttle ordering, out-of-enum `category` → 422 on store + update, raw no-Accept 422 on PATCH + photo.
- `category` enum-guarded server-side via `Rule::in(Garment::CATEGORIES)` on store + update.
- IDOR non-owner-404 matrix verified complete against the current route table; any missing edge filled in the existing test files.
- `context/foundation/test-plan.md` cookbook §6.3/6.4/6.5 filled, §3 Phase 2 status → `complete`, §5 gate row active; `change.md` status advanced.

Verify: `composer test` + `./vendor/bin/pint --test` green; deliberate-break checks in each phase's manual criteria prove the tests bite.

### Key Discoveries:

- 429 is deterministically reachable in-process: array cache store + constant test IP → 11th `postJson` trips the per-IP bucket (`research.md` §#3).
- Global bucket key `'classify-global'` is IP-independent; trip it by spoofing distinct client IPs so the per-IP bucket never fires first (`AppServiceProvider.php:33-35`).
- Archive plan mandates app-key gate ordered before throttle so keyless floods can't burn limiter budget (`context/archive/2026-06-13-classify-via-laravel/plan.md:107-108`) — never tested.
- Guard-cache caveat: two users acting in one test require `$this->app->make('auth')->forgetGuards();` between requests (`GarmentStoreTest.php:127`).
- Laravel test requests accept server variables — `$this->postJson($uri, $data, $headers)` with `REMOTE_ADDR` overridden via `$this->call(...)`/`withServerVariables(['REMOTE_ADDR' => ...])` controls `$request->ip()` (trustProxies `*` also honors `X-Forwarded-For`).

## What We're NOT Doing

- No consolidated `GarmentAuthorizationTest` file — existing scattered per-route tests stay authoritative; this phase gap-fills in place (decision: IDOR scope).
- No test-only limiter re-registration / lowered caps — tests exercise production limiter config verbatim (decision: 429 mechanics).
- No raw no-Accept test for DELETE — body-less route, no validation path, nothing can 422; skip recorded in test-plan §6.5 (decision: 422 routes).
- No changes to the per-IP spoofability posture (`trustProxies('*')`) — accepted trade-off from archive impl-review F1; global bucket is the ceiling.
- No e2e, no mobile-client work, no CI wiring (Phase 4 of the rollout owns gates wiring).
- No HMAC/app-key redesign — static key contract stands (archive decision).

## Implementation Approach

Three test phases ordered by risk value (abuse 429s first — the only genuinely uncovered risk), then a doc-sync phase. The one production change (`category` enum) lands inside its test phase so the guard and its bite-check ship atomically. Each phase ends with a deliberate-break manual check: revert the guarded line, watch the new test fail, restore.

## Phase 1: Abuse lockdown on /classify (risk #3)

### Overview

Prove the throttle actually throttles: real 429 on the per-IP bucket, real 429 on the global bucket, and the app-key-before-throttle ordering invariant.

### Changes Required:

#### 1. New Feature test: classify throttling

**File**: `tests/Feature/ClassifyThrottleTest.php` (new)

**Intent**: Dedicated file for limiter behavior — keeps `ClassifyEndpointTest` (classification contract) separate from abuse mechanics. Follows existing conventions: `RefreshDatabase`, `Storage`/classifier fake via `$this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier)`, app key set in `setUp()` via `config(['services.app.client_key' => 'test-app-key'])`.

**Contract**: Four tests against `POST /classify` with production limiter config (10/min per-IP, 100/min global):

- `test_eleventh_request_from_same_ip_returns_429` — 10 valid-key requests pass (or at least don't 429), 11th → `assertStatus(429)` + `Retry-After` header present.
- `test_global_bucket_returns_429_across_distinct_ips` — spoof distinct client IPs (10 requests each from ~10 IPs = 100 global hits, per-IP bucket never trips), 101st request from a fresh IP → 429. Control client IP via server variables (`REMOTE_ADDR`) so `$request->ip()` varies; helper method for "post as IP".
- `test_wrong_app_key_does_not_consume_limiter_budget` — 11 wrong-key requests → all 403 (never 429); then a valid-key request from the same IP still succeeds (budget unburned) — locks the app.key-before-throttle ordering from the archive plan.
- `test_429_body_is_json` — the 429 response is JSON (`ForceJsonResponse` holds on the throttle path), body has `message`.

Cache note: array store means buckets reset per test method — each test owns its own counter window; no cross-test bleed.

### Success Criteria:

#### Automated Verification:

- New tests pass: `php artisan test --filter=ClassifyThrottleTest`
- Full suite green: `composer test`
- Style: `./vendor/bin/pint --test`

#### Manual Verification:

- Bite check: swap middleware order in `routes/api.php:16` (`['throttle:classify','app.key']`) → wrong-key-budget test fails; restore.
- Bite check: remove `throttle:classify` from the route → both 429 tests fail; restore.

**Implementation Note**: After completing this phase and all automated verification passes, pause for manual confirmation before proceeding.

---

## Phase 2: Validation parity + category enum (risk #5)

### Overview

Close the server-side enum gap on `category` and extend the raw no-Accept 422 net to the two uncovered validating routes.

### Changes Required:

#### 1. Enum-guard `category` on store and update

**File**: `app/Http/Controllers/GarmentController.php`

**Intent**: Add `Rule::in(Garment::CATEGORIES)` to the `category` rules in `store()` (`:132`) and `update()` (`:78`), mirroring the existing `condition` pattern (`:135`, `:80`). Closes the request-side half of the enum asymmetry Phase 1 (rollout) closed on the AI-parse side.

**Contract**: `category` accepted values become exactly `Garment::CATEGORIES` (`['tops','bottoms','footwear','accessories','outerwear']`) or `null`; anything else → 422. Mobile client already receives category from `/classify`, which normalizes to this same enum — no legitimate value regresses.

#### 2. Out-of-enum category tests

**File**: `tests/Feature/GarmentStoreTest.php`, `tests/Feature/GarmentListingCardTest.php`

**Intent**: One test per route: store with `category: 'not-a-category'` → 422 with error on `category`; PATCH same. Place next to the existing `condition` enum tests to mirror the pattern.

**Contract**: `assertStatus(422)` + `assertJsonValidationErrors('category')`; valid enum value still accepted (covered by existing happy-path tests).

#### 3. Raw no-Accept 422 tests for PATCH and photo

**File**: `tests/Feature/GarmentListingCardTest.php`, `tests/Feature/GarmentPhotoReplacementTest.php`

**Intent**: Mirror `test_store_validation_returns_422_without_accept_header` (`GarmentStoreTest.php:200-210`): raw `$this->patch(...)` with an invalid payload (e.g. out-of-enum condition) and raw `$this->post(...)` photo route with no file — no `Accept` header — assert 422, not 302.

**Contract**: `$this->patch()`/`$this->post()` (not the `Json` variants), Bearer token header only; `assertStatus(422)`. These are the regression net for `bootstrap/app.php:26` (`ForceJsonResponse` prepend).

### Success Criteria:

#### Automated Verification:

- Targeted tests pass: `php artisan test --filter=GarmentStoreTest`, `--filter=GarmentListingCardTest`, `--filter=GarmentPhotoReplacementTest`
- Full suite green: `composer test`
- Style: `./vendor/bin/pint --test`

#### Manual Verification:

- Bite check: comment out `$middleware->prependToGroup('api', ForceJsonResponse::class);` (`bootstrap/app.php:26`) → the two new no-Accept tests fail (302); restore.
- Bite check: drop `Rule::in(Garment::CATEGORIES)` from store → new category test fails; restore.

**Implementation Note**: Pause for manual confirmation before proceeding.

---

## Phase 3: IDOR matrix audit (risk #2)

### Overview

Verify the non-owner-404 matrix is complete against the current route table and fill any missing edge in the existing files. Expected small: research shows every route already has a non-owner test.

### Changes Required:

#### 1. Matrix verification + gap-fill

**File**: existing `tests/Feature/Garment*Test.php`, `tests/Feature/WardrobeCatalogueTest.php` (only if a gap is found)

**Intent**: Enumerate current garment routes (`routes/api.php:31-38`) against existing non-owner assertions. Known-covered: index scoping, show, update, destroy, tombstone-redelete, replacePhoto. Audit specifically: (a) non-owner PATCH with a *valid* body still 404s before validation side-effects, (b) non-owner photo replace leaves the owner's media untouched (`assertDatabaseCount`/media unchanged), (c) any route added since research. Add a test only where an edge is genuinely unasserted — no duplication of existing coverage.

**Contract**: Every mutating + reading garment route: authenticated non-owner → `assertNotFound()`; no data/media mutation occurs for the non-owner attempt. 404-not-403 stays the contract.

### Success Criteria:

#### Automated Verification:

- Full suite green: `composer test`
- Style: `./vendor/bin/pint --test`

#### Manual Verification:

- Bite check: remove one ownership guard (e.g. `GarmentController.php:55-57` update guard) → at least one test fails; restore. Confirms guards are load-bearing, not decorative.
- Audit note recorded in the phase commit message or `change.md` Notes: which edges were checked, which (if any) tests added.

**Implementation Note**: Pause for manual confirmation before proceeding.

---

## Phase 4: Test-plan sync

### Overview

Fill the cookbook stubs, flip rollout status, advance the change record. Doc-only.

### Changes Required:

#### 1. Cookbook + status updates

**File**: `context/foundation/test-plan.md`

**Intent**: Fill §6.3 (IDOR pattern: `$owner`/`$other` + `assertNotFound()`, guard-cache `forgetGuards()` caveat, 404-not-403 rationale), §6.4 (throttle pattern: production limits, IP spoofing via server variables, array-cache reset semantics, wrong-key-budget ordering test), §6.5 (finalize: raw request pattern, PATCH/photo now covered, DELETE skip + reason). §3 Phase 2 row → `complete`. §5 gate row "integration on authz + abuse + 422" → active. §6.6 note anything surprising the phases taught. Update "Last updated" line.

**Contract**: Section structure and status vocabulary unchanged; only content fills and status flips.

#### 2. Change record

**File**: `context/changes/testing-authz-abuse-lockdown/change.md`

**Intent**: `status: implementing` → set by first implement run; this phase sets final `status: implemented` (or per `/10x-implement` ritual) and `updated:` date.

**Contract**: Frontmatter only.

### Success Criteria:

#### Automated Verification:

- Full suite still green: `composer test`

#### Manual Verification:

- §6.3–6.5 no longer say "TBD"; §3 Phase 2 status reads `complete`; re-running `/10x-test-plan` would resume at Phase 3.

**Implementation Note**: Final phase — after confirmation, change is ready for `/10x-impl-review` / archive per lifecycle.

---

## Testing Strategy

### Unit Tests:

- None new — Phase 1 (rollout) owns the classifier unit surface. This change is integration-only by design.

### Integration Tests:

- Throttle: per-IP 429, global-bucket 429 via IP spoofing, wrong-key-budget ordering, 429 JSON shape.
- Validation: out-of-enum `category` 422 on store + PATCH; raw no-Accept 422 on PATCH + photo.
- IDOR: audit-driven gap-fill only; existing matrix stays in place.

### Manual Testing Steps:

1. Per-phase deliberate-break checks (listed in each phase) — the core "tests bite" proof.
2. Optional smoke against `php artisan serve`: curl `/classify` 11× with valid key → observe 429 + `Retry-After`.

## Performance Considerations

Global-bucket test issues ~101 in-process requests with a faked classifier — expect a few seconds, not minutes. Acceptable; keep it a single test method so the cost is isolated and filterable.

## Migration Notes

`category` enum guard is a behavioral API change: clients sending non-enum categories start receiving 422. The mobile client sources categories from `/classify` (already normalized to the same enum) and from a picker fed by the same list — no legitimate traffic regresses. If a stored historical row has an out-of-enum category, PATCHing *other* fields is unaffected (`sometimes` rules — `category` not resent is not validated).

## References

- Related research: `context/changes/testing-authz-abuse-lockdown/research.md`
- Test plan: `context/foundation/test-plan.md` (§2 risks #2/#3/#5, §6 cookbook)
- Limiter: `app/Providers/AppServiceProvider.php:31-36`; gate: `app/Http/Middleware/EnsureAppKey.php`
- Ownership guards: `app/Http/Controllers/GarmentController.php:46-48,55-57,108-110,177-179`
- 404-not-403 contract: `context/changes/listing-card-edit/plan-brief.md:22`
- Ordering invariant: `context/archive/2026-06-13-classify-via-laravel/plan.md:107-108`
- No-Accept pattern: `tests/Feature/GarmentStoreTest.php:200-210`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Abuse lockdown on /classify

#### Automated

- [x] 1.1 New tests pass: `php artisan test --filter=ClassifyThrottleTest`
- [x] 1.2 Full suite green: `composer test`
- [x] 1.3 Style: `./vendor/bin/pint --test`

#### Manual

- [x] 1.4 Bite check: middleware order swap fails wrong-key-budget test
- [x] 1.5 Bite check: throttle removal fails both 429 tests

### Phase 2: Validation parity + category enum

#### Automated

- [ ] 2.1 Targeted tests pass: GarmentStoreTest / GarmentListingCardTest / GarmentPhotoReplacementTest filters
- [ ] 2.2 Full suite green: `composer test`
- [ ] 2.3 Style: `./vendor/bin/pint --test`

#### Manual

- [ ] 2.4 Bite check: ForceJsonResponse removal fails new no-Accept tests
- [ ] 2.5 Bite check: category Rule::in removal fails new category test

### Phase 3: IDOR matrix audit

#### Automated

- [ ] 3.1 Full suite green: `composer test`
- [ ] 3.2 Style: `./vendor/bin/pint --test`

#### Manual

- [ ] 3.3 Bite check: removing one ownership guard fails at least one test
- [ ] 3.4 Audit note recorded (edges checked, tests added if any)

### Phase 4: Test-plan sync

#### Automated

- [ ] 4.1 Full suite still green: `composer test`

#### Manual

- [ ] 4.2 §6.3–6.5 filled, §3 Phase 2 `complete`, gate row active
