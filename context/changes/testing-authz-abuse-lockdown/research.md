---
date: 2026-07-16T07:50:29+02:00
researcher: Claude (Fable 5)
git_commit: 52014528d51b3ce007510ccc7d501cb7153aacf1
branch: staging
repository: api
topic: "Authorization & abuse lockdown — ground risks #2 (IDOR), #3 (classify cost abuse), #5 (422 parity / untrusted input)"
tags: [research, codebase, authz, idor, rate-limit, app-key, validation, garments, classify]
status: complete
last_updated: 2026-07-16
last_updated_by: Claude (Fable 5)
---

# Research: Authorization & abuse lockdown (test-plan Phase 2)

**Date**: 2026-07-16T07:50:29+02:00
**Researcher**: Claude (Fable 5)
**Git Commit**: 52014528d51b3ce007510ccc7d501cb7153aacf1
**Branch**: staging
**Repository**: api

## Research Question

Ground rollout Phase 2 of `context/foundation/test-plan.md` ("Authorization & abuse lockdown", risks #2/#3/#5):

- **#2 IDOR**: how ownership is enforced per garment route; whether the index query is scoped by user; the 404-vs-403 choice.
- **#3 cost abuse**: the app-key gate; the named rate limiter's per-IP + global buckets; how the client IP is derived behind the proxy.
- **#5 validation parity**: which middleware forces JSON on the api group; which routes are covered vs uncovered by a no-Accept test; server-side enum/size enforcement and client-value trust.

## Summary

1. **IDOR (#2) — enforcement exists and is already broadly tested.** No policies, no gates, no scoped route bindings: every garment route enforces ownership manually via `$garment->user_id !== $request->user()->id` → `abort(404)` (`app/Http/Controllers/GarmentController.php:47,56,109,178`). Index is scoped by `where('user_id', ...)` (`GarmentController.php:22`). Contract is **404, never 403** (decided in `context/changes/listing-card-edit/plan-brief.md:22` — avoids leaking existence; PRD is silent on the code). Non-owner 404 tests already exist for show, update, delete, re-delete-tombstone, and photo replacement; index scoping is tested. The IDOR surface is close to covered — the plan's job is gap-filling and bite-checks, not greenfield tests.
2. **Cost abuse (#3) — gates exist; 403 tested, 429 never exercised.** `X-App-Key` gate: `EnsureAppKey` middleware, constant-time `hash_equals`, blank-server-key guard, 403 JSON on failure (`app/Http/Middleware/EnsureAppKey.php:23-27`). Rate limiter `classify`: 10/min per IP + 100/min global fixed key `'classify-global'` (`app/Providers/AppServiceProvider.php:31-36`), applied via `throttle:classify` (`routes/api.php:16`). **Zero tests exercise a 429** — this is the phase's biggest genuine gap, and exactly the anti-pattern the test plan names. A real 429 is deterministically reachable in-process: `CACHE_STORE=array` in `phpunit.xml`, limiter registered unconditionally, no testing-env bypass, test client IP constant (`127.0.0.1`) → 11th request trips the per-IP bucket.
3. **422 parity (#5) — middleware covers everything; tests cover only 2 of 5 routes.** `ForceJsonResponse` is prepended to the whole `api` group (`bootstrap/app.php:26`) and rewrites `Accept: application/json` (`app/Http/Middleware/ForceJsonResponse.php:20`), so at runtime no api route can 302. But only `POST /garments` and `POST /classify` have raw no-Accept regression tests; `PATCH /garments/{id}`, `POST /garments/{id}/photo` (and DELETE, body-less) have none — reverting the middleware registration would pass the suite for those routes.
4. **Untrusted input (#5b) — `user_id` is safe; `category` is NOT enum-guarded.** `user_id` is not in `$fillable` and is always set server-side from the authenticated user (`GarmentController.php:157`). But `category` validates as free `string, max:255` on both store (`GarmentController.php:132`) and update (`:78`) despite `Garment::CATEGORIES` existing — only `condition` gets `Rule::in(...)`. This is the same asymmetry risk #1 confirmed on the AI-parse side, now on the request-validation side.

## Detailed Findings

### Routes and middleware stack

| Method | URI | Action | Middleware |
|---|---|---|---|
| POST | `/classify` | `ClassifyController@classify` | `app.key`, `throttle:classify` (`routes/api.php:15-16`) |
| POST | `/auth/supabase/exchange` | `SupabaseController@exchange` | `throttle:5,1` (`routes/api.php:19`) |
| POST | `/auth/refresh`, `/auth/logout` | `AuthController` | `auth:sanctum` (`routes/api.php:21-23`) |
| GET | `/user`, `/ping` | — | `auth:sanctum` + `CheckForAnyAbility:access` (`routes/api.php:27-29`) |
| POST/GET | `/garments` | `store` / `index` | garment group (`routes/api.php:27,31-32`) |
| GET/PATCH | `/garments/{garment}` | `show` / `update` | garment group (`routes/api.php:33-34`) |
| POST | `/garments/{garment}/photo` | `replacePhoto` | garment group (`routes/api.php:35`) |
| DELETE | `/garments/{garmentId}` | `destroy` | garment group + `whereNumber('garmentId')` (`routes/api.php:38`) |

Garment group = `auth:sanctum` + `Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class.':access'` (`routes/api.php:9,27`). Tokens minted with `['access']` ability (`app/Http/Controllers/Api/Concerns/IssuesTokenPairs.php:17-19`). `ForceJsonResponse` prepended to the entire api group (`bootstrap/app.php:26`).

### #2 IDOR — ownership enforcement per route

No Policy / Gate / scoped binding anywhere in `app/` (grep-confirmed). Implicit binding resolves the row regardless of owner; the controller then hard-codes the guard:

- **show**: `if ($garment->user_id !== $request->user()->id) { abort(404); }` — `GarmentController.php:46-48`
- **update**: same guard — `GarmentController.php:55-57`
- **replacePhoto**: same guard — `GarmentController.php:177-179`
- **destroy**: no binding; `Garment::find($garmentId)` (`:93`) then same guard (`:108-110`); tombstone lookup scoped `where('user_id', ...)->where('garment_id', ...)` (`:99-101`); unknown/foreign id → `abort_unless(..., 404)` (`:103`)
- **store**: `$garment->user_id = $request->user()->id;` (`:157`); `client_ref` idempotency lookups scoped by `user_id` (`:146-147, 162-164`)
- **index**: `Garment::where('user_id', $request->user()->id)->with('media')->orderByDesc('created_at')->paginate(20)` — `GarmentController.php:22-25`

**404-not-403 contract source**: `context/changes/listing-card-edit/plan-brief.md:22` ("Ownership violation response | 404 (not 403) | Avoids leaking resource existence"). PRD Access Control (`context/foundation/prd.md:123-130`) states only the flat single-user model — no status code; the contract lives in the change layer.

**Existing non-owner coverage** (all `assertNotFound()`, `$owner`/`$other` two-user pattern):

- index scoping: `tests/Feature/WardrobeCatalogueTest.php:66` (`test_index_does_not_return_other_users_garments`)
- show: `tests/Feature/GarmentListingCardTest.php:140`; unknown id: `:132`
- update: `tests/Feature/GarmentListingCardTest.php:151`
- replacePhoto: `tests/Feature/GarmentPhotoReplacementTest.php:96`
- destroy: `tests/Feature/GarmentRemovalTest.php:64`; unknown: `:88`; foreign tombstone re-delete: `:109`

No test asserts 403 for non-owners anywhere — contract is consistent.

### #3 Cost abuse — app-key gate + rate limiter

**App-key gate** (`app/Http/Middleware/EnsureAppKey.php`):
- Alias `app.key` registered `bootstrap/app.php:18`; applied `routes/api.php:16`.
- Config `services.app.client_key` ← env `APP_CLIENT_KEY` (`config/services.php:49-52`, `.env.example:81`).
- Constant-time `hash_equals` + guard rejecting blank/non-string server key so empty==empty cannot bypass (`EnsureAppKey.php:23-27`). Failure → `response()->json(['message' => 'Forbidden.'], 403)` (`:26`).

**Rate limiter** (`app/Providers/AppServiceProvider.php:31-36`):

```php
RateLimiter::for('classify', fn (Request $request) => [
    Limit::perMinute(10)->by((string) $request->ip()),   // per-IP
    Limit::perMinute(100)->by('classify-global'),        // global ceiling
]);
```

**IP derivation**: `$middleware->trustProxies(at: '*')` (`bootstrap/app.php:29`) — required for API Gateway/Lambda (Bref) so `$request->ip()` reads `X-Forwarded-For`. Known trade-off (comment `AppServiceProvider.php:28-30`, and `context/archive/2026-06-13-classify-via-laravel/reviews/impl-review.md:25-42` finding F1): the per-IP key is spoofable via forged `X-Forwarded-For`, so the **global 100/min bucket is the real ceiling**. CIDR pinning was evaluated and rejected as non-viable on API Gateway.

**429 shape**: framework default from `ThrottleRequests` — no custom handler (`bootstrap/app.php:31-33` empty `withExceptions`): HTTP 429, `Retry-After` + `X-RateLimit-*` headers, JSON `{"message":"Too Many Attempts."}` (JSON guaranteed by `ForceJsonResponse`).

**Test coverage**: 403 paths covered — missing key (`tests/Feature/ClassifyEndpointTest.php:68`), wrong key (`:77`), blank server key (`:86`); per-test key set via `config(['services.app.client_key' => 'test-app-key'])` (`:23`). **429: zero hits** for `429|throttle|Retry-After|Too Many|assertTooMany` across `tests/`.

**Deterministic 429 in a Feature test** (observed mechanics, no bypass to work around):
- Limiter registered unconditionally in `boot()`; throttle middleware unconditionally on the route; no `App::environment()` guard anywhere.
- `phpunit.xml` sets `CACHE_STORE=array` — RateLimiter counts accumulate across sequential requests within one test method and reset between tests.
- Test-client IP is constant (`127.0.0.1`) → 11 requests trip the per-IP bucket. The global bucket is keyed by the fixed string `'classify-global'`, independent of IP — testable by varying the client IP (or lowering the limit via config) so per-IP never trips first.

### #5 Validation parity — 422 contract + server-side limits

**ForceJsonResponse**: `app/Http/Middleware/ForceJsonResponse.php:20` sets `Accept: application/json` on every request; prepended to the whole api group `bootstrap/app.php:26`. Runtime coverage: all api routes. Origin: lessons.md rule "422 only when expectsJson()" + `context/archive/2026-06-13-classify-via-laravel/reviews/impl-review.md:60-61` (F2).

**Validation per mutating route** (all inline `$request->validate()`; `app/Http/Requests` does not exist):

- **POST /garments** (`GarmentController.php:130-138`): `client_ref` nullable string max:255; `category` nullable string max:255 (**free text — no `Rule::in`**); `brand`/`color` nullable string max:255; `condition` nullable string `Rule::in(Garment::CONDITIONS)`; `description` nullable string max:5000; `photo` required image max:10240 (10 MB).
- **PATCH /garments/{garment}** (`GarmentController.php:76-82`): same fields with `sometimes` prefix; `category` again free text; `condition` enum-guarded. Plus `If-Unmodified-Since`: unparseable → 400 (`:66`), stale → 409 (`:69-74`).
- **POST /garments/{garment}/photo** (`GarmentController.php:181-183`): `photo` required image max:10240.
- **DELETE /garments/{garmentId}**: no body validation; route-level `whereNumber` (`routes/api.php:38`).
- **POST /classify** (`app/Http/Controllers/ClassifyController.php:29-31`): `photo` required image max:10240; runtime `abort_if($path === false, 422, ...)` (`:38`).

**Enum constants** (`app/Models/Garment.php`): `CONDITIONS = ['new', 'like new', 'good', 'fair', 'worn']` (`:18`); `CATEGORIES = ['tops', 'bottoms', 'footwear', 'accessories', 'outerwear']` (`:22`). Server-side: only `condition` enforced; **`category` accepts any string** on store and update despite the constant existing.

**Client-value trust**: `$fillable = [client_ref, category, brand, color, condition, description]` (`Garment.php:24-31`) — `user_id` not fillable, always set server-side (`GarmentController.php:157`); ownership always derived from `$request->user()->id`, never request body (index `:22`, show `:45`, update `:54`, destroy `:97,111`, replacePhoto `:174`).

**No-Accept test coverage** (the only two non-Json requests in the whole suite):

| Route | no-Accept 422 test |
|---|---|
| POST /garments | YES — `tests/Feature/GarmentStoreTest.php:200-210` |
| POST /classify | YES — `tests/Feature/ClassifyEndpointTest.php:105-113` |
| PATCH /garments/{id} | **NO** — all `patchJson` (`GarmentListingCardTest.php:46-158`) |
| POST /garments/{id}/photo | **NO** — all `postJson` (`GarmentPhotoReplacementTest.php:48-103`) |
| DELETE /garments/{id} | NO — body-less, lower relevance |

Bite check: reverting `bootstrap/app.php:26` (ForceJsonResponse registration) would still pass all PATCH/photo tests today.

## Test conventions to follow (from existing suite)

- Feature test: `extends Tests\TestCase` (`tests/TestCase.php:7`), `use RefreshDatabase` per class.
- Auth: per-class `token()` helper — `$user->createToken('access', ['access'], now()->addMinutes(5))->plainTextToken` (`tests/Feature/GarmentStoreTest.php:22-25`), sent as `['Authorization' => 'Bearer '.$this->token($user)]`.
- IDOR pattern: `$owner` / `$other` users (`GarmentRemovalTest.php:66-72`). Guard-cache caveat when two users act in one test: `$this->app->make('auth')->forgetGuards();` between requests (`GarmentStoreTest.php:127`).
- Uploads: `Storage::fake('local')` in `setUp()`; `UploadedFile::fake()->image('garment.jpg')`; oversize via `->size(20480)`; media attach via `addMedia(...)->toMediaCollection('photos')`.
- Classifier fake: `$this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier)` (`ClassifyEndpointTest.php:33`) — fine here; Phase 2 tests gates/validation, not the parse path.
- `phpunit.xml`: `APP_ENV=testing`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`; no throttle/app-key env overrides (app key set per-test in code).

## Gap Analysis (what Phase 2 must actually add)

| Risk | Already covered | Genuine gap |
|---|---|---|
| #2 IDOR | non-owner 404 on show/update/destroy/photo/tombstone; index scoping | thin: no non-owner test naming the full route matrix as contract; consider bite-check that guards actually fire (e.g. removing one guard fails a test — they do, per-route tests exist) |
| #3 abuse | app-key 403 ×3 (missing/wrong/blank-server) | **429 never exercised**: per-IP bucket (11th request), global `classify-global` bucket, `Retry-After` presence; app-key gate ordering before throttle (archive plan `:107-108`) untested |
| #5 parity | no-Accept 422 on store + classify | **PATCH + photo routes lack no-Accept tests**; `category` not enum-guarded server-side (design question: enforce `Rule::in(Garment::CATEGORIES)` or document free-text as intended); oversize/enum negative tests exist for store/photo but PATCH `condition` out-of-enum test exists (`GarmentListingCardTest.php` uses patchJson only) — no raw variant |

## Architecture Insights

- Ownership is a **manual controller-guard pattern**, deliberately chosen over policies/scoped bindings (`context/changes/listing-card-edit/plan.md:56`). Any new garment route must copy the guard — tests are the only thing preventing a forgotten guard.
- The **global limiter bucket is the real abuse ceiling**; per-IP is best-effort due to `trustProxies('*')` on Lambda (spoofable `X-Forwarded-For`). Tests should treat the global bucket as first-class, not an afterthought.
- `ForceJsonResponse` is a single point of failure for the whole 422 contract: one line in `bootstrap/app.php:26`. Per-route no-Accept tests are the regression net for that line.
- `condition` vs `category` enum asymmetry now confirmed on **both** boundaries: AI-parse side (risk #1, Phase 1) and request-validation side (this research). Same root shape.

## Code References

- `routes/api.php:15-16` — `/classify` with `app.key` + `throttle:classify`
- `routes/api.php:27-38` — Sanctum garment group + ability middleware
- `app/Http/Middleware/EnsureAppKey.php:23-27` — hash_equals gate, blank-key guard, 403
- `app/Providers/AppServiceProvider.php:31-36` — named `classify` limiter (10/IP + 100 global)
- `bootstrap/app.php:18,26,29` — `app.key` alias, ForceJsonResponse prepend, trustProxies('*')
- `app/Http/Middleware/ForceJsonResponse.php:20` — Accept-header rewrite
- `app/Http/Controllers/GarmentController.php:22-25` — index scoping
- `app/Http/Controllers/GarmentController.php:46-48,55-57,108-110,177-179` — per-route abort(404) ownership guards
- `app/Http/Controllers/GarmentController.php:130-138,76-82,181-183` — validation rules (category free-text, condition enum)
- `app/Http/Controllers/GarmentController.php:157` — server-side user_id assignment
- `app/Models/Garment.php:18,22,24-31` — CONDITIONS/CATEGORIES constants, $fillable
- `app/Http/Controllers/ClassifyController.php:29-31,38` — classify validation
- `tests/Feature/ClassifyEndpointTest.php:68,77,86,105` — app-key 403s, no-Accept 422
- `tests/Feature/GarmentStoreTest.php:200-210` — store no-Accept 422 (reference pattern)
- `phpunit.xml:26` — `CACHE_STORE=array` (makes 429 deterministic in-process)

## Historical Context (from prior changes)

- `context/archive/2026-06-13-classify-via-laravel/` — origin of `/classify` protection: static `X-App-Key` (extractable from binary; accepted, env-rotatable — `plan-brief.md:31,72-73`; HMAC rejected `plan.md:83`); app-key middleware ordered before throttle (`plan.md:107-108`); impl-review F1 → named limiter with global bucket after X-Forwarded-For spoof finding (`reviews/impl-review.md:25-42`); F2 → no-Accept regression tests mandated (`reviews/impl-review.md:60-61`).
- `context/changes/listing-card-edit/` — origin of 404-not-403 ownership contract (`plan-brief.md:15,22,57`); impl-review caught missing update-IDOR test (`reviews/impl-review.md:39-40`).
- Commit `f97ffdf` "close mobile sync-contract gaps" (2026-07-06) — added photo-replacement route, `client_ref` idempotency, tombstone re-delete, `If-Unmodified-Since` — the new surface this phase's IDOR/parity matrix must include.
- `context/changes/auth-refresh/reviews/impl-review.md:23-30` — precedent: `throttle:5,1` added to auth routes after review.
- `context/foundation/lessons.md` — "422 tylko gdy expectsJson()" rule (drives risk #5); "nigdy plausible-but-wrong" (frames the category free-text finding).

## Related Research

- `context/changes/testing-classification-safety-net/plan.md` — Phase 1 (risk #1); enum-guard asymmetry first confirmed there on the AI-parse side.
- `context/foundation/test-plan.md` §6.2–6.5 — cookbook stubs this phase will fill (6.3 IDOR, 6.4 abuse/429, 6.5 no-Accept).

## Open Questions

1. **`category` enum enforcement** — should store/update add `Rule::in(Garment::CATEGORIES)`, or is free-text category intended for user-entered values? Test plan risk #5 says "enum and size limits reject server-side"; today only `condition` does. Plan must decide: change production validation (scope beyond pure test additions) or record free-text as the contract.
2. **Global-bucket 429 test mechanics** — trip the 100/min `classify-global` bucket without 100 requests: vary client IP per request so per-IP never trips, or lower the limit via config/limiter re-registration in the test. Plan should pick one and note it in cookbook §6.4.
3. **DELETE no-Accept test** — body-less route, no validation to trigger 422; is a no-Accept variant worth anything there (contract says 404/204 paths, not 422)? Likely skip — record the reason.
4. **Middleware-order test** — archive plan mandates app-key runs before throttle (wrong key must not consume limiter budget). Worth one test: 11 wrong-key requests → still 403 (not 429), then valid key still passes.
